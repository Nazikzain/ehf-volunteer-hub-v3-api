<?php

declare(strict_types=1);

namespace Ehf\Api\Http\Controllers;

use Ehf\Api\Audit\AuditContext;
use Ehf\Api\Authorisation\Principal;
use Ehf\Api\Http\Response\JsonResponder;
use Ehf\Api\Services\GovernanceService;
use Ehf\Api\Validation\RequestInput;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * API §7.17 — System Owners, the Technical Manager role and operational exceptions. Owner actions
 * are audited with acting role `system_owner`. `POST /system-owners` is not available (R7).
 */
final readonly class GovernanceController
{
    use ControllerSupport;

    private const array EXCEPTION_STATUSES = ['pending', 'authorised', 'rejected', 'revoked', 'expired'];

    public function __construct(
        private GovernanceService $governance,
        private JsonResponder $responder,
    ) {}

    public function owners(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->success($request, $this->governance->owners());
    }

    /**
     * @param array<string, string> $args
     */
    public function removeOwner(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $input = RequestInput::body($request, ['reason', 'password_confirmation']);
        $reason = $input->string('reason', 1, 500);
        $password = $input->string('password_confirmation', 1, 1024, false);
        $input->validate();

        $this->governance->removeOwner(self::principal($request), self::id($args, 'userId'), $reason, $password, $this->context($request));

        return $this->responder->noContent();
    }

    public function grantTechnicalManager(ServerRequestInterface $request): ResponseInterface
    {
        $input = RequestInput::body($request, ['user_id', 'reason', 'password_confirmation']);
        $userId = $input->int('user_id');
        $reason = $input->string('reason', 1, 500);
        $password = $input->string('password_confirmation', 1, 1024, false);
        $input->validate();

        return $this->responder->success($request, $this->governance->grantTechnicalManager(self::principal($request), $userId, $reason, $password, $this->context($request)), 201);
    }

    /**
     * @param array<string, string> $args
     */
    public function revokeTechnicalManager(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $input = RequestInput::body($request, ['reason', 'password_confirmation']);
        $reason = $input->string('reason', 1, 500);
        $password = $input->string('password_confirmation', 1, 1024, false);
        $input->validate();

        $this->governance->revokeTechnicalManager(self::principal($request), self::id($args, 'assignmentId'), $reason, $password, $this->context($request));

        return $this->responder->noContent();
    }

    public function exceptions(ServerRequestInterface $request): ResponseInterface
    {
        $query = RequestInput::query($request, ['status', 'user_id']);
        $status = $query->oneOf('status', self::EXCEPTION_STATUSES, false);
        $userId = $query->optionalInt('user_id');
        $query->validate();

        return $this->responder->success($request, $this->governance->exceptions($status, $userId));
    }

    public function createException(ServerRequestInterface $request): ResponseInterface
    {
        $input = RequestInput::body($request, ['recipient_user_id', 'permission', 'scope', 'project_id', 'reason', 'expires_at']);
        $recipient = $input->int('recipient_user_id');
        $permission = $input->string('permission', 1, 100);
        $scope = $input->oneOf('scope', ['organisation', 'project'], false) ?? 'organisation';
        $project = $input->optionalInt('project_id');
        $reason = $input->string('reason', 1, 1000);
        $expiresAt = $input->optionalTimestamp('expires_at');
        $input->validate();

        return $this->responder->success($request, $this->governance->createException(self::principal($request), $recipient, $permission, $scope, $project, $reason, $expiresAt, $this->context($request)), 201);
    }

    /**
     * @param array<string, string> $args
     */
    public function authoriseException(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $input = RequestInput::body($request, ['password_confirmation', 'note', 'version']);
        $password = $input->string('password_confirmation', 1, 1024, false);
        $input->optionalString('note', 1, 1000);
        $version = $input->int('version');
        $input->validate();

        return $this->responder->success($request, $this->governance->authoriseException(self::principal($request), self::id($args, 'id'), $version, $password, $this->context($request)));
    }

    /**
     * @param array<string, string> $args
     */
    public function rejectException(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        [$reason, $version] = $this->reasonAndVersion($request);

        return $this->responder->success($request, $this->governance->rejectException(self::principal($request), self::id($args, 'id'), $version, $reason, $this->context($request)));
    }

    /**
     * @param array<string, string> $args
     */
    public function revokeException(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        [$reason, $version] = $this->reasonAndVersion($request);

        return $this->responder->success($request, $this->governance->revokeException(self::principal($request), self::id($args, 'id'), $version, $reason, $this->context($request)));
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function reasonAndVersion(ServerRequestInterface $request): array
    {
        $input = RequestInput::body($request, ['reason', 'version']);
        $reason = $input->string('reason', 1, 1000);
        $version = $input->int('version');
        $input->validate();

        return [$reason, $version];
    }

    private function context(ServerRequestInterface $request): AuditContext
    {
        return AuditContext::fromRequest($request, Principal::SYSTEM_OWNER);
    }
}
