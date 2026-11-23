<?php

declare(strict_types=1);

namespace Ehf\Api\Tests\Http;

use Ehf\Api\Tests\Support\TestApp;
use PHPUnit\Framework\TestCase;

final class CorsTest extends TestCase
{
    public function testAllowedOriginReceivesExactOriginAndCredentials(): void
    {
        $response = (new TestApp())->handle('GET', '/api/v3/health', ['Origin' => TestApp::ALLOWED_ORIGIN]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(TestApp::ALLOWED_ORIGIN, $response->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertSame('true', $response->getHeaderLine('Access-Control-Allow-Credentials'));
        self::assertStringContainsString('X-Request-Id', $response->getHeaderLine('Access-Control-Expose-Headers'));
        self::assertStringContainsString('Origin', $response->getHeaderLine('Vary'));
    }

    public function testOtherOriginsReceiveNoCorsHeaders(): void
    {
        foreach (['https://evil.example', 'https://app.example.test.evil.example', 'http://app.example.test', 'null'] as $origin) {
            $response = (new TestApp())->handle('GET', '/api/v3/health', ['Origin' => $origin]);

            self::assertFalse($response->hasHeader('Access-Control-Allow-Origin'), $origin);
            self::assertFalse($response->hasHeader('Access-Control-Allow-Credentials'), $origin);
            self::assertStringContainsString('Origin', $response->getHeaderLine('Vary'));
        }
    }

    public function testRequestsWithoutOriginAreUnaffected(): void
    {
        $response = (new TestApp())->handle('GET', '/api/v3/health');

        self::assertSame(200, $response->getStatusCode());
        self::assertFalse($response->hasHeader('Access-Control-Allow-Origin'));
    }

    public function testPreflightFromAllowedOriginIsAnswered(): void
    {
        $response = (new TestApp())->handle('OPTIONS', '/api/v3/health', [
            'Origin' => TestApp::ALLOWED_ORIGIN,
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'Content-Type, X-CSRF-Token',
        ]);

        self::assertSame(204, $response->getStatusCode());
        self::assertSame(TestApp::ALLOWED_ORIGIN, $response->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertSame('GET, POST, PUT, PATCH, DELETE', $response->getHeaderLine('Access-Control-Allow-Methods'));
        self::assertSame('Content-Type, X-CSRF-Token, Idempotency-Key', $response->getHeaderLine('Access-Control-Allow-Headers'));
        self::assertSame('600', $response->getHeaderLine('Access-Control-Max-Age'));
        self::assertNotSame('', $response->getHeaderLine('X-Request-Id'));
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
    }

    public function testPreflightFromOtherOriginIsRefused(): void
    {
        $response = (new TestApp())->handle('OPTIONS', '/api/v3/health', [
            'Origin' => 'https://evil.example',
            'Access-Control-Request-Method' => 'POST',
        ]);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('origin_not_allowed', TestApp::error($response)['code']);
        self::assertFalse($response->hasHeader('Access-Control-Allow-Origin'));
    }

    public function testWithNoConfiguredOriginsEveryPreflightIsRefused(): void
    {
        $app = new TestApp(TestApp::settings(origins: []));
        $response = $app->handle('OPTIONS', '/api/v3/health', [
            'Origin' => TestApp::ALLOWED_ORIGIN,
            'Access-Control-Request-Method' => 'GET',
        ]);

        self::assertSame(403, $response->getStatusCode());
    }

    public function testActingRoleHeaderIsNotYetAllowed(): void
    {
        $response = (new TestApp())->handle('OPTIONS', '/api/v3/health', [
            'Origin' => TestApp::ALLOWED_ORIGIN,
            'Access-Control-Request-Method' => 'GET',
        ]);

        // Reserved for multiple roles (R2, pending): not advertised until approved.
        self::assertStringNotContainsString('X-Acting-Role', $response->getHeaderLine('Access-Control-Allow-Headers'));
    }
}
