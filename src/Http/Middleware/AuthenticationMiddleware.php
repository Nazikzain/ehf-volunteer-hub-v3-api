<?php

declare(strict_types=1);

namespace Ehf\Api\Http\Middleware;

use Ehf\Api\Audit\AuditContext;
use Ehf\Api\Audit\AuditLogger;
use Ehf\Api\Authorisation\Principal;
use Ehf\Api\Http\Error\ApiException;
use Ehf\Api\Http\Error\ErrorCode;
use Ehf\Api\Http\RequestAttribute;
use Ehf\Api\Http\RouteAccess;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Routing\RouteContext;

/**
 * Pipeline step 7 (API §2.12, §6.2): every route declares exactly one primary permission code
 * (RouteAccess). Public routes pass; otherwise the caller must be signed in (`401`) and hold the
 * code (`403 forbidden`). Scope, guards and field rules are applied by the services.
 * A route without a declaration is a programming error (500), never an open door.
 */
final readonly class AuthenticationMiddleware implements MiddlewareInterface
{
    public function __construct(private AuditLogger $audit) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $route = RouteContext::fromRequest($request)->getRoute();
        if ($route === null) {
            return $handler->handle($request);
        }

        $permission = $route->getArgument(RouteAccess::PERMISSION);
        if ($permission === null || $permission === '') {
            throw new \LogicException('Route ' . $route->getPattern() . ' declares no permission.');
        }
        if ($permission === RouteAccess::PUBLIC) {
            return $handler->handle($request);
        }

        $principal = $request->getAttribute(RequestAttribute::PRINCIPAL);
        if (!$principal instanceof Principal) {
            throw new ApiException($request->getAttribute(RequestAttribute::SESSION_STATE) === 'expired' ? ErrorCode::SessionExpired : ErrorCode::Unauthenticated);
        }

        if ($permission !== RouteAccess::SIGNED_IN && $permission !== RouteAccess::SERVICE_CHECKED && !$principal->can($permission)) {
            $event = $route->getArgument(RouteAccess::AUDIT);
            if ($event !== null && $event !== '') {
                $this->audit->denied(AuditContext::fromRequest($request), $event, null, null, ['reason' => 'forbidden', 'permission' => $permission]);
            }

            throw new ApiException(ErrorCode::Forbidden);
        }

        return $handler->handle($request);
    }
}
