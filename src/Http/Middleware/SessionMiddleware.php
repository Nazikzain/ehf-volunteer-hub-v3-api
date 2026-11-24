<?php

declare(strict_types=1);

namespace Ehf\Api\Http\Middleware;

use Ehf\Api\Auth\AuthFeature;
use Ehf\Api\Auth\SessionCookie;
use Ehf\Api\Auth\SessionService;
use Ehf\Api\Http\RequestAttribute;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Pipeline step 5 (API §2.12): resolves the session cookie to the signed-in Principal.
 * An invalid or expired session is treated as signed out and its cookie is cleared.
 * While sign-in is not configured (A3–A7 pending), no session is accepted.
 */
final readonly class SessionMiddleware implements MiddlewareInterface
{
    public function __construct(
        private AuthFeature $feature,
        private SessionService $sessions,
        private SessionCookie $cookie,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $sessionId = $this->feature->enabled() ? SessionCookie::read($request) : null;
        if ($sessionId === null) {
            return $handler->handle($request->withAttribute(RequestAttribute::SESSION_STATE, 'none'));
        }

        $principal = $this->sessions->resolve($sessionId);
        if ($principal === null) {
            $response = $handler->handle($request->withAttribute(RequestAttribute::SESSION_STATE, 'expired'));

            return $response->hasHeader('Set-Cookie') ? $response : $this->cookie->clear($response);
        }

        return $handler->handle($request
            ->withAttribute(RequestAttribute::SESSION_STATE, 'valid')
            ->withAttribute(RequestAttribute::SESSION_ID, $sessionId)
            ->withAttribute(RequestAttribute::PRINCIPAL, $principal));
    }
}
