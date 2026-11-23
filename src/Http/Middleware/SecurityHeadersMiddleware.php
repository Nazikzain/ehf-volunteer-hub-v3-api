<?php

declare(strict_types=1);

namespace Ehf\Api\Http\Middleware;

use Ehf\Api\Config\Settings;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Pipeline step 2 (API §2.12): security headers on every response, including errors
 * (API §15.2).
 */
final readonly class SecurityHeadersMiddleware implements MiddlewareInterface
{
    /** One year, sent only when HSTS is enabled (always in staging and production). */
    public const int HSTS_MAX_AGE = 31_536_000;

    public function __construct(private Settings $settings) {}

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        $headers = [
            'Content-Security-Policy' => "default-src 'none'; frame-ancestors 'none'",
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
            // API responses may contain personal data; nothing is cached (API §15.2).
            'Cache-Control' => 'no-store',
        ];

        if ($this->settings->hstsEnabled) {
            $headers['Strict-Transport-Security'] = 'max-age=' . self::HSTS_MAX_AGE . '; includeSubDomains';
        }

        return $headers;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        foreach ($this->headers() as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response->withoutHeader('X-Powered-By');
    }
}
