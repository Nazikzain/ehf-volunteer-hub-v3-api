<?php

declare(strict_types=1);

namespace Ehf\Api\Tests\Support;

use Ehf\Api\Auth\SessionCookie;
use Psr\Http\Message\ResponseInterface;

/**
 * A signed-in test client: the session cookie value and the CSRF token returned with it.
 */
final readonly class TestSession
{
    public function __construct(
        public string $id,
        public string $csrf,
        public string $setCookie,
    ) {}

    public static function fromResponse(ResponseInterface $response): self
    {
        $cookie = '';
        foreach ($response->getHeader('Set-Cookie') as $header) {
            if (str_starts_with($header, SessionCookie::NAME . '=')) {
                $cookie = $header;
            }
        }
        if (preg_match('/^' . preg_quote(SessionCookie::NAME, '/') . '=([A-Za-z0-9_-]{43});/', $cookie, $m) !== 1) {
            throw new \UnexpectedValueException('No session cookie in the response.');
        }

        $body = TestApp::json($response);
        $data = $body['data'] ?? [];
        $csrf = \is_array($data) && \is_string($data['csrf_token'] ?? null) ? $data['csrf_token'] : '';

        return new self($m[1], $csrf, $cookie);
    }
}
