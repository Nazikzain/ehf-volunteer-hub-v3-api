<?php

declare(strict_types=1);

namespace Ehf\Api\Http\Controllers;

use Ehf\Api\Http\Response\JsonResponder;
use Ehf\Api\Services\HealthService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `GET /api/v3/health` — permission: public (API Appendix A.1). No audit event.
 */
final readonly class HealthController
{
    public function __construct(
        private HealthService $health,
        private JsonResponder $responder,
    ) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->responder->success($request, $this->health->status());
    }
}
