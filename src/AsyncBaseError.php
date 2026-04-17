<?php

declare(strict_types=1);

namespace AsyncBase;

use RuntimeException;

/**
 * Thrown by the SDK on any API error response.
 *
 * Note: Exception already has $code as an int property, so we expose the
 * server-provided string error code via $errorCode to avoid redeclaration.
 */
final class AsyncBaseError extends RuntimeException
{
    /** Server-provided error code (e.g. AUTH_INVALID). */
    public readonly string $errorCode;

    /** URL to docs for this error code. */
    public readonly string $docs;

    /** HTTP status. */
    public readonly ?int $status;

    /** Optional server request id. */
    public readonly ?string $requestId;

    public function __construct(
        string $errorCode,
        string $message,
        string $docs = '',
        ?int $status = null,
        ?string $requestId = null,
    ) {
        parent::__construct("[{$errorCode}] {$message}");
        $this->errorCode = $errorCode;
        $this->docs = $docs;
        $this->status = $status;
        $this->requestId = $requestId;
    }
}
