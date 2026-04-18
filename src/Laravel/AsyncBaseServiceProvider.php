<?php

declare(strict_types=1);

namespace AsyncBase\Laravel;

use AsyncBase\Laravel\Connectors\AsyncBaseConnector;
use Illuminate\Queue\QueueManager;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the `asyncbase` queue driver so `php artisan queue:work asyncbase`
 * works out of the box. Auto-discovered by Laravel 11+ via
 * composer.json -> extra.laravel.providers.
 *
 * Configure by adding a connection to config/queue.php:
 *
 *   'asyncbase' => [
 *       'driver'   => 'asyncbase',
 *       'key'      => env('ASYNCBASE_KEY'),
 *       'base_url' => env('ASYNCBASE_BASE_URL'),
 *       'queue'    => 'default',
 *       'group'    => env('ASYNCBASE_GROUP', 'laravel-workers'),
 *       'consumer' => env('ASYNCBASE_CONSUMER'),       // optional; auto
 *       'visibility_seconds' => 60,                     // match retry_after
 *   ],
 *
 * Then: `php artisan queue:work asyncbase --queue=emails`
 */
class AsyncBaseServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // No-op — driver registration happens in boot so QueueManager is ready.
    }

    public function boot(): void
    {
        /** @var QueueManager $manager */
        $manager = $this->app['queue'];
        $manager->addConnector('asyncbase', fn () => new AsyncBaseConnector());
    }
}
