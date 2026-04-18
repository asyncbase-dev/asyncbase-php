<?php

declare(strict_types=1);

namespace AsyncBase\Laravel\Jobs;

use AsyncBase\AsyncBaseError;
use AsyncBase\ConsumedMessage;
use AsyncBase\Queue as AsyncBaseClient;
use Illuminate\Container\Container;
use Illuminate\Contracts\Queue\Job as JobContract;
use Illuminate\Queue\Jobs\Job;

/**
 * Bridges an AsyncBase ConsumedMessage to Laravel's Job contract so the
 * `queue:work` loop can handle it like any other driver's job.
 *
 * Lifecycle:
 *   1. AsyncBaseQueue::pop() pulls a message, wraps it here.
 *   2. Worker calls $job->fire() — base class resolves the handler class
 *      from the payload's "job" field and invokes handle().
 *   3a. Handler returns normally → Worker calls $job->delete() → ack.
 *   3b. Handler throws + retries remain → Worker calls $job->release($delay)
 *       → nack (AsyncBase applies exp-backoff per server config).
 *   3c. Max attempts exceeded → Worker calls $job->fail($exception) →
 *       we nack with the final attempt count so AsyncBase moves to DLQ.
 */
class AsyncBaseJob extends Job implements JobContract
{
    public function __construct(
        Container $container,
        public readonly AsyncBaseClient $queueClient,
        public readonly ConsumedMessage $consumedMessage,
        public readonly string $group,
        string $connectionName,
        string $queue,
        private readonly string $rawBody,
    ) {
        $this->container = $container;
        $this->connectionName = $connectionName;
        $this->queue = $queue;
    }

    public function getJobId(): string
    {
        return $this->consumedMessage->id;
    }

    public function getRawBody(): string
    {
        return $this->rawBody;
    }

    /**
     * Laravel increments after each failure; our server-side `attempt` starts
     * at 1 for a fresh message and increments on nack. Return what
     * AsyncBase gives us directly.
     */
    public function attempts(): int
    {
        return $this->consumedMessage->attempt;
    }

    public function delete(): void
    {
        parent::delete();
        try {
            $this->consumedMessage->ack();
        } catch (AsyncBaseError $e) {
            error_log("[asyncbase-job] ack failed for {$this->getJobId()}: {$e->errorCode}");
        }
    }

    /**
     * Laravel passes $delay for driver-controlled backoff. AsyncBase computes
     * its own exponential backoff server-side per attempt, so we ignore $delay
     * and let the server decide. If you set a custom Laravel backoff() on the
     * job, use `retries` when sending instead.
     */
    public function release($delay = 0): void
    {
        parent::release($delay);
        try {
            $this->consumedMessage->nack($this->consumedMessage->attempt);
        } catch (AsyncBaseError $e) {
            error_log("[asyncbase-job] nack failed for {$this->getJobId()}: {$e->errorCode}");
        }
    }

    /**
     * Called by the worker when max attempts exceeded or job is permanently
     * failed. Nack one last time — the API knows when retries are exhausted
     * and moves the message to the DLQ automatically.
     */
    public function fail($e = null): void
    {
        parent::fail($e);
        try {
            $this->consumedMessage->nack($this->consumedMessage->attempt);
        } catch (AsyncBaseError $err) {
            error_log("[asyncbase-job] fail->nack for {$this->getJobId()}: {$err->errorCode}");
        }
    }
}
