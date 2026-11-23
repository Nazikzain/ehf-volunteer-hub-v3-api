<?php

declare(strict_types=1);

namespace Ehf\Api\Tests\Unit\Config;

use Ehf\Api\Config\AppEnvironment;
use Ehf\Api\Config\ConfigurationException;
use Ehf\Api\Config\Environment;
use Ehf\Api\Config\Settings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SettingsTest extends TestCase
{
    public function testUnsetEnvironmentFailsClosedToProduction(): void
    {
        $settings = $this->settings([]);

        self::assertSame(AppEnvironment::Production, $settings->environment);
        self::assertTrue($settings->hstsEnabled);
        self::assertSame([], $settings->corsAllowedOrigins);
        self::assertNull($settings->database);
    }

    public function testLocalDefaults(): void
    {
        $settings = $this->settings(['APP_ENV' => 'local']);

        self::assertFalse($settings->hstsEnabled);
        self::assertSame(Settings::DEFAULT_MAX_JSON_BODY_BYTES, $settings->maxJsonBodyBytes);
        self::assertSame('/project/storage/logs/app.log', $settings->logPath);
        self::assertSame('info', $settings->logLevel);
        self::assertSame('', $settings->basePath);
    }

    public function testOriginsAreParsedExactly(): void
    {
        $settings = $this->settings([
            'APP_ENV' => 'production',
            'CORS_ALLOWED_ORIGINS' => ' https://app.example.org, https://admin.example.org:8443 ,https://app.example.org,',
        ]);

        self::assertSame(['https://app.example.org', 'https://admin.example.org:8443'], $settings->corsAllowedOrigins);
    }

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function invalidConfigurations(): iterable
    {
        yield 'unknown environment' => [['APP_ENV' => 'dev'], 'APP_ENV'];
        yield 'wildcard origin' => [['APP_ENV' => 'local', 'CORS_ALLOWED_ORIGINS' => '*'], 'wildcards'];
        yield 'wildcard subdomain' => [['APP_ENV' => 'local', 'CORS_ALLOWED_ORIGINS' => 'https://*.example.org'], 'wildcards'];
        yield 'origin with path' => [['APP_ENV' => 'local', 'CORS_ALLOWED_ORIGINS' => 'https://app.example.org/'], 'CORS_ALLOWED_ORIGINS'];
        yield 'uppercase origin' => [['APP_ENV' => 'local', 'CORS_ALLOWED_ORIGINS' => 'https://App.example.org'], 'CORS_ALLOWED_ORIGINS'];
        yield 'http origin in production' => [['APP_ENV' => 'production', 'CORS_ALLOWED_ORIGINS' => 'http://app.example.org'], 'https'];
        yield 'http origin in staging' => [['APP_ENV' => 'staging', 'CORS_ALLOWED_ORIGINS' => 'http://app.example.org'], 'https'];
        yield 'hsts off in production' => [['APP_ENV' => 'production', 'HSTS_ENABLED' => 'false'], 'HSTS_ENABLED'];
        yield 'bad boolean' => [['APP_ENV' => 'local', 'HSTS_ENABLED' => 'maybe'], 'HSTS_ENABLED'];
        yield 'tiny body limit' => [['APP_ENV' => 'local', 'MAX_JSON_BODY_BYTES' => '10'], 'MAX_JSON_BODY_BYTES'];
        yield 'bad log level' => [['APP_ENV' => 'local', 'LOG_LEVEL' => 'verbose'], 'LOG_LEVEL'];
        yield 'bad base path' => [['APP_ENV' => 'local', 'APP_BASE_PATH' => 'backend'], 'APP_BASE_PATH'];
        yield 'partial database settings' => [['APP_ENV' => 'local', 'DB_HOST' => 'localhost'], 'DB_NAME'];
        yield 'bad database port' => [['APP_ENV' => 'local', 'DB_HOST' => 'h', 'DB_NAME' => 'n', 'DB_USER' => 'u', 'DB_PASSWORD' => 'p', 'DB_PORT' => '99999'], 'DB_PORT'];
    }

    /**
     * @param array<string, string> $values
     */
    #[DataProvider('invalidConfigurations')]
    public function testInvalidConfigurationIsRefused(array $values, string $messageFragment): void
    {
        try {
            $this->settings($values);
            self::fail('Expected a ConfigurationException.');
        } catch (ConfigurationException $e) {
            self::assertStringContainsString($messageFragment, $e->getMessage());
        }
    }

    public function testDatabaseSettingsAreReadWithoutExposingThePassword(): void
    {
        $settings = $this->settings([
            'APP_ENV' => 'local',
            'DB_HOST' => 'db.internal',
            'DB_NAME' => 'v3_example',
            'DB_USER' => 'v3_runtime',
            'DB_PASSWORD' => 'example-only-value',
        ]);

        $database = $settings->database;
        self::assertNotNull($database);
        self::assertSame(3306, $database->port);
        self::assertSame('example-only-value', $database->password());
        self::assertStringNotContainsString('example-only-value', print_r($database, true));
        self::assertStringNotContainsString('example-only-value', print_r($settings, true));
    }

    public function testConfigurationErrorsNeverEchoValues(): void
    {
        try {
            $this->settings(['APP_ENV' => 'local', 'CORS_ALLOWED_ORIGINS' => 'https://secret-host.internal/path']);
            self::fail('Expected a ConfigurationException.');
        } catch (ConfigurationException $e) {
            self::assertStringNotContainsString('secret-host', $e->getMessage());
        }
    }

    public function testBlankValuesCountAsUnset(): void
    {
        $settings = $this->settings(['APP_ENV' => 'local', 'DB_HOST' => '  ', 'DB_PASSWORD' => '']);

        self::assertNull($settings->database);
    }

    /**
     * @param array<string, string> $values
     */
    private function settings(array $values): Settings
    {
        return Settings::fromEnvironment(new Environment($values), '/project');
    }
}
