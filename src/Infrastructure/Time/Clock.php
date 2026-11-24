<?php

declare(strict_types=1);

namespace Ehf\Api\Infrastructure\Time;

/**
 * The current time in UTC. Injected so that expiry rules can be tested.
 */
interface Clock
{
    public function now(): \DateTimeImmutable;
}
