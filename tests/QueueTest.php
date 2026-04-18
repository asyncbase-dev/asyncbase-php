<?php

declare(strict_types=1);

namespace AsyncBase\Tests;

use AsyncBase\AsyncBaseError;
use AsyncBase\Queue;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class QueueTest extends TestCase
{
    /** @var array<int, array{request: Request, response: Response}> */
    private array $history = [];

    private function makeQueue(array $responses): Queue
    {
        $this->history = [];
        $mock = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($this->history));
        return new Queue('sk_test_abc', 'https://test.asyncbase.dev', 30.0, $stack);
    }

    public function testSendWithIdempotencyAndDelay(): void
    {
        $q = $this->makeQueue([
            new Response(201, [], json_encode(['id' => 'msg_1', 'enqueued_at' => '2026-04-18T00:00:00Z'])),
        ]);

        $r = $q->send('emails', ['to' => 'a@b.com'], 'idem-1', '5s');

        $this->assertSame('msg_1', $r->id);
        $this->assertCount(1, $this->history);
        $req = $this->history[0]['request'];
        $this->assertSame('POST', $req->getMethod());
        $this->assertSame('/v1/queues/emails/messages', $req->getUri()->getPath());
        $this->assertSame('Bearer sk_test_abc', $req->getHeaderLine('Authorization'));
        $this->assertSame('idem-1', $req->getHeaderLine('Idempotency-Key'));
        $body = json_decode((string) $req->getBody(), true);
        $this->assertSame('5s', $body['delay']);
    }

    public function testErrorEnvelopeBecomesAsyncBaseError(): void
    {
        $q = $this->makeQueue([
            new Response(413, [], json_encode([
                'error' => [
                    'code' => 'PAYLOAD_TOO_LARGE',
                    'message' => 'Payload exceeds 256KB',
                    'docs' => 'https://asyncbase.dev/docs/errors/PAYLOAD_TOO_LARGE',
                ],
            ])),
        ]);

        try {
            $q->send('emails', []);
            $this->fail('expected AsyncBaseError');
        } catch (AsyncBaseError $e) {
            $this->assertSame('PAYLOAD_TOO_LARGE', $e->errorCode);
            $this->assertSame(413, $e->status);
        }
    }

    public function testPullAndAckRoundtrip(): void
    {
        $q = $this->makeQueue([
            new Response(200, [], json_encode([
                'messages' => [[
                    'id' => 'msg_1',
                    'payload' => ['k' => 'v'],
                    'enqueued_at' => '2026-04-18T00:00:00Z',
                    'attempt' => 1,
                    'ack_deadline' => '2026-04-18T00:00:30Z',
                    'fifo_group' => null,
                    'metadata' => (object) [],
                ]],
            ])),
            new Response(200, [], json_encode(['id' => 'msg_1', 'acked_at' => '2026-04-18T00:00:05Z'])),
        ]);

        $msgs = $q->pull('emails', 'workers');
        $this->assertCount(1, $msgs);
        $msgs[0]->ack();
        $this->assertCount(2, $this->history);
        $this->assertSame('/v1/queues/emails/messages/msg_1/ack', $this->history[1]['request']->getUri()->getPath());
    }

    public function testRejectsInvalidApiKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Queue('');
    }

    public function testRateLimitError(): void
    {
        $q = $this->makeQueue([
            new Response(429, ['Retry-After' => '1'], json_encode([
                'error' => [
                    'code' => 'RATE_LIMITED',
                    'message' => 'Exceeded 10 req/s',
                    'docs' => 'https://asyncbase.dev/docs/errors/RATE_LIMITED',
                ],
            ])),
        ]);

        try {
            $q->send('emails', []);
            $this->fail('expected AsyncBaseError');
        } catch (AsyncBaseError $e) {
            $this->assertSame('RATE_LIMITED', $e->errorCode);
        }
    }
}
