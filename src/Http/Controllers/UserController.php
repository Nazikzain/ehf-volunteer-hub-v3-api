<?php

declare(strict_types=1);

namespace Ehf\Api\Http\Controllers;

use Ehf\Api\Audit\AuditContext;
use Ehf\Api\Http\Response\JsonResponder;
use Ehf\Api\Services\AccountService;
use Ehf\Api\Validation\RequestInput;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * API §7.2 — staff and portal accounts (account data only).
 */
final readonly class UserController
{
    use ControllerSupport;

    private const array STATUSES = ['invited', 'active', 'suspended', 'deactivated'];

    private const array ROLES = ['volunteer', 'full_admin', 'monitoring_admin', 'technical_manager'];

    public function __construct(
        private AccountService $accounts,
        private JsonResponder $responder,
    ) {}

    public function list(ServerRequestInterface $request): ResponseInterface
    {
        $query = RequestInput::query($request, ['q', 'status', 'role', 'page', 'per_page']);
        $q = $query->optionalString('q', 1, 100);
        $status = $query->oneOf('status', self::STATUSES, false);
        $role = $query->oneOf('role', self::ROLES, false);
        [$page, $perPage] = self::paging($query);
        $query->validate();

        $result = $this->accounts->search($q, $status, $role, $page, $perPage);

        return $this->responder->success($request, $result['items'], 200, self::pageMeta($page, $perPage, $result['total']));
    }

    /**
     * @param array<string, string> $args
     */
    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->responder->success($request, $this->accounts->get(self::id($args, 'id')));
    }

    public function create(ServerRequestInterface $request): ResponseInterface
    {
        $input = RequestInput::body($request, ['display_name', 'email']);
        $displayName = $input->string('display_name', 1, 120);
        $email = $input->email('email');
        $input->validate();

        return $this->responder->success($request, $this->accounts->create($email, $displayName, AuditContext::fromRequest($request), self::principal($request)), 201);
    }

    /**
     * @param array<string, string> $args
     */
    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $input = RequestInput::body($request, ['display_name', 'email', 'version']);
        $displayName = $input->optionalString('display_name', 1, 120);
        $version = $input->int('version');
        $input->validate();

        return $this->responder->success($request, $this->accounts->update(self::id($args, 'id'), $displayName, $input->has('email'), $version, AuditContext::fromRequest($request), self::principal($request)));
    }

    /**
     * @param array<string, string> $args
     */
    public function suspend(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        [$reason, $version] = $this->reasonAndVersion($request, true);

        return $this->responder->success($request, $this->accounts->suspend(self::id($args, 'id'), (string) $reason, $version, AuditContext::fromRequest($request), self::principal($request)));
    }

    /**
     * @param array<string, string> $args
     */
    public function reactivate(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        [$reason, $version] = $this->reasonAndVersion($request, false);

        return $this->responder->success($request, $this->accounts->reactivate(self::id($args, 'id'), $reason, $version, AuditContext::fromRequest($request), self::principal($request)));
    }

    /**
     * @param array<string, string> $args
     */
    public function deactivate(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        [$reason, $version] = $this->reasonAndVersion($request, true);

        return $this->responder->success($request, $this->accounts->deactivate(self::id($args, 'id'), (string) $reason, $version, AuditContext::fromRequest($request), self::principal($request)));
    }

    /**
     * @param array<string, string> $args
     */
    public function cancelInvitation(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $input = RequestInput::body($request, ['reason']);
        $input->optionalString('reason', 1, 500);
        $input->validate();
        $this->accounts->cancelInvitation(self::id($args, 'id'), AuditContext::fromRequest($request));

        return $this->responder->noContent();
    }

    /**
     * @return array{0: ?string, 1: int}
     */
    private function reasonAndVersion(ServerRequestInterface $request, bool $reasonRequired): array
    {
        $input = RequestInput::body($request, ['reason', 'version']);
        $reason = $input->optionalString('reason', 1, 500, true, $reasonRequired);
        $version = $input->int('version');
        $input->validate();

        return [$reason, $version];
    }
}
