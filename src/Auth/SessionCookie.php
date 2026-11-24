<?php

declare(strict_types=1);

namespace Ehf\Api\Auth;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The session cookie: `__Host-` prefix (so it is Secure, Path=/ and host-only), HttpOnly, and the
 * SameSite value from configuration (A3, pending).
 */
final readonly class SessionCookie
{
    public const string NAME = '__Host-ehf_session';

    public function __construct(private AuthFeature $feature) {}

    public static function read(ServerRequestInterface $request): ?string
    {
        $cookies = $request->getCookieParams();
        $value = $cookies[self::NAME] ?? null;
        if (!\is_string($value)) {
            foreach (explode(';', $request->getHeaderLine('Cookie')) as $pair) {
                [$name, $cookie] = array_pad(explode('=', trim($pair), 2), 2, '');
                if ($name === self::NAME) {
                    $value = $cookie;
                }
            }
        }

        return \is_string($value) && preg_match('/^[A-Za-z0-9_-]{43}$/', $value) === 1 ? $value : null;
    }

    public function set(ResponseInterface $response, string $sessionId): ResponseInterface
    {
        return $response->withAddedHeader('Set-Cookie', \sprintf(
            '%s=%s; Path=/; Secure; HttpOnly; SameSite=%s; Max-Age=%d',
            self::NAME,
            $sessionId,
            $this->feature->settings()->sameSite,
            $this->feature->settings()->sessionAbsoluteMinutes * 60,
        ));
    }

    public function clear(ResponseInterface $response): ResponseInterface
    {
        return $response->withAddedHeader('Set-Cookie', \sprintf(
            '%s=; Path=/; Secure; HttpOnly; SameSite=%s; Max-Age=0',
            self::NAME,
            $this->feature->settings()->sameSite,
        ));
    }
}
