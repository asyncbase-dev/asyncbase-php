<?php

declare(strict_types=1);

namespace AsyncBase\Laravel\Queue;

use AsyncBase\AsyncBaseError;
use AsyncBase\Laravel\Jobs\AsyncBaseJob;
use AsyncBase\Queue as AsyncBaseClient;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Contracts\Queue\Queue as QueueContract;
use Illuminate\Queue\Queue as BaseQueue;

/**
 * Laravel queue driver for AsyncBase.
 *
 * Bridges Illuminate\Contracts\Queue\Queue ↔ the AsyncBase\Queue client:
 *
 *   dispatch(new Job) → push() → createPayload() → client->send()
 *   queue:work loop   → pop()  → client->pull()   → AsyncBaseJob wrapper
 *   job succeeds      → delete() → client->ack()
 *   job throws        → release($delay) → client->nack() (auto backoff in API)
 *
 * Visibility: each pop() uses $visibilitySeconds. Long handlers should either
 * raise that value in config, set Laravel's retry_after to match, or heartbeat
 * via AsyncBase directly (not wired into queue:work loop; use a custom
 * middleware if you need sub-handler heartbeats).
 */
class AsyncBaseQueue extends BaseQueue implements QueueContract
{
    public function __construct(
        public readonly AsyncBaseClient $client,
        public readonly string $defaultQueue,
        public readonly string $group,
        public readonly ?string $consumer,
        public readonly int $visibilitySeconds,
    ) {
    }

    // ─── push ─────────────────────────────────────────────────

    /**
     * @param  object|string  $job
     * @param  mixed  $data
     */
    public function push($job, $data = '', $queue = null): mixed
    {
        return $this->pushRaw($this->createPayload($job, $this->getQueue($queue), $data), $queue);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function pushRaw($payload, $queue = null, array $options = []): mixed
    {
        $queueName = $this->getQueue($queue);
        try {
            $res = $this->client->send(
                $queueName,
                ['laravel_payload' => $payload],  // wrap so get_docs / inspection stays clean
                idempotencyKey: $options['idempotency_key'] ?? null,
                delay: $options['delay'] ?? null,
            );
        } catch (AsyncBaseError $e) {
            throw new \RuntimeException(
                "AsyncBase send failed: {$e->errorCode} {$e->getMessage()}",
                previous: $e,
            );
        }
        return $res->id;
    }

    /**
     * @param  int|\DateInterval|\DateTimeInterface  $delay
     * @param  object|string  $job
     * @param  mixed  $data
     */
    public function later($delay, $job, $data = '', $queue = null): mixed
    {
        $seconds = $this->secondsUntil($delay);
        return $this->pushRaw(
            $this->createPayload($job, $this->getQueue($queue), $data),
            $queue,
            ['delay' => "{$seconds}s"],
        );
    }

    // ─── pop ──────────────────────────────────────────────────

    public function pop($queue = null): ?Job
    {
        $queueName = $this->getQueue($queue);
        try {
            $msgs = $this->client->pull(
                $queueName,
                group: $this->group,
                consumer: $this->consumer,
                limit: 1,
                waitSeconds: 0,   // queue:work polls; don't long-poll here
                visibilitySeconds: $this->visibilitySeconds,
            );
        } catch (AsyncBaseError $e) {
            // Don't crash the worker loop on transient errors.
            // queue:work will sleep + retry.
            error_log("[asyncbase-queue] pull failed: {$e->errorCode} {$e->getMessage()}");
            return null;
        }

        if (empty($msgs)) {
            return null;
        }

        /** @var \AsyncBase\ConsumedMessage $msg */
        $msg = $msgs[0];
        $body = isset($msg->payload['laravel_payload'])
            ? (string) $msg->payload['laravel_payload']
            : (string) json_encode($msg->payload);

        return new AsyncBaseJob(
            container: $this->container,
            queueClient: $this->client,
            consumedMessage: $msg,
            group: $this->group,
            connectionName: $this->connectionName,
            queue: $queueName,
            rawBody: $body,
        );
    }

    // ─── size / stats ─────────────────────────────────────────
    // Laravel 12's Queue contract adds per-state size probes for Horizon.
    // AsyncBase doesn't expose those breakdowns yet (no /v1/queues/:name/stats),
    // so all return 0. Override in a subclass once the stats endpoint lands.

    public function size($queue = null): int
    {
        return 0;
    }

    public function pendingSize($queue = null): int
    {
        return 0;
    }

    public function delayedSize($queue = null): int
    {
        return 0;
    }

    public function reservedSize($queue = null): int
    {
        return 0;
    }

    /**
     * `php artisan queue:clear` — dangerous on a SaaS queue. Return 0 so it
     * no-ops; users should delete the stream via the dashboard instead.
     */
    public function clear($queue): int
    {
        return 0;
    }

    /**
     * Laravel's queue:monitor reads this to alert on stale jobs. AsyncBase
     * doesn't expose an "oldest pending message" probe — returning null
     * tells the monitor to skip the stale-threshold check.
     */
    public function creationTimeOfOldestPendingJob($queue = null): ?int
    {
        return null;
    }

    // ─── helpers ──────────────────────────────────────────────

    private function getQueue(mixed $queue): string
    {
        return (string) ($queue ?: $this->defaultQueue);
    }
}
