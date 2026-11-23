<?php

declare(strict_types=1);

namespace Ehf\Api\Tests\Http;

use Ehf\Api\Http\Response\JsonResponder;
use Ehf\Api\Infrastructure\Id\UlidGenerator;
use Ehf\Api\Tests\Support\TestApp;
use PHPUnit\Framework\TestCase;

final class HealthEndpointTest extends TestCase
{
    public function testHealthReturnsStatusInTheStandardEnvelope(): void
    {
        $response = (new TestApp())->handle('GET', '/api/v3/health');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(JsonResponder::CONTENT_TYPE, $response->getHeaderLine('Content-Type'));

        $body = TestApp::json($response);
        self::assertSame(['status' => 'ok'], $body['data']);
        self::assertIsArray($body['meta']);
        self::assertSame(['request_id'], array_keys($body['meta']));
        self::assertSame($response->getHeaderLine('X-Request-Id'), $body['meta']['request_id']);
        self::assertTrue(UlidGenerator::isValid($response->getHeaderLine('X-Request-Id')));
    }

    public function testHealthRevealsNoVersionOrConfiguration(): void
    {
        $raw = (string) (new TestApp())->handle('GET', '/api/v3/health')->getBody();

        foreach (['version', 'php', 'mysql', 'mariadb', 'database', 'env', 'path'] as $word) {
            self::assertStringNotContainsStringIgnoringCase($word, $raw);
        }
    }

    public function testHealthIsOnlyServedUnderApiV3(): void
    {
        $app = new TestApp();

        self::assertSame(404, $app->handle('GET', '/health')->getStatusCode());
        self::assertSame(404, $app->handle('GET', '/api/v2/health')->getStatusCode());
    }

    public function testHealthWorksUnderAConfiguredBasePath(): void
    {
        $app = new TestApp(TestApp::settings(basePath: '/backend'));

        self::assertSame(200, $app->handle('GET', '/backend/api/v3/health')->getStatusCode());
        self::assertSame(404, $app->handle('GET', '/api/v3/health')->getStatusCode());
    }

    public function testEachRequestGetsANewServerGeneratedRequestId(): void
    {
        $app = new TestApp();
        $first = $app->handle('GET', '/api/v3/health', ['X-Request-Id' => 'client-chosen-id']);
        $second = $app->handle('GET', '/api/v3/health');

        self::assertNotSame('client-chosen-id', $first->getHeaderLine('X-Request-Id'));
        self::assertNotSame($first->getHeaderLine('X-Request-Id'), $second->getHeaderLine('X-Request-Id'));
    }
}
