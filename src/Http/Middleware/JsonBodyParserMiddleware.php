<?php

declare(strict_types=1);

namespace Ehf\Api\Http\Middleware;

use Ehf\Api\Config\Settings;
use Ehf\Api\Http\Error\ApiException;
use Ehf\Api\Http\Error\ErrorCode;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Pipeline step 4 (API §2.12, §4.1, §9): JSON request parsing.
 *
 * - Bodies of POST, PUT, PATCH and DELETE must be `application/json` (UTF-8), except
 *   `multipart/form-data`, which is passed through untouched for file uploads (Phase 7).
 * - The body must be a single JSON object; anything else is `400 malformed_json`.
 * - Bodies larger than MAX_JSON_BODY_BYTES are refused with `413 payload_too_large`.
 * - Rejecting unknown fields is endpoint-specific and done by each endpoint's validator
 *   (see Validation\InputValidator), because only the endpoint knows its accepted fields.
 */
final readonly class JsonBodyParserMiddleware implements MiddlewareInterface
{
    private const array BODY_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    private const int MAX_DEPTH = 64;

    public function __construct(private Settings $settings) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!\in_array(strtoupper($request->getMethod()), self::BODY_METHODS, true)) {
            return $handler->handle($request);
        }

        $declaredLength = $request->getHeaderLine('Content-Length');
        if (ctype_digit($declaredLength) && (int) $declaredLength > $this->settings->maxJsonBodyBytes) {
            throw new ApiException(ErrorCode::PayloadTooLarge);
        }

        [$mediaType, $charset] = $this->contentType($request->getHeaderLine('Content-Type'));

        if ($mediaType === 'multipart/form-data') {
            return $handler->handle($request);
        }

        $body = $this->readBody($request);
        if ($body === '') {
            return $handler->handle($request);
        }

        if ($mediaType !== 'application/json' || ($charset !== null && $charset !== 'utf-8')) {
            throw new ApiException(ErrorCode::UnsupportedMediaType, 'Send the request body as application/json (UTF-8).');
        }

        try {
            $decoded = json_decode($body, false, self::MAX_DEPTH, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException) {
            throw new ApiException(ErrorCode::MalformedJson);
        }

        if (!$decoded instanceof \stdClass) {
            throw new ApiException(ErrorCode::MalformedJson, 'The request body must be a JSON object.');
        }

        /** @var array<string, mixed> $parsed */
        $parsed = json_decode($body, true, self::MAX_DEPTH, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);

        return $handler->handle($request->withParsedBody($parsed));
    }

    /**
     * @return array{0: string, 1: ?string} media type and charset, lowercased
     */
    private function contentType(string $header): array
    {
        $parts = explode(';', $header);
        $mediaType = strtolower(trim($parts[0]));
        $charset = null;

        foreach (\array_slice($parts, 1) as $parameter) {
            [$name, $value] = array_pad(explode('=', $parameter, 2), 2, '');
            if (strtolower(trim($name)) === 'charset') {
                $charset = strtolower(trim($value, " \t\""));
            }
        }

        return [$mediaType, $charset];
    }

    private function readBody(ServerRequestInterface $request): string
    {
        $stream = $request->getBody();
        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        $limit = $this->settings->maxJsonBodyBytes;
        $body = '';
        while (!$stream->eof()) {
            $body .= $stream->read(8192);
            if (\strlen($body) > $limit) {
                throw new ApiException(ErrorCode::PayloadTooLarge);
            }
        }

        return $body;
    }
}
