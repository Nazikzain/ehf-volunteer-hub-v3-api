<?php

declare(strict_types=1);

namespace Ehf\Api\Auth;

use Ehf\Api\Http\Error\ApiException;
use Ehf\Api\Http\Error\ErrorCode;
use Ehf\Api\Http\Error\ErrorDetail;

/**
 * Password policy (decision A6, pending): only the configured minimum and maximum lengths are
 * applied. No complexity rules and no leaked-password check, because neither is approved.
 */
final readonly class PasswordPolicy
{
    public function __construct(private AuthFeature $feature) {}

    public function check(string $password, string $field = 'password'): void
    {
        $settings = $this->feature->settings();
        $length = mb_strlen($password, 'UTF-8');
        if ($length < $settings->passwordMinLength || $length > $settings->passwordMaxLength) {
            throw new ApiException(ErrorCode::PasswordPolicy, details: [new ErrorDetail(
                $field,
                'length',
                \sprintf('Must be %d to %d characters.', $settings->passwordMinLength, $settings->passwordMaxLength),
            )]);
        }
    }
}
