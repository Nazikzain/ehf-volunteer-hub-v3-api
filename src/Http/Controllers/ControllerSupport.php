<?php

declare(strict_types=1);

namespace Ehf\Api\Http\Controllers;

use Ehf\Api\Authorisation\Principal;
use Ehf\Api\Http\Error\ApiException;
use Ehf\Api\Http\Error\ErrorCode;
use Ehf\Api\Http\RequestAttribute;
use Ehf\Api\Validation\RequestInput;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Small helpers shared by controllers. Controllers only translate HTTP to service calls.
 */
trait ControllerSupport
{
    private static function principal(ServerRequestInterface $request): Principal
    {
        $principal = $request->getAttribute(RequestAttribute::PRINCIPAL);

        return $principal instanceof Principal ? $principal : throw new ApiException(ErrorCode::Unauthenticated);
    }

    /**
     * Route placeholder → positive integer, or 404.
     *
     * @param array<string, string> $args
     */
    private static function id(array $args, string $name): int
    {
        $value = $args[$name] ?? '';

        return preg_match('/^[1-9]\d{0,18}$/', $value) === 1 ? (int) $value : throw new ApiException(ErrorCode::NotFound);
    }

    /**
     * @return array{0: int, 1: int} page and per_page (API §4.1: default 25, maximum 100)
     */
    private static function paging(RequestInput $query): array
    {
        return [$query->optionalInt('page') ?? 1, $query->optionalInt('per_page', 1, false, 100) ?? 25];
    }

    /**
     * @return array{page: int, per_page: int, total: int, total_pages: int}
     */
    private static function pageMeta(int $page, int $perPage, int $total): array
    {
        return ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'total_pages' => (int) ceil($total / $perPage)];
    }

    private static function agent(ServerRequestInterface $request): string
    {
        return mb_substr($request->getHeaderLine('User-Agent'), 0, 120);
    }

    private static function ip(ServerRequestInterface $request): string
    {
        $ip = $request->getServerParams()['REMOTE_ADDR'] ?? '';

        return \is_string($ip) ? $ip : '';
    }
}
