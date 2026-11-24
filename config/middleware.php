<?php

declare(strict_types=1);

/**
 * The request pipeline, outermost first, in the order of API V1.3.1 §2.12.
 *
 * Central error handling sits inside CORS and security headers, so error responses carry the
 * same request ID, security and CORS headers as successful ones. Routing runs before the
 * session step so that the authentication and rate-limit steps can read each route's declared
 * permission (config/routes.php).
 */

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

return [
    RequestIdMiddleware::class,          // 1. request ID
    SecurityHeadersMiddleware::class,    // 2. security headers
    CorsMiddleware::class,               // 3. CORS (exact allowlist)
    Pipeline::ERROR_HANDLING,            //    central error handling (§2.6, §2.12 step 9)
    JsonBodyParserMiddleware::class,     // 4. JSON parsing
    Pipeline::ROUTING,                   //    route resolution (the controller runs innermost)
    SessionMiddleware::class,            // 5. session
    OriginCsrfMiddleware::class,         // 6. Origin and CSRF check
    AuthenticationMiddleware::class,     // 7. authentication and the route's permission
    RateLimitMiddleware::class,          // 8. rate limiting
    IdempotencyMiddleware::class,        //    repeat protection for creates (§4.1)
];
