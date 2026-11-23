<?php

declare(strict_types=1);

namespace Ehf\Api\Http\Response;

use Ehf\Api\Http\Error\ApiException;
use Ehf\Api\Http\Error\ErrorCode;
use Ehf\Api\Http\Error\ErrorDetail;
use Ehf\Api\Http\RequestAttribute;
use Ehf\Api\Infrastructure\Id\RequestIdGenerator;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Builds every JSON response in the standard formats of API V1.3.1 §4.2 and §4.3.
 *
 * Success: {"data": …, "meta": {"request_id": …, …}}
 * Error:   {"error": {"code", "message", "details", "request_id"}}
 */
final readonly class JsonResponder
{
    public const string CONTENT_TYPE = 'application/json; charset=utf-8';

    private const int JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;

    public function __construct(
        private ResponseFactoryInterface $responseFactory,
        private RequestIdGenerator $requestIds,
    ) {}

    /**
     * @param array<string, mixed>|list<mixed> $data
     * @param array<string, mixed>             $meta additional meta entries (request_id is always set)
     */
    public function success(ServerRequestInterface $request, array $data, int $status = 200, array $meta = []): ResponseInterface
    {
        $body = [
            'data' => $data,
            'meta' => ['request_id' => $this->requestId($request)] + $meta,
        ];

        return $this->json($body, $status);
    }

    public function error(ServerRequestInterface $request, ApiException $exception): ResponseInterface
    {
        $response = $this->errorBody(
            $this->requestId($request),
            $exception->errorCode,
            $exception->getMessage(),
            $exception->details,
        );

        foreach ($exception->headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }

    /**
     * @param list<ErrorDetail> $details
     */
    public function errorBody(string $requestId, ErrorCode $code, ?string $message = null, array $details = []): ResponseInterface
    {
        $body = [
            'error' => [
                'code' => $code->value,
                'message' => $message ?? $code->defaultMessage(),
                'details' => array_map(static fn(ErrorDetail $detail): array => $detail->toArray(), $details),
                'request_id' => $requestId,
            ],
        ];

        return $this->json($body, $code->httpStatus());
    }

    public function noContent(int $status = 204): ResponseInterface
    {
        return $this->responseFactory->createResponse($status);
    }

    public function requestId(ServerRequestInterface $request): string
    {
        $id = $request->getAttribute(RequestAttribute::REQUEST_ID);

        return \is_string($id) && $id !== '' ? $id : $this->requestIds->generate();
    }

    /**
     * @param array<string, mixed> $body
     */
    private function json(array $body, int $status): ResponseInterface
    {
        $response = $this->responseFactory->createResponse($status)
            ->withHeader('Content-Type', self::CONTENT_TYPE);
        $response->getBody()->write(json_encode($body, self::JSON_FLAGS));

        return $response;
    }
}
