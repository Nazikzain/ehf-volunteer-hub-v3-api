<?php

declare(strict_types=1);

namespace Ehf\Api\Config;

/**
 * Typed, validated application settings, built once at start-up from the environment.
 *
 * Only technical values live here. Values that depend on pending decisions (session and token
 * lifetimes A4/A5, password policy A6, rate limits A7, domains and SameSite A3, email A11) are
 * deliberately absent until those decisions are approved and their phase begins.
 */
final readonly class Settings
{
    public const int DEFAULT_MAX_JSON_BODY_BYTES = 1_048_576;

    private const array LOG_LEVELS = ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'];

    /**
     * @param list<string> $corsAllowedOrigins exact origins (scheme://host[:port]); never a wildcard
     */
    public function __construct(
        public AppEnvironment $environment,
        public string $projectRoot,
        public string $basePath,
        public array $corsAllowedOrigins,
        public bool $hstsEnabled,
        public int $maxJsonBodyBytes,
        public string $logPath,
        public string $logLevel,
        public ?DatabaseSettings $database,
    ) {}

    public static function fromEnvironment(Environment $env, string $projectRoot): self
    {
        $environment = self::parseEnvironment($env->get('APP_ENV'));

        $origins = self::parseOrigins($env->get('CORS_ALLOWED_ORIGINS'), $environment);

        $hsts = self::parseBool($env->get('HSTS_ENABLED'), 'HSTS_ENABLED') ?? $environment->isDeployed();
        if ($environment->isDeployed() && !$hsts) {
            throw new ConfigurationException('HSTS_ENABLED cannot be false in staging or production.');
        }

        $maxBody = $env->get('MAX_JSON_BODY_BYTES') ?? (string) self::DEFAULT_MAX_JSON_BODY_BYTES;
        if (preg_match('/^\d{1,9}$/', $maxBody) !== 1 || (int) $maxBody < 1024) {
            throw new ConfigurationException('MAX_JSON_BODY_BYTES must be a whole number of at least 1024.');
        }

        $logLevel = strtolower($env->get('LOG_LEVEL') ?? 'info');
        if (!\in_array($logLevel, self::LOG_LEVELS, true)) {
            throw new ConfigurationException('LOG_LEVEL must be one of: ' . implode(', ', self::LOG_LEVELS) . '.');
        }

        $basePath = rtrim($env->get('APP_BASE_PATH') ?? '', '/');
        if ($basePath !== '' && preg_match('#^(/[A-Za-z0-9._-]+)+$#', $basePath) !== 1) {
            throw new ConfigurationException('APP_BASE_PATH must be empty or a path such as /backend.');
        }

        return new self(
            environment: $environment,
            projectRoot: $projectRoot,
            basePath: $basePath,
            corsAllowedOrigins: $origins,
            hstsEnabled: $hsts,
            maxJsonBodyBytes: (int) $maxBody,
            logPath: $env->get('LOG_PATH') ?? $projectRoot . '/storage/logs/app.log',
            logLevel: $logLevel,
            database: DatabaseSettings::fromEnvironment($env),
        );
    }

    private static function parseEnvironment(?string $value): AppEnvironment
    {
        if ($value === null) {
            // Fail closed: an unset environment gets the strictest rules.
            return AppEnvironment::Production;
        }

        return AppEnvironment::tryFrom(strtolower($value))
            ?? throw new ConfigurationException('APP_ENV must be one of: local, testing, staging, production.');
    }

    /**
     * @return list<string>
     */
    private static function parseOrigins(?string $value, AppEnvironment $environment): array
    {
        if ($value === null) {
            return [];
        }

        $origins = [];
        foreach (explode(',', $value) as $origin) {
            $origin = trim($origin);
            if ($origin === '') {
                continue;
            }

            if (str_contains($origin, '*')) {
                throw new ConfigurationException('CORS_ALLOWED_ORIGINS must list exact origins; wildcards are not allowed.');
            }

            if (preg_match('#^(https?)://([a-z0-9]([a-z0-9-]*[a-z0-9])?)(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*(:\d{1,5})?$#', $origin, $m) !== 1) {
                throw new ConfigurationException('CORS_ALLOWED_ORIGINS entries must be origins such as https://app.example.org (lowercase, no path).');
            }

            if ($environment->isDeployed() && $m[1] !== 'https') {
                throw new ConfigurationException('CORS_ALLOWED_ORIGINS must use https in staging and production.');
            }

            $origins[] = $origin;
        }

        return array_values(array_unique($origins));
    }

    private static function parseBool(?string $value, string $name): ?bool
    {
        if ($value === null) {
            return null;
        }

        return match (strtolower($value)) {
            'true', '1', 'yes', 'on' => true,
            'false', '0', 'no', 'off' => false,
            default => throw new ConfigurationException($name . ' must be true or false.'),
        };
    }
}
