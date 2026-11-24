<?php

declare(strict_types=1);

namespace Ehf\Api\Http\Controllers;

use Ehf\Api\Audit\AuditContext;
use Ehf\Api\Http\Error\ApiException;
use Ehf\Api\Http\Error\ErrorCode;
use Ehf\Api\Http\Response\JsonResponder;
use Ehf\Api\Services\AccessService;
use Ehf\Api\Validation\RequestInput;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * API §7.2 — roles, grants, the role/permission catalogues and sessions.
 */
final readonly class AccessController
{
    use ControllerSupport;

    public function __construct(
        private AccessService $access,
        private JsonResponder $responder,
    ) {}

    public function roleCatalogue(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->success($request, $this->access->roleCatalogue());
    }

    public function permissionCatalogue(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->success($request, $this->access->permissionCatalogue());
    }

    /**
     * @param array<string, string> $args
     */
    public function roles(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->responder->success($request, $this->access->roles(self::id($args, 'id')));
    }

    /**
     * @param array<string, string> $args
     */
    public function assignRole(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $input = RequestInput::body($request, ['role', 'scope', 'project_id', 'reason']);
        $role = $input->string('role', 1, 40);
        $scope = $input->oneOf('scope', ['organisation', 'project'], false) ?? 'organisation';
        $project = $input->optionalInt('project_id');
        $reason = $input->string('reason', 1, 500);
        $input->validate();

        return $this->responder->success($request, $this->access->assignRole(self::principal($request), self::id($args, 'id'), $role, $scope, $project, $reason, AuditContext::fromRequest($request)), 201);
    }

    /**
     * @param array<string, string> $args
     */
    public function removeRole(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $this->access->removeRole(self::principal($request), self::id($args, 'id'), self::id($args, 'assignmentId'), $this->reason($request), AuditContext::fromRequest($request));

        return $this->responder->noContent();
    }

    /**
     * @param array<string, string> $args
     */
    public function grants(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->responder->success($request, $this->access->grants(self::id($args, 'id')));
    }

    /**
     * @param array<string, string> $args
     */
    public function createGrant(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $input = RequestInput::body($request, ['permission', 'scope', 'project_id', 'reason', 'expires_at']);
        $permission = $input->string('permission', 1, 100);
        $scope = $input->oneOf('scope', ['organisation', 'project'], false) ?? 'organisation';
        $project = $input->optionalInt('project_id');
        $reason = $input->string('reason', 1, 500);
        $expiresAt = $input->optionalTimestamp('expires_at');
        $input->validate();

        return $this->responder->success($request, $this->access->createGrant(self::principal($request), self::id($args, 'id'), $permission, $scope, $project, $reason, $expiresAt, AuditContext::fromRequest($request)), 201);
    }

    /**
     * @param array<string, string> $args
     */
    public function revokeGrant(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $this->access->revokeGrant(self::principal($request), self::id($args, 'id'), self::id($args, 'grantId'), $this->reason($request), AuditContext::fromRequest($request));

        return $this->responder->noContent();
    }

    public function sessions(ServerRequestInterface $request): ResponseInterface
    {
        $query = RequestInput::query($request, ['user_id']);
        $userId = $query->optionalInt('user_id');
        $query->validate();

        return $this->responder->success($request, $this->access->sessions($userId));
    }

    /**
     * @param array<string, string> $args
     */
    public function revokeSession(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = $args['id'] ?? '';
        if (preg_match('/^[a-f0-9]{64}$/', $id) !== 1) {
            throw new ApiException(ErrorCode::NotFound);
        }
        $this->optionalReason($request);
        $this->access->revokeSession($id, AuditContext::fromRequest($request));

        return $this->responder->noContent();
    }

    /**
     * @param array<string, string> $args
     */
    public function revokeAllSessions(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $this->optionalReason($request);
        $this->access->revokeAllSessions(self::id($args, 'id'), AuditContext::fromRequest($request));

        return $this->responder->noContent();
    }

    private function reason(ServerRequestInterface $request): string
    {
        $input = RequestInput::body($request, ['reason']);
        $reason = $input->string('reason', 1, 500);
        $input->validate();

        return $reason;
    }

    private function optionalReason(ServerRequestInterface $request): void
    {
        $input = RequestInput::body($request, ['reason']);
        $input->optionalString('reason', 1, 500);
        $input->validate();
    }
}
