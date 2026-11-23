<?php

declare(strict_types=1);

namespace Ehf\Api\Http\Middleware;

use Ehf\Api\Config\Settings;
use Ehf\Api\Http\Error\ErrorCode;
use Ehf\Api\Http\Response\JsonResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Pipeline step 3 (API §2.12, §5): CORS with an exact per-environment allowlist,
 * credentials allowed, no wildcards.
 *
 * - Allowed origin: the response names that exact origin and allows credentials.
 * - Preflight from an allowed origin: answered here with 204.
 * - Preflight from any other origin: `403 origin_not_allowed`.
 * - Simple request from any other origin: no CORS headers, so the browser withholds the
 *   response. Server-side refusal of state-changing requests by Origin is the
 *   Origin + CSRF step, which arrives with sessions in Phase 4.
 */
final readonly class CorsMiddleware implements MiddlewareInterface
{
    public const array ALLOWED_METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];

    /**
     * Request headers the API defines (API §4.1, §5). `X-Acting-Role` is reserved for
     * multiple roles (R2, pending) and is not accepted until R2 is approved.
     */
    public const array ALLOWED_HEADERS = ['Content-Type', 'X-CSRF-Token', 'Idempotency-Key'];

    public const array EXPOSED_HEADERS = ['X-Request-Id', 'Retry-After'];

    public const int PREFLIGHT_MAX_AGE = 600;

    public function __construct(
        private Settings $settings,
        private JsonResponder $responder,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $origin = $request->getHeaderLine('Origin');
        if ($origin === '') {
            return $handler->handle($request);
        }

        $allowed = \in_array($origin, $this->settings->corsAllowedOrigins, true);

        if ($this->isPreflight($request)) {
            if (!$allowed) {
                return $this->vary($this->responder->errorBody(
                    $this->responder->requestId($request),
                    ErrorCode::OriginNotAllowed,
                ));
            }

            return $this->withCorsHeaders($this->responder->noContent(), $origin)
                ->withHeader('Access-Control-Allow-Methods', implode(', ', self::ALLOWED_METHODS))
                ->withHeader('Access-Control-Allow-Headers', implode(', ', self::ALLOWED_HEADERS))
                ->withHeader('Access-Control-Max-Age', (string) self::PREFLIGHT_MAX_AGE);
        }

        $response = $handler->handle($request);

        return $allowed ? $this->withCorsHeaders($response, $origin) : $this->vary($response);
    }

    private function isPreflight(ServerRequestInterface $request): bool
    {
        return strtoupper($request->getMethod()) === 'OPTIONS'
            && $request->hasHeader('Access-Control-Request-Method');
    }

    private function withCorsHeaders(ResponseInterface $response, string $origin): ResponseInterface
    {
        return $this->vary($response)
            ->withHeader('Access-Control-Allow-Origin', $origin)
            ->withHeader('Access-Control-Allow-Credentials', 'true')
            ->withHeader('Access-Control-Expose-Headers', implode(', ', self::EXPOSED_HEADERS));
    }

    private function vary(ResponseInterface $response): ResponseInterface
    {
        return $response->withAddedHeader('Vary', 'Origin');
    }
}
