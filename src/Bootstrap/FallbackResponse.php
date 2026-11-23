<?php

declare(strict_types=1);

namespace Ehf\Api\Bootstrap;

use Ehf\Api\Http\Error\ErrorCode;
use Ehf\Api\Http\Response\JsonResponder;
use Ehf\Api\Infrastructure\Id\UlidGenerator;

/**
 * Last-resort response when the application cannot start (for example invalid configuration)
 * or fails outside the middleware pipeline. Same error format and security headers as
 * normal responses; never any detail about the cause.
 */
final class FallbackResponse
{
    private function __construct() {}

    /**
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    public static function build(?string $requestId = null): array
    {
        $requestId ??= (new UlidGenerator())->generate();
        $code = ErrorCode::InternalError;

        return [
            'status' => $code->httpStatus(),
            'headers' => [
                'Content-Type' => JsonResponder::CONTENT_TYPE,
                'X-Request-Id' => $requestId,
                'Content-Security-Policy' => "default-src 'none'; frame-ancestors 'none'",
                'X-Content-Type-Options' => 'nosniff',
                'Referrer-Policy' => 'no-referrer',
                'Cache-Control' => 'no-store',
            ],
            'body' => json_encode([
                'error' => [
                    'code' => $code->value,
                    'message' => $code->defaultMessage(),
                    'details' => [],
                    'request_id' => $requestId,
                ],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        ];
    }

    public static function emit(): void
    {
        $response = self::build();
        if (!headers_sent()) {
            http_response_code($response['status']);
            header_remove();
            foreach ($response['headers'] as $name => $value) {
                header($name . ': ' . $value);
            }
        }

        echo $response['body'];
    }
}
