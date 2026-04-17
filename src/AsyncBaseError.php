<?php

declare(strict_types=1);

namespace AsyncBase;

use RuntimeException;

/**
 * Thrown by the SDK on any API error response.
 */
final class AsyncBaseError extends RuntimeException
{
    public function __construct(
        public readonly string $code,
        string $message,
        public readonly string $docs = '',
        public readonly ?int $status = null,
        public readonly ?string $requestId = null,
    ) {
        parent::__construct("[{$code}] {$message}");
    }
}
