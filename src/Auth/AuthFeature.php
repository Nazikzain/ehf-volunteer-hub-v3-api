<?php

declare(strict_types=1);

namespace Ehf\Api\Auth;

use Ehf\Api\Config\AuthSettings;
use Ehf\Api\Config\Settings;
use Ehf\Api\Http\Error\ApiException;
use Ehf\Api\Http\Error\ErrorCode;

/**
 * Whether sign-in is configured. Sign-in needs the values of pending decisions A3, A4, A6 and A7;
 * without them it stays switched off: no session is accepted and the sign-in endpoints answer
 * `503 maintenance`.
 */
final readonly class AuthFeature
{
    public function __construct(private Settings $settings) {}

    public function enabled(): bool
    {
        return $this->settings->auth !== null;
    }

    public function settings(): AuthSettings
    {
        return $this->settings->auth ?? throw new ApiException(ErrorCode::Maintenance, 'Sign-in is not available yet: its settings (decisions A3, A4, A6, A7) are not configured.');
    }
}
