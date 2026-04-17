# asyncbase/asyncbase — PHP SDK

AsyncBase PHP SDK. Queue primitive client for PHP 8.1+.

> Step 1 scaffolding. Implementation in Step 7 (post core API).

## Install

```bash
composer require asyncbase/asyncbase
```

## Quickstart (once implemented)

```php
<?php

use AsyncBase\Queue;

$q = new Queue($_ENV['ASYNCBASE_API_KEY']);

// Send
$q->send('emails', ['to' => 'user@example.com']);

// Consume (pull-based)
$q->consume('emails', function ($msg) {
    sendEmail($msg->payload);
}, group: 'worker-1');
```

## Development

```bash
composer install

composer test
composer analyze
composer format
```
