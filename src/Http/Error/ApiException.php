<?php

declare(strict_types=1);

namespace Ehf\Api\Http\Error;

use RuntimeException;
use Throwable;

/**
 * An expected, client-facing error. The central error handler turns it into the
 * standard error response (API §4.3). The message must be safe to show to the caller.
 */
final class ApiException extends RuntimeException
{
    /**
     * @param list<ErrorDetail>     $details
     * @param array<string, string> $headers extra response headers (for example Retry-After)
     */
    public function __construct(
        public readonly ErrorCode $errorCode,
        ?string $message = null,
        public readonly array $details = [],
        public readonly array $headers = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message ?? $errorCode->defaultMessage(), 0, $previous);
    }

    public function httpStatus(): int
    {
        return $this->errorCode->httpStatus();
    }
}
