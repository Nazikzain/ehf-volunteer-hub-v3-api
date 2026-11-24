<?php

declare(strict_types=1);

namespace Ehf\Api\Http\Middleware;

use Ehf\Api\Auth\AuthFeature;
use Ehf\Api\Auth\RateLimiter;
use Ehf\Api\Http\RouteAccess;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Routing\RouteContext;

/**
 * Pipeline step 8 (API §2.12, §5): per-IP limit on the token endpoints (invitation and reset
 * checks, activation, reset), using the A7 values. Sign-in has its own per-account and per-IP
 * failure limits inside AuthService, because they count failures only.
 */
final readonly class RateLimitMiddleware implements MiddlewareInterface
{
    public function __construct(
        private AuthFeature $feature,
        private RateLimiter $limiter,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $route = RouteContext::fromRequest($request)->getRoute();
        if ($route === null || $route->getArgument(RouteAccess::RATE_LIMIT) !== RouteAccess::RATE_LIMIT_TOKEN || !$this->feature->enabled()) {
            return $handler->handle($request);
        }

        $settings = $this->feature->settings();
        $ip = $request->getServerParams()['REMOTE_ADDR'] ?? '';
        $key = 'token:ip:' . (\is_string($ip) ? $ip : '');
        $this->limiter->assertBelow($key, $settings->tokenIpMax, $settings->tokenWindowMinutes);
        $this->limiter->hit($key, $settings->tokenWindowMinutes);

        return $handler->handle($request);
    }
}
