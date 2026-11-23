<?php

declare(strict_types=1);

namespace Ehf\Api\Tests\Unit\Bootstrap;

use Ehf\Api\Bootstrap\FallbackResponse;
use Ehf\Api\Bootstrap\Pipeline;
use Ehf\Api\Http\Middleware\CorsMiddleware;
use Ehf\Api\Http\Middleware\JsonBodyParserMiddleware;
use Ehf\Api\Http\Middleware\RequestIdMiddleware;
use Ehf\Api\Http\Middleware\SecurityHeadersMiddleware;
use Ehf\Api\Infrastructure\Id\UlidGenerator;
use PHPUnit\Framework\TestCase;

final class PipelineTest extends TestCase
{
    public function testPipelineFollowsApiSection212Order(): void
    {
        // API V1.3.1 §2.12: request ID → security headers → CORS → JSON parsing → (session →
        // Origin/CSRF → authentication → rate limiting: Phase 4). Error handling wraps the
        // inner steps; routing is innermost.
        self::assertSame([
            RequestIdMiddleware::class,
            SecurityHeadersMiddleware::class,
            CorsMiddleware::class,
            Pipeline::ERROR_HANDLING,
            JsonBodyParserMiddleware::class,
            Pipeline::ROUTING,
        ], Pipeline::definition());
    }

    public function testPhase4StepsAreDocumentedInTheirPlace(): void
    {
        $source = (string) file_get_contents(\dirname(__DIR__, 3) . '/config/middleware.php');

        $positions = [];
        foreach (['JsonBodyParserMiddleware::class', '5. session', '6. Origin and CSRF', '7. authentication', '8. rate limiting', 'Pipeline::ROUTING'] as $needle) {
            $position = strpos($source, $needle);
            self::assertIsInt($position, $needle);
            $positions[] = $position;
        }

        $sorted = $positions;
        sort($sorted);
        self::assertSame($sorted, $positions);
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
