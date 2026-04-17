<?php

declare(strict_types=1);

namespace AsyncBase;

/**
 * AsyncBase PHP SDK — queue primitive client.
 *
 * Step 1: placeholder. Step 7 (post MVP core) scaffolds Queue class via Guzzle.
 *
 * Intended API (not yet implemented):
 *
 *   $q = new \AsyncBase\Queue('sk_live_xxx');
 *   $q->send('emails', ['to' => 'user@example.com']);
 *   $q->consume('emails', fn($msg) => sendEmail($msg->payload));
 */
final class Client
{
    public const VERSION = '0.0.1';
}
