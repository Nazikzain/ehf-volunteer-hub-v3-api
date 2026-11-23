<?php

declare(strict_types=1);

namespace Ehf\Api\Http\Error;

/**
 * One entry of `error.details` (API §4.3): which field, which rule, and a safe message.
 */
final readonly class ErrorDetail
{
    public function __construct(
        public string $field,
        public string $code,
        public string $message,
    ) {}

    /**
     * @return array{field: string, code: string, message: string}
     */
    public function toArray(): array
    {
        return ['field' => $this->field, 'code' => $this->code, 'message' => $this->message];
    }
}
