<?php

declare(strict_types=1);

namespace AsyncBase;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\RequestException;

/**
 * AsyncBase PHP SDK — queue primitive client.
 *
 * Usage:
 *
 *   $q = new \AsyncBase\Queue($_ENV['ASYNCBASE_API_KEY']);
 *   $q->send('emails', ['to' => 'user@example.com']);
 *
 *   foreach ($q->consume('emails', group: 'worker-1') as $msg) {
 *       sendEmail($msg->payload);
 *       $msg->ack();
 *   }
 */
final class Queue
{
    public const VERSION = '0.0.1';

    private const DEFAULT_BASE_URL = 'https://api.asyncbase.dev';
    private const API_KEY_RE = '/^sk_(test|live)_[a-zA-Z0-9_-]+$/';

    private readonly HttpClient $http;
    private readonly string $apiKey;
    private readonly string $baseUrl;

    public function __construct(
        string $apiKey,
        ?string $baseUrl = null,
        float $timeout = 30.0,
    ) {
        if ($apiKey === '' || !preg_match(self::API_KEY_RE, $apiKey)) {
            throw new \InvalidArgumentException(
                'AsyncBase: API key is required and must match sk_(test|live)_[a-zA-Z0-9_-]+',
            );
        }
        $this->apiKey = $apiKey;
        $this->baseUrl = rtrim($baseUrl ?? self::DEFAULT_BASE_URL, '/');
        $this->http = new HttpClient([
            'base_uri' => $this->baseUrl,
            'timeout' => $timeout,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey,
            ],
        ]);
    }

    /**
     * Enqueue a message.
     *
     * @param  array<string, mixed>  $payload
     */
    public function send(
        string $queue,
        array $payload,
        ?string $idempotencyKey = null,
        ?string $delay = null,
        ?int $retries = null,
        ?string $ttl = null,
        ?string $fifoGroup = null,
        ?string $dedupeId = null,
    ): EnqueueResult {
        $body = ['payload' => $payload];
        if ($delay !== null) {
            $body['delay'] = $delay;
        }
        if ($retries !== null) {
            $body['retries'] = $retries;
        }
        if ($ttl !== null) {
            $body['ttl'] = $ttl;
        }
        if ($fifoGroup !== null) {
            $body['fifo_group'] = $fifoGroup;
        }
        if ($dedupeId !== null) {
            $body['dedupe_id'] = $dedupeId;
        }

        $idem = $idempotencyKey ?? self::uuid4();

        $res = $this->request('POST', "/v1/queues/{$queue}/messages", [
            'headers' => ['Idempotency-Key' => $idem],
            'json' => $body,
        ]);
        $data = self::decode($res);

        return new EnqueueResult(
            id: (string) $data['id'],
            enqueuedAt: (string) $data['enqueued_at'],
            deliverAt: $data['deliver_at'] ?? null,
            deduped: $data['deduped'] ?? null,
        );
    }

    /**
     * Pull one batch of messages (single HTTP call).
     *
     * @return array<int, ConsumedMessage>
     */
    public function pull(
        string $queue,
        string $group,
        ?string $consumer = null,
        int $limit = 10,
        int $waitSeconds = 0,
        int $visibilitySeconds = 30,
    ): array {
        $consumer ??= 'c-' . bin2hex(random_bytes(4));
        $query = [
            'group' => $group,
            'consumer' => $consumer,
            'limit' => (string) $limit,
            'wait' => (string) $waitSeconds,
            'visibility_seconds' => (string) $visibilitySeconds,
        ];

        $res = $this->request('GET', "/v1/queues/{$queue}/messages", [
            'query' => $query,
            'timeout' => max(30.0, $waitSeconds + 5.0),
        ]);
        $data = self::decode($res);

        $out = [];
        foreach (($data['messages'] ?? []) as $m) {
            $out[] = new ConsumedMessage(
                queueClient: $this,
                queue: $queue,
                group: $group,
                consumer: $consumer,
                id: (string) $m['id'],
                payload: (array) ($m['payload'] ?? []),
                enqueuedAt: (string) $m['enqueued_at'],
                attempt: (int) ($m['attempt'] ?? 1),
                ackDeadline: (string) $m['ack_deadline'],
                fifoGroup: $m['fifo_group'] ?? null,
            );
        }

        return $out;
    }

    /**
     * Infinite consume loop — `yield`s each message. Caller must ack/nack each.
     *
     * @return \Generator<int, ConsumedMessage>
     */
    public function consume(
        string $queue,
        string $group,
        ?string $consumer = null,
        int $limit = 10,
        int $waitSeconds = 20,
        int $visibilitySeconds = 30,
        float $idlePollSec = 1.0,
    ): \Generator {
        $consumer ??= 'c-' . bin2hex(random_bytes(4));

        while (true) {
            try {
                $batch = $this->pull(
                    queue: $queue,
                    group: $group,
                    consumer: $consumer,
                    limit: $limit,
                    waitSeconds: $waitSeconds,
                    visibilitySeconds: $visibilitySeconds,
                );
            } catch (AsyncBaseError $e) {
                throw $e;
            } catch (\Throwable) {
                usleep((int) ($idlePollSec * 1_000_000));
                continue;
            }

            if ($batch === []) {
                if ($waitSeconds === 0) {
                    usleep((int) ($idlePollSec * 1_000_000));
                }
                continue;
            }

            foreach ($batch as $msg) {
                yield $msg;
            }
        }
    }

    public function ack(string $queue, string $messageId, string $group): AckResult
    {
        if ($group === '') {
            throw new \InvalidArgumentException('AsyncBase: `group` is required for ack()');
        }
        $res = $this->request('POST', "/v1/queues/{$queue}/messages/{$messageId}/ack", [
            'query' => ['group' => $group],
        ]);
        $data = self::decode($res);

        return new AckResult(
            id: (string) $data['id'],
            ackedAt: (string) $data['acked_at'],
            alreadyAcked: $data['already_acked'] ?? null,
        );
    }

    public function nack(
        string $queue,
        string $messageId,
        string $group,
        int $attempt = 1,
    ): NackResult {
        if ($group === '') {
            throw new \InvalidArgumentException('AsyncBase: `group` is required for nack()');
        }
        $res = $this->request('POST', "/v1/queues/{$queue}/messages/{$messageId}/nack", [
            'query' => ['group' => $group, 'attempt' => (string) $attempt],
        ]);
        $data = self::decode($res);

        return new NackResult(
            id: (string) $data['id'],
            nackedAt: (string) $data['nacked_at'],
            movedToDlq: (bool) ($data['moved_to_dlq'] ?? false),
            retryAt: $data['retry_at'] ?? null,
            nextAttempt: $data['next_attempt'] ?? null,
            backoffMs: $data['backoff_ms'] ?? null,
            attempt: $data['attempt'] ?? null,
            alreadyHandled: $data['already_handled'] ?? null,
        );
    }

    public function heartbeat(
        string $queue,
        string $messageId,
        string $group,
        string $consumer,
    ): HeartbeatResult {
        if ($group === '' || $consumer === '') {
            throw new \InvalidArgumentException('AsyncBase: `group` and `consumer` are required for heartbeat()');
        }
        $res = $this->request('POST', "/v1/queues/{$queue}/messages/{$messageId}/heartbeat", [
            'query' => ['group' => $group, 'consumer' => $consumer],
        ]);
        $data = self::decode($res);

        return new HeartbeatResult(
            id: (string) $data['id'],
            heartbeatAt: (string) $data['heartbeat_at'],
            consumer: (string) $data['consumer'],
            group: (string) $data['group'],
        );
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function request(string $method, string $uri, array $options = []): \Psr\Http\Message\ResponseInterface
    {
        try {
            return $this->http->request($method, $uri, array_merge([
                'http_errors' => false,
            ], $options));
        } catch (RequestException $e) {
            throw new AsyncBaseError(
                code: 'NETWORK_ERROR',
                message: $e->getMessage(),
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(\Psr\Http\Message\ResponseInterface $res): array
    {
        $status = $res->getStatusCode();
        $body = (string) $res->getBody();

        if ($status >= 400) {
            $data = [];
            try {
                $data = json_decode($body, true, flags: JSON_THROW_ON_ERROR) ?? [];
            } catch (\JsonException) {
                // non-JSON error
            }
            $err = $data['error'] ?? [];
            throw new AsyncBaseError(
                code: (string) ($err['code'] ?? 'UNKNOWN'),
                message: (string) ($err['message'] ?? $res->getReasonPhrase()),
                docs: (string) ($err['docs'] ?? ''),
                status: $status,
                requestId: $err['request_id'] ?? null,
            );
        }

        try {
            return json_decode($body, true, flags: JSON_THROW_ON_ERROR) ?? [];
        } catch (\JsonException $e) {
            throw new AsyncBaseError(
                code: 'BAD_RESPONSE',
                message: 'Server returned invalid JSON: ' . $e->getMessage(),
                status: $status,
            );
        }
    }

    private static function uuid4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0F) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3F) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}

/**
 * @codeCoverageIgnore
 */
final class ConsumedMessage
{
    public function __construct(
        private readonly Queue $queueClient,
        private readonly string $queue,
        private readonly string $group,
        private readonly string $consumer,
        public readonly string $id,
        public readonly array $payload,
        public readonly string $enqueuedAt,
        public readonly int $attempt,
        public readonly string $ackDeadline,
        public readonly ?string $fifoGroup = null,
    ) {
    }

    public function ack(): AckResult
    {
        return $this->queueClient->ack($this->queue, $this->id, $this->group);
    }

    public function nack(?int $attempt = null): NackResult
    {
        return $this->queueClient->nack(
            $this->queue,
            $this->id,
            $this->group,
            $attempt ?? $this->attempt,
        );
    }

    public function heartbeat(): HeartbeatResult
    {
        return $this->queueClient->heartbeat($this->queue, $this->id, $this->group, $this->consumer);
    }
}

final class EnqueueResult
{
    public function __construct(
        public readonly string $id,
        public readonly string $enqueuedAt,
        public readonly ?string $deliverAt = null,
        public readonly ?bool $deduped = null,
    ) {
    }
}

final class AckResult
{
    public function __construct(
        public readonly string $id,
        public readonly string $ackedAt,
        public readonly ?bool $alreadyAcked = null,
    ) {
    }
}

final class NackResult
{
    public function __construct(
        public readonly string $id,
        public readonly string $nackedAt,
        public readonly bool $movedToDlq = false,
        public readonly ?string $retryAt = null,
        public readonly ?int $nextAttempt = null,
        public readonly ?int $backoffMs = null,
        public readonly ?int $attempt = null,
        public readonly ?bool $alreadyHandled = null,
    ) {
    }
}

final class HeartbeatResult
{
    public function __construct(
        public readonly string $id,
        public readonly string $heartbeatAt,
        public readonly string $consumer,
        public readonly string $group,
    ) {
    }
}
