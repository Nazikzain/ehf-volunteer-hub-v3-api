<?php

declare(strict_types=1);

namespace Ehf\Api\Http\Middleware;

use Ehf\Api\Http\RequestAttribute;
use Ehf\Api\Infrastructure\Id\RequestIdGenerator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Pipeline step 1 (API §2.12): assigns a request ID, exposes it to every later layer
 * and returns it in the `X-Request-Id` response header.
 *
 * A client-supplied `X-Request-Id` is ignored: the ID is always server-generated, so it
 * can be trusted in logs and audit records.
 */
final readonly class RequestIdMiddleware implements MiddlewareInterface
{
    public const string HEADER = 'X-Request-Id';

    public function __construct(private RequestIdGenerator $generator) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $requestId = $this->generator->generate();

        $response = $handler->handle($request->withAttribute(RequestAttribute::REQUEST_ID, $requestId));

        return $response->withHeader(self::HEADER, $requestId);
    }
}
