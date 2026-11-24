<?php

declare(strict_types=1);

namespace Ehf\Api\Tests\Unit\Bootstrap;

use Ehf\Api\Bootstrap\FallbackResponse;
use Ehf\Api\Bootstrap\Pipeline;
use Ehf\Api\Http\Middleware\AuthenticationMiddleware;
use Ehf\Api\Http\Middleware\CorsMiddleware;
use Ehf\Api\Http\Middleware\IdempotencyMiddleware;
use Ehf\Api\Http\Middleware\JsonBodyParserMiddleware;
use Ehf\Api\Http\Middleware\OriginCsrfMiddleware;
use Ehf\Api\Http\Middleware\RateLimitMiddleware;
use Ehf\Api\Http\Middleware\RequestIdMiddleware;
use Ehf\Api\Http\Middleware\SecurityHeadersMiddleware;
use Ehf\Api\Http\Middleware\SessionMiddleware;
use Ehf\Api\Infrastructure\Id\UlidGenerator;
use PHPUnit\Framework\TestCase;

final class PipelineTest extends TestCase
{
    public function testPipelineFollowsApiSection212Order(): void
    {
        // API V1.3.1 §2.12: request ID → security headers → CORS → JSON parsing → session →
        // Origin/CSRF → authentication → rate limiting. Error handling wraps the inner steps;
        // routing runs before the session so later steps can read the route's permission.
        self::assertSame([
            RequestIdMiddleware::class,
            SecurityHeadersMiddleware::class,
            CorsMiddleware::class,
            Pipeline::ERROR_HANDLING,
            JsonBodyParserMiddleware::class,
            Pipeline::ROUTING,
            SessionMiddleware::class,
            OriginCsrfMiddleware::class,
            AuthenticationMiddleware::class,
            RateLimitMiddleware::class,
            IdempotencyMiddleware::class,
        ], Pipeline::definition());
    }

    public function testFallbackResponseUsesTheStandardErrorFormatAndHeaders(): void
    {
        $response = FallbackResponse::build('01HF7YAT00ABCDEFGHJKMNPQRS');

        self::assertSame(500, $response['status']);
        self::assertSame('nosniff', $response['headers']['X-Content-Type-Options']);
        self::assertSame('no-store', $response['headers']['Cache-Control']);
        self::assertSame('01HF7YAT00ABCDEFGHJKMNPQRS', $response['headers']['X-Request-Id']);
        self::assertSame(
            ['error' => [
                'code' => 'internal_error',
                'message' => 'Something went wrong. Please try again later.',
                'details' => [],
                'request_id' => '01HF7YAT00ABCDEFGHJKMNPQRS',
            ]],
            json_decode($response['body'], true, 8, JSON_THROW_ON_ERROR),
        );
        self::assertTrue(UlidGenerator::isValid(FallbackResponse::build()['headers']['X-Request-Id']));
    }
}
