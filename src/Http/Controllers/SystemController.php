<?php

declare(strict_types=1);

namespace Ehf\Api\Http\Controllers;

use Ehf\Api\Http\Response\JsonResponder;
use Ehf\Api\Services\SystemHealthService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * API §7.19 — technical health for Technical Managers.
 */
final readonly class SystemController
{
    public function __construct(
        private SystemHealthService $health,
        private JsonResponder $responder,
    ) {}

    public function health(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->success($request, $this->health->report());
    }
}
