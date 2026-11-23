<?php

declare(strict_types=1);

namespace Ehf\Api\Tests\Unit\Http;

use Ehf\Api\Http\Error\ErrorCode;
use PHPUnit\Framework\TestCase;

final class ErrorCodeTest extends TestCase
{
    /**
     * The table of API V1.3.1 §4.3, transcribed independently of the enum.
     */
    private const array API_TABLE = [
        400 => ['malformed_json', 'unknown_field'],
        401 => ['unauthenticated', 'session_expired', 'invalid_credentials'],
        403 => ['forbidden', 'csrf_invalid', 'origin_not_allowed', 'self_decision_forbidden', 'self_grant_forbidden',
            'owner_authority_required', 'final_owner_protected', 'password_confirmation_required', 'field_not_permitted',
            'format_not_permitted', 'report_not_permitted'],
        404 => ['not_found'],
        409 => ['invalid_transition', 'stale_version', 'duplicate', 'active_certificate_exists', 'not_eligible',
            'not_eligible_for_provisioning', 'account_exists', 'period_completed', 'locked_by_certificate', 'token_used'],
        410 => ['token_expired', 'export_expired'],
        413 => ['payload_too_large'],
        415 => ['unsupported_media_type'],
        422 => ['validation_failed', 'idempotency_mismatch', 'combination_not_allowed', 'use_operational_exception',
            'field_not_editable', 'too_many_files', 'too_many_rows', 'password_policy'],
        429 => ['rate_limited'],
        500 => ['internal_error'],
        503 => ['maintenance'],
    ];

    public function testEnumMatchesTheApiTableExactly(): void
    {
        $expected = [];
        foreach (self::API_TABLE as $status => $codes) {
            foreach ($codes as $code) {
                $expected[$code] = $status;
            }
        }

        $actual = [];
        foreach (ErrorCode::cases() as $case) {
            $actual[$case->value] = $case->httpStatus();
        }

        ksort($expected);
        ksort($actual);
        self::assertSame($expected, $actual);
    }

    public function testEveryCodeHasASafeMessage(): void
    {
        foreach (ErrorCode::cases() as $case) {
            $message = $case->defaultMessage();
            self::assertNotSame('', $message, $case->value);
            self::assertMatchesRegularExpression('/\.$/', $message, $case->value);
        }
    }
}
