<?php

declare(strict_types=1);

namespace Ehf\Api\Config;

/**
 * Values for sign-in, sessions, passwords and rate limits.
 *
 * Every value depends on a decision that is still pending (API V1.3.1 §17): A3 (SameSite), A4
 * (session timeouts), A6 (password policy) and A7 (rate limits). There are therefore **no
 * defaults**. If none of the AUTH_* variables is set, sign-in is switched off (fail closed); if
 * only some are set, start-up fails so a half-configured deployment cannot run.
 *
 * See docs/phase-4-decisions.md.
 */
final readonly class AuthSettings
{
    public const array VARIABLES = [
        'AUTH_SESSION_SAMESITE',
        'AUTH_SESSION_IDLE_MINUTES',
        'AUTH_SESSION_ABSOLUTE_MINUTES',
        'AUTH_PASSWORD_MIN_LENGTH',
        'AUTH_PASSWORD_MAX_LENGTH',
        'AUTH_RATE_LOGIN_ACCOUNT_MAX',
        'AUTH_RATE_LOGIN_IP_MAX',
        'AUTH_RATE_LOGIN_WINDOW_MINUTES',
        'AUTH_RATE_TOKEN_IP_MAX',
        'AUTH_RATE_TOKEN_WINDOW_MINUTES',
    ];

    /** bcrypt ignores input beyond 72 bytes; only Argon2id may accept longer passwords. */
    public const int BCRYPT_MAX_BYTES = 72;

    public function __construct(
        public string $sameSite,
        public int $sessionIdleMinutes,
        public int $sessionAbsoluteMinutes,
        public int $passwordMinLength,
        public int $passwordMaxLength,
        public int $loginAccountMax,
        public int $loginIpMax,
        public int $loginWindowMinutes,
        public int $tokenIpMax,
        public int $tokenWindowMinutes,
    ) {}

    public static function fromEnvironment(Environment $env): ?self
    {
        $values = [];
        foreach (self::VARIABLES as $name) {
            $values[$name] = $env->get($name);
        }

        $set = array_filter($values, static fn(?string $v): bool => $v !== null);
        if ($set === []) {
            return null;
        }

        $missing = array_keys(array_diff_key($values, $set));
        if ($missing !== []) {
            throw new ConfigurationException('Sign-in settings are incomplete; also set: ' . implode(', ', $missing) . '.');
        }

        $sameSite = ucfirst(strtolower((string) $values['AUTH_SESSION_SAMESITE']));
        if (!\in_array($sameSite, ['Lax', 'Strict', 'None'], true)) {
            throw new ConfigurationException('AUTH_SESSION_SAMESITE must be Lax, Strict or None.');
        }

        $settings = new self(
            sameSite: $sameSite,
            sessionIdleMinutes: self::positive($values, 'AUTH_SESSION_IDLE_MINUTES'),
            sessionAbsoluteMinutes: self::positive($values, 'AUTH_SESSION_ABSOLUTE_MINUTES'),
            passwordMinLength: self::positive($values, 'AUTH_PASSWORD_MIN_LENGTH'),
            passwordMaxLength: self::positive($values, 'AUTH_PASSWORD_MAX_LENGTH'),
            loginAccountMax: self::positive($values, 'AUTH_RATE_LOGIN_ACCOUNT_MAX'),
            loginIpMax: self::positive($values, 'AUTH_RATE_LOGIN_IP_MAX'),
            loginWindowMinutes: self::positive($values, 'AUTH_RATE_LOGIN_WINDOW_MINUTES'),
            tokenIpMax: self::positive($values, 'AUTH_RATE_TOKEN_IP_MAX'),
            tokenWindowMinutes: self::positive($values, 'AUTH_RATE_TOKEN_WINDOW_MINUTES'),
        );

        if ($settings->sessionIdleMinutes > $settings->sessionAbsoluteMinutes) {
            throw new ConfigurationException('AUTH_SESSION_IDLE_MINUTES cannot exceed AUTH_SESSION_ABSOLUTE_MINUTES.');
        }
        if ($settings->passwordMinLength > $settings->passwordMaxLength) {
            throw new ConfigurationException('AUTH_PASSWORD_MIN_LENGTH cannot exceed AUTH_PASSWORD_MAX_LENGTH.');
        }
        if (!\defined('PASSWORD_ARGON2ID') && $settings->passwordMaxLength > self::BCRYPT_MAX_BYTES) {
            throw new ConfigurationException('AUTH_PASSWORD_MAX_LENGTH cannot exceed 72 without Argon2id support.');
        }

        return $settings;
    }

    /**
     * @param array<string, ?string> $values
     */
    private static function positive(array $values, string $name): int
    {
        $value = (string) $values[$name];
        if (preg_match('/^[1-9]\d{0,6}$/', $value) !== 1) {
            throw new ConfigurationException($name . ' must be a positive whole number.');
        }

        return (int) $value;
    }
}
