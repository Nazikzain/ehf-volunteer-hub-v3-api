<?php

declare(strict_types=1);

namespace Ehf\Api\Http\Error;

use Ehf\Api\Http\Response\JsonResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Slim\Exception\HttpException;
use Slim\Exception\HttpMethodNotAllowedException;
use Slim\Exception\HttpNotFoundException;
use Slim\Interfaces\ErrorHandlerInterface;

/**
 * Central error handling (API §2.6, §2.12 step 9, §9).
 *
 * Every error becomes the standard error body. Stack traces, SQL, file paths and exception
 * messages of unexpected errors are never returned; they are logged with the request ID.
 *
 * An unknown path and an unsupported method on a known path both return `404 not_found`:
 * API §4.3 defines no 405 code, and §4.4 prefers not revealing what exists.
 */
final readonly class JsonErrorHandler implements ErrorHandlerInterface
{
    public function __construct(
        private JsonResponder $responder,
        private LoggerInterface $logger,
    ) {}

    public function __invoke(
        ServerRequestInterface $request,
        \Throwable $exception,
        bool $displayErrorDetails,
        bool $logErrors,
        bool $logErrorDetails,
    ): ResponseInterface {
        $apiException = $this->toApiException($exception);
        $requestId = $this->responder->requestId($request);

        if ($apiException->errorCode === ErrorCode::InternalError) {
            $this->logger->error('Unhandled exception', [
                'request_id' => $requestId,
                'method' => $request->getMethod(),
                'path' => $request->getUri()->getPath(),
                'exception' => $exception,
            ]);
        } else {
            $this->logger->info('Request refused', [
                'request_id' => $requestId,
                'method' => $request->getMethod(),
                'path' => $request->getUri()->getPath(),
                'code' => $apiException->errorCode->value,
            ]);
        }

        return $this->responder->error($request, $apiException);
    }

    private function toApiException(\Throwable $exception): ApiException
    {
        if ($exception instanceof ApiException) {
            return $exception;
        }

        if ($exception instanceof HttpNotFoundException || $exception instanceof HttpMethodNotAllowedException) {
            return new ApiException(ErrorCode::NotFound, previous: $exception);
        }

        if ($exception instanceof HttpException) {
            $code = match ($exception->getCode()) {
                400 => ErrorCode::MalformedJson,
                401 => ErrorCode::Unauthenticated,
                403 => ErrorCode::Forbidden,
                404 => ErrorCode::NotFound,
                413 => ErrorCode::PayloadTooLarge,
                415 => ErrorCode::UnsupportedMediaType,
                429 => ErrorCode::RateLimited,
                503 => ErrorCode::Maintenance,
                default => ErrorCode::InternalError,
            };

            return new ApiException($code, previous: $exception);
        }

        return new ApiException(ErrorCode::InternalError, previous: $exception);
    }
}
