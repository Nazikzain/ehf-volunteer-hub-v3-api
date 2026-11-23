<?php

declare(strict_types=1);

namespace Ehf\Api\Infrastructure\Id;

interface RequestIdGenerator
{
    /**
     * A new, unique request ID: 26 characters of Crockford base32 (a ULID).
     */
    public function generate(): string;
}
