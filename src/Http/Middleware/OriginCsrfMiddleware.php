<?php

declare(strict_types=1);

namespace Ehf\Api\Http\Middleware;

use Ehf\Api\Authorisation\Principal;
use Ehf\Api\Config\Settings;
use Ehf\Api\Http\Error\ApiException;
use Ehf\Api\Http\Error\ErrorCode;
use Ehf\Api\Http\RequestAttribute;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Pipeline step 6 (API §2.12, §5): Origin and CSRF protection for every state-changing request
 * (POST, PUT, PATCH, DELETE).
 *
 * - The Origin header must be one of the exact allowed origins (`403 origin_not_allowed`); a
 *   missing Origin is refused too (fail closed).
 * - Signed in: X-CSRF-Token must equal the session's CSRF token (`403 csrf_invalid`).
 * - Before sign-in (login, activation, reset): the allowed Origin plus a JSON body (checked by the
 *   JSON parser) are required.
 */
final readonly class OriginCsrfMiddleware implements MiddlewareInterface
{
    private const array UNSAFE = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function __construct(private Settings $settings) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!\in_array(strtoupper($request->getMethod()), self::UNSAFE, true)) {
            return $handler->handle($request);
        }

        if (!\in_array($request->getHeaderLine('Origin'), $this->settings->corsAllowedOrigins, true)) {
            throw new ApiException(ErrorCode::OriginNotAllowed);
        }

        $principal = $request->getAttribute(RequestAttribute::PRINCIPAL);
        if ($principal instanceof Principal && !hash_equals($principal->csrfToken, $request->getHeaderLine('X-CSRF-Token'))) {
            throw new ApiException(ErrorCode::CsrfInvalid);
        }

        return $handler->handle($request);
    }
}
