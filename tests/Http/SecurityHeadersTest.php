<?php

declare(strict_types=1);

namespace Ehf\Api\Tests\Http;

use Ehf\Api\Config\AppEnvironment;
use Ehf\Api\Tests\Support\TestApp;
use PHPUnit\Framework\TestCase;

final class SecurityHeadersTest extends TestCase
{
    public function testSecurityHeadersOfApiSection15AreSent(): void
    {
        $response = (new TestApp())->handle('GET', '/api/v3/health');

        self::assertSame("default-src 'none'; frame-ancestors 'none'", $response->getHeaderLine('Content-Security-Policy'));
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertSame('no-referrer', $response->getHeaderLine('Referrer-Policy'));
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertFalse($response->hasHeader('X-Powered-By'));
    }

    public function testHstsIsOffLocallyAndOnWhenEnabled(): void
    {
        self::assertFalse((new TestApp())->handle('GET', '/api/v3/health')->hasHeader('Strict-Transport-Security'));

        $production = new TestApp(TestApp::settings(environment: AppEnvironment::Production, hsts: true));
        self::assertSame(
            'max-age=31536000; includeSubDomains',
            $production->handle('GET', '/api/v3/health')->getHeaderLine('Strict-Transport-Security'),
        );
    }
}
