<?php

declare(strict_types=1);

namespace Ehf\Api\Http\Controllers;

use Ehf\Api\Http\Response\JsonResponder;
use Ehf\Api\Infrastructure\Database\Database;
use Ehf\Api\Services\AuditQueryService;
use Ehf\Api\Validation\RequestInput;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * API §7.18 — audit logs. Read-only: no endpoint edits or deletes audit records [A].
 */
final readonly class AuditController
{
    use ControllerSupport;

    private const array FILTERS = ['actor', 'action', 'resource_type', 'resource_id', 'outcome', 'from', 'to', 'page', 'per_page'];

    public function __construct(
        private AuditQueryService $audit,
        private JsonResponder $responder,
    ) {}

    public function security(ServerRequestInterface $request): ResponseInterface
    {
        return $this->search($request, ['security', 'technical']);
    }

    public function operational(ServerRequestInterface $request): ResponseInterface
    {
        return $this->search($request, ['operational']);
    }

    /**
     * @param array<string, string> $args
     */
    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->responder->success($request, $this->audit->get(self::id($args, 'id'), self::principal($request)));
    }

    public function verify(ServerRequestInterface $request): ResponseInterface
    {
        $query = RequestInput::query($request, ['from', 'to']);
        $from = $query->optionalTimestamp('from');
        $to = $query->optionalTimestamp('to');
        $query->validate();

        return $this->responder->success($request, $this->audit->verify(
            $from === null ? null : Database::timestamp($from),
            $to === null ? null : Database::timestamp($to),
        ));
    }

    /**
     * @param list<string> $categories
     */
    private function search(ServerRequestInterface $request, array $categories): ResponseInterface
    {
        $query = RequestInput::query($request, self::FILTERS);
        $filters = [
            'actor' => $query->optionalInt('actor'),
            'action' => $query->optionalString('action', 1, 100),
            'resource_type' => $query->optionalString('resource_type', 1, 60),
            'resource_id' => $query->optionalInt('resource_id'),
            'outcome' => $query->oneOf('outcome', ['success', 'denied'], false),
        ];
        $from = $query->optionalTimestamp('from');
        $to = $query->optionalTimestamp('to');
        [$page, $perPage] = self::paging($query);
        $query->validate();
        $filters['from'] = $from === null ? null : Database::timestamp($from);
        $filters['to'] = $to === null ? null : Database::timestamp($to);

        $result = $this->audit->search($categories, $filters, $page, $perPage);

        return $this->responder->success($request, $result['items'], 200, self::pageMeta($page, $perPage, $result['total']));
    }
}
