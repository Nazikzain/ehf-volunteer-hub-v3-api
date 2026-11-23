<?php

declare(strict_types=1);

namespace Ehf\Api\Config;

enum AppEnvironment: string
{
    case Local = 'local';
    case Testing = 'testing';
    case Staging = 'staging';
    case Production = 'production';

    /**
     * Staging and production are deployed to real hosts and get the strict rules
     * (HTTPS-only CORS origins, HSTS on).
     */
    public function isDeployed(): bool
    {
        return $this === self::Staging || $this === self::Production;
    }
}
