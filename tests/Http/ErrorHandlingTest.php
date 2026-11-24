<?php

declare(strict_types=1);

namespace Ehf\Api\Tests\Http;

use Ehf\Api\Http\Error\ApiException;
use Ehf\Api\Http\Error\ErrorCode;
use Ehf\Api\Http\Error\ErrorDetail;
use Ehf\Api\Http\Response\JsonResponder;
use Ehf\Api\Http\RouteAccess;
use Ehf\Api\Infrastructure\Id\UlidGenerator;
use Ehf\Api\Tests\Support\TestApp;
use Monolog\Level;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

final class ErrorHandlingTest extends TestCase
{
    public function testUnknownRouteIsNotFoundInTheStandardErrorFormat(): void
    {
        $response = (new TestApp())->handle('GET', '/api/v3/does-not-exist');

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(JsonResponder::CONTENT_TYPE, $response->getHeaderLine('Content-Type'));
        self::assertSame(['error'], array_keys(TestApp::json($response)));

        $raw = TestApp::json($response)['error'];
        self::assertIsArray($raw);
        self::assertSame(['code', 'message', 'details', 'request_id'], array_keys($raw));

        $error = TestApp::error($response);
        self::assertSame('not_found', $error['code']);
        self::assertSame([], $error['details']);
        self::assertSame($response->getHeaderLine('X-Request-Id'), $error['request_id']);
        self::assertTrue(UlidGenerator::isValid($error['request_id']));
    }

    public function testUnsupportedMethodOnAKnownPathIsAlsoNotFound(): void
    {
        $response = (new TestApp())->handle('DELETE', '/api/v3/health');

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('not_found', TestApp::error($response)['code']);
    }

    public function testApiExceptionIsRenderedWithItsCodeDetailsAndHeaders(): void
    {
        $test = new TestApp();
        $test->app->get('/api/v3/__test/refuse', function (): ResponseInterface {
            throw new ApiException(
                ErrorCode::ValidationFailed,
                details: [new ErrorDetail('duration_minutes', 'out_of_range', 'Must be between 1 and 1440.')],
                headers: ['Retry-After' => '30'],
            );
        })->setArgument(RouteAccess::PERMISSION, RouteAccess::PUBLIC);

        $response = $test->handle('GET', '/api/v3/__test/refuse');
        $error = TestApp::error($response);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('validation_failed', $error['code']);
        self::assertSame('Some fields need attention.', $error['message']);
        self::assertSame(
            [['field' => 'duration_minutes', 'code' => 'out_of_range', 'message' => 'Must be between 1 and 1440.']],
            $error['details'],
        );
        self::assertSame('30', $response->getHeaderLine('Retry-After'));
    }

    public function testUnexpectedExceptionIsInternalErrorWithoutLeakingDetails(): void
    {
        $test = new TestApp();
        $test->app->get('/api/v3/__test/crash', function (): ResponseInterface {
            throw new \RuntimeException('SQLSTATE[42S02] secret table at /var/www/private/file.php');
        })->setArgument(RouteAccess::PERMISSION, RouteAccess::PUBLIC);

        $response = $test->handle('GET', '/api/v3/__test/crash');
        $raw = (string) $response->getBody();
        $error = TestApp::error($response);

        self::assertSame(500, $response->getStatusCode());
        self::assertSame('internal_error', $error['code']);
        self::assertSame(ErrorCode::InternalError->defaultMessage(), $error['message']);
        foreach (['SQLSTATE', 'secret', '/var/www', '.php', 'RuntimeException', 'trace'] as $leak) {
            self::assertStringNotContainsStringIgnoringCase($leak, $raw);
        }

        // Logged server-side, with the request ID, for diagnosis.
        self::assertTrue($test->logs->hasRecordThatPasses(
            static fn($record): bool => $record->message === 'Unhandled exception'
                && ($record->context['request_id'] ?? null) === $error['request_id'],
            Level::Error,
        ));
    }

    public function testARouteWithoutADeclaredPermissionFailsClosed(): void
    {
        $test = new TestApp();
        $test->app->get('/api/v3/__test/undeclared', function (): ResponseInterface {
            throw new \LogicException('The handler must never run.');
        });

        $response = $test->handle('GET', '/api/v3/__test/undeclared');

        self::assertSame(500, $response->getStatusCode());
        self::assertTrue($test->logs->hasRecordThatPasses(
            static fn($record): bool => ($record->context['exception'] ?? null) instanceof \LogicException
                && str_contains($record->context['exception']->getMessage(), 'declares no permission'),
            Level::Error,
        ));
    }

    public function testErrorResponsesCarryTheSecurityHeaders(): void
    {
        $response = (new TestApp())->handle('GET', '/api/v3/missing');

        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertNotSame('', $response->getHeaderLine('Content-Security-Policy'));
    }
}
