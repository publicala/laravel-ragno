<?php

declare(strict_types=1);

namespace Publicala\Ragno\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Thrown when the Ragno gateway rejects a query or cannot be reached.
 *
 * Carries the canonical error `code`, the `request_id` (matches the gateway's
 * audit log and the `X-Request-Id` response header) and the HTTP status, so
 * failures stay traceable end to end.
 *
 * Note: when a query runs through a {@see \Publicala\Ragno\RagnoConnection},
 * Laravel's `Connection::run()` wraps this in a `QueryException`. Reach the
 * `request_id` from a caught `QueryException` with
 * {@see \Publicala\Ragno\RagnoManager::requestId()} (or `Ragno::requestId()`).
 */
final class RagnoQueryException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'unknown',
        public readonly ?string $requestId = null,
        public readonly ?int $httpStatus = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
