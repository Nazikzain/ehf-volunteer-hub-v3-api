<?php

declare(strict_types=1);

namespace Ehf\Api\Http\Middleware;

use Ehf\Api\Authorisation\Principal;
use Ehf\Api\Http\Error\ApiException;
use Ehf\Api\Http\Error\ErrorCode;
use Ehf\Api\Http\Error\ErrorDetail;
use Ehf\Api\Http\RequestAttribute;
use Ehf\Api\Http\Response\JsonResponder;
use Ehf\Api\Http\RouteAccess;
use Ehf\Api\Infrastructure\Database\Database;
use Ehf\Api\Infrastructure\Database\Row;
use Ehf\Api\Infrastructure\Time\Clock;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Routing\RouteContext;

/**
 * Repeat protection for create requests (API §4.1; Workflow §2.2, REC; `idempotency_keys`, D4).
 * With an `Idempotency-Key` header, a successful response is kept for 24 hours: the same key with
 * the same body returns the same response; with a different body, `422 idempotency_mismatch`.
 */
final readonly class IdempotencyMiddleware implements MiddlewareInterface
{
    public const string HEADER = 'Idempotency-Key';

    private const int KEEP_HOURS = 24;

    public function __construct(
        private Database $db,
        private Clock $clock,
        private ResponseFactoryInterface $responses,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $route = RouteContext::fromRequest($request)->getRoute();
        $principal = $request->getAttribute(RequestAttribute::PRINCIPAL);
        $key = $request->getHeaderLine(self::HEADER);
        if ($route === null || $route->getArgument(RouteAccess::IDEMPOTENT) !== '1' || !$principal instanceof Principal || $key === '') {
            return $handler->handle($request);
        }

        if (preg_match('/^[A-Za-z0-9._:-]{1,100}$/', $key) !== 1) {
            throw new ApiException(ErrorCode::ValidationFailed, details: [new ErrorDetail(self::HEADER, 'format', 'Must be 1 to 100 letters, digits or . _ : -')]);
        }

        $endpoint = $request->getMethod() . ' ' . $request->getUri()->getPath();
        $bodyHash = hash('sha256', (string) json_encode($request->getParsedBody()));
        $now = Database::timestamp($this->clock->now());

        $this->db->execute('DELETE FROM idempotency_keys WHERE user_id = ? AND idem_key = ? AND expires_at <= ?', [$principal->userId, $key, $now]);
        $stored = $this->db->one('SELECT endpoint, body_hash, response_status, response_body FROM idempotency_keys WHERE user_id = ? AND idem_key = ?', [$principal->userId, $key]);
        if ($stored !== null) {
            if (Row::string($stored, 'endpoint') !== $endpoint || !hash_equals(Row::string($stored, 'body_hash'), $bodyHash)) {
                throw new ApiException(ErrorCode::IdempotencyMismatch);
            }

            $response = $this->responses->createResponse(Row::int($stored, 'response_status'))->withHeader('Content-Type', JsonResponder::CONTENT_TYPE);
            $response->getBody()->write(Row::stringOrNull($stored, 'response_body') ?? '');

            return $response;
        }

        $response = $handler->handle($request);
        if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
            $this->db->execute(
                'INSERT IGNORE INTO idempotency_keys (user_id, idem_key, endpoint, body_hash, response_status, response_body, created_at, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$principal->userId, $key, $endpoint, $bodyHash, $response->getStatusCode(), (string) $response->getBody(), $now,
                    Database::timestamp($this->clock->now()->modify('+' . self::KEEP_HOURS . ' hours'))],
            );
        }

        return $response;
    }
}
