<?php

declare(strict_types=1);

/**
 * The request pipeline, outermost first, in the order of API V1.3.1 §2.12.
 *
 * Phase 2 registers steps 1–4 plus central error handling and routing. Steps 5–8 depend on
 * sessions, accounts and pending Phase 4 decisions (A3–A7, RS4 …); they are listed here so
 * the order is fixed now, and they are added in Phase 4. Until then no route needs them:
 * the only route is the public health check.
 *
 * Central error handling sits inside CORS and security headers, so error responses carry
 * the same request ID, security and CORS headers as successful ones.
 */

use Ehf\Api\Bootstrap\Pipeline;
use Ehf\Api\Http\Middleware\CorsMiddleware;
use Ehf\Api\Http\Middleware\JsonBodyParserMiddleware;
use Ehf\Api\Http\Middleware\RequestIdMiddleware;
use Ehf\Api\Http\Middleware\SecurityHeadersMiddleware;

return [
    RequestIdMiddleware::class,          // 1. request ID
    SecurityHeadersMiddleware::class,    // 2. security headers
    CorsMiddleware::class,               // 3. CORS (exact allowlist)
    Pipeline::ERROR_HANDLING,            //    central error handling (§2.6, §2.12 step 9)
    JsonBodyParserMiddleware::class,     // 4. JSON parsing
    // 5. session                — Phase 4 (A3, A4, D4)
    // 6. Origin and CSRF check  — Phase 4
    // 7. authentication         — Phase 4
    // 8. rate limiting          — Phase 4 (A7)
    Pipeline::ROUTING,                   //    route resolution, then the controller
];
