<?php

declare(strict_types=1);

namespace Ehf\Api\Tests\Unit\Config;

use Ehf\Api\Config\AuthSettings;
use Ehf\Api\Config\ConfigurationException;
use Ehf\Api\Config\Environment;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Sign-in settings depend on pending decisions (A3, A4, A6, A7): no defaults, off when absent,
 * start-up failure when half configured.
 */
final class AuthSettingsTest extends TestCase
{
    /**
     * @return array<string, string>
     */
    private static function complete(): array
    {
        // Test-only values; not decisions.
        return [
            'AUTH_SESSION_SAMESITE' => 'lax', 'AUTH_SESSION_IDLE_MINUTES' => '30', 'AUTH_SESSION_ABSOLUTE_MINUTES' => '720',
            'AUTH_PASSWORD_MIN_LENGTH' => '12', 'AUTH_PASSWORD_MAX_LENGTH' => '128',
            'AUTH_RATE_LOGIN_ACCOUNT_MAX' => '5', 'AUTH_RATE_LOGIN_IP_MAX' => '20', 'AUTH_RATE_LOGIN_WINDOW_MINUTES' => '15',
            'AUTH_RATE_TOKEN_IP_MAX' => '10', 'AUTH_RATE_TOKEN_WINDOW_MINUTES' => '15',
        ];
    }

    public function testNothingConfiguredMeansSignInIsOff(): void
    {
        self::assertNull(AuthSettings::fromEnvironment(new Environment([])));
    }

    public function testCompleteConfigurationIsRead(): void
    {
        $settings = AuthSettings::fromEnvironment(new Environment(self::complete()));

        self::assertNotNull($settings);
        self::assertSame('Lax', $settings->sameSite);
        self::assertSame(30, $settings->sessionIdleMinutes);
        self::assertSame(15, $settings->tokenWindowMinutes);
    }

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function invalid(): iterable
    {
        yield 'partial' => [['AUTH_SESSION_SAMESITE' => 'Lax'], 'incomplete'];
        yield 'bad samesite' => [['AUTH_SESSION_SAMESITE' => 'Loose'] + self::complete(), 'AUTH_SESSION_SAMESITE'];
        yield 'zero' => [['AUTH_SESSION_IDLE_MINUTES' => '0'] + self::complete(), 'AUTH_SESSION_IDLE_MINUTES'];
        yield 'not a number' => [['AUTH_RATE_LOGIN_IP_MAX' => 'many'] + self::complete(), 'AUTH_RATE_LOGIN_IP_MAX'];
        yield 'idle above absolute' => [['AUTH_SESSION_IDLE_MINUTES' => '900'] + self::complete(), 'cannot exceed'];
        yield 'min above max' => [['AUTH_PASSWORD_MIN_LENGTH' => '200'] + self::complete(), 'cannot exceed'];
    }

    /**
     * @param array<string, string> $values
     */
    #[DataProvider('invalid')]
    public function testInvalidConfigurationStopsStartUp(array $values, string $fragment): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage($fragment);

        AuthSettings::fromEnvironment(new Environment($values));
    }
}
