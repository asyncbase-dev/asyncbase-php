<?php

declare(strict_types=1);

namespace AsyncBase\Laravel\Connectors;

use AsyncBase\Laravel\Queue\AsyncBaseQueue;
use AsyncBase\Queue as AsyncBaseClient;
use Illuminate\Queue\Connectors\ConnectorInterface;

final class AsyncBaseConnector implements ConnectorInterface
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function connect(array $config): AsyncBaseQueue
    {
        $key = (string) ($config['key'] ?? '');
        if ($key === '') {
            throw new \InvalidArgumentException(
                "AsyncBase queue driver: 'key' config is required. Set ASYNCBASE_KEY in .env.",
            );
        }
        $baseUrl = isset($config['base_url']) && $config['base_url'] !== ''
            ? (string) $config['base_url']
            : null;
        $timeout = (float) ($config['timeout'] ?? 30.0);

        $client = new AsyncBaseClient($key, $baseUrl, $timeout);

        return new AsyncBaseQueue(
            client: $client,
            defaultQueue: (string) ($config['queue'] ?? 'default'),
            group: (string) ($config['group'] ?? 'laravel-workers'),
            consumer: isset($config['consumer']) ? (string) $config['consumer'] : null,
            visibilitySeconds: (int) ($config['visibility_seconds'] ?? 60),
        );
    }
}
