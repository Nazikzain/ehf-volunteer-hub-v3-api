<?php

declare(strict_types=1);

/**
 * Routes layer (API §2.11). Every route is under /api/v3, declares exactly one primary permission
 * code (API Appendix A) and the audit event its refusals are recorded under, and must appear in
 * openapi/openapi.yaml with the same x-permission (checked by tests/Contract).
 *
 * Not registered, because an unresolved decision blocks them (docs/phase-4-decisions.md):
 *   POST /users/{id}/invitation, POST /users/{id}/password-reset, POST /auth/password/forgot
 *     — sending email needs A11 (and token lifetimes, A5);
 *   POST /system-owners — owner eligibility needs R7.
 * Phase 5 adds volunteer records (§7.4), portal provisioning (§7.2) and /me/profile (§7.3).
 * Not yet: other /me/* endpoints, settings and the business endpoints of Phases 6–11.
 */

use Ehf\Api\Http\Controllers\AccessController;
use Ehf\Api\Http\Controllers\AuditController;
use Ehf\Api\Http\Controllers\AuthController;
use Ehf\Api\Http\Controllers\GovernanceController;
use Ehf\Api\Http\Controllers\HealthController;
use Ehf\Api\Http\Controllers\ProfileController;
use Ehf\Api\Http\Controllers\ProvisioningController;
use Ehf\Api\Http\Controllers\SystemController;
use Ehf\Api\Http\Controllers\UserController;
use Ehf\Api\Http\Controllers\VolunteerController;
use Ehf\Api\Http\RouteAccess;
use Slim\App;
use Slim\Interfaces\RouteInterface;
use Slim\Routing\RouteCollectorProxy;

return static function (App $app): void {
    $access = static function (RouteInterface $route, string $permission, string $audit = '', bool $idempotent = false, bool $tokenLimit = false): void {
        $route->setArgument(RouteAccess::PERMISSION, $permission);
        $route->setArgument(RouteAccess::AUDIT, $audit);
        if ($idempotent) {
            $route->setArgument(RouteAccess::IDEMPOTENT, '1');
        }
        if ($tokenLimit) {
            $route->setArgument(RouteAccess::RATE_LIMIT, RouteAccess::RATE_LIMIT_TOKEN);
        }
    };

    $app->group('/api/v3', function (RouteCollectorProxy $v3) use ($access): void { // not static: Slim binds group closures
        // System (API §7.1, §7.19)
        $access($v3->get('/health', HealthController::class)->setName('health'), RouteAccess::PUBLIC);
        $access($v3->get('/system/health', [SystemController::class, 'health']), 'system.health');

        // Authentication (API §7.1)
        $access($v3->get('/auth/session', [AuthController::class, 'session']), RouteAccess::PUBLIC);
        $access($v3->post('/auth/login', [AuthController::class, 'login']), RouteAccess::PUBLIC, 'auth.login.succeeded');
        $access($v3->post('/auth/logout', [AuthController::class, 'logout']), RouteAccess::SIGNED_IN, 'auth.logout');
        $access($v3->post('/auth/password/change', [AuthController::class, 'changePassword']), RouteAccess::SIGNED_IN, 'auth.password.changed');
        $access($v3->post('/auth/invitations/inspect', [AuthController::class, 'inspectInvitation']), RouteAccess::PUBLIC, '', false, true);
        $access($v3->post('/auth/activate', [AuthController::class, 'activate']), RouteAccess::PUBLIC, 'account.activated', false, true);
        $access($v3->post('/auth/password/reset/inspect', [AuthController::class, 'inspectReset']), RouteAccess::PUBLIC, '', false, true);
        $access($v3->post('/auth/password/reset', [AuthController::class, 'reset']), RouteAccess::PUBLIC, 'auth.reset.completed', false, true);

        // Accounts (API §7.2)
        $access($v3->get('/users', [UserController::class, 'list']), 'accounts.view');
        $access($v3->post('/users', [UserController::class, 'create']), 'accounts.create', 'account.created', true);
        $access($v3->get('/users/{id:[0-9]+}', [UserController::class, 'show']), 'accounts.view');
        $access($v3->patch('/users/{id:[0-9]+}', [UserController::class, 'update']), 'accounts.edit', 'account.updated');
        $access($v3->delete('/users/{id:[0-9]+}/invitation', [UserController::class, 'cancelInvitation']), 'invitations.cancel', 'invitation.cancelled');
        $access($v3->post('/users/{id:[0-9]+}/suspend', [UserController::class, 'suspend']), 'accounts.suspend', 'account.suspended');
        $access($v3->post('/users/{id:[0-9]+}/reactivate', [UserController::class, 'reactivate']), 'accounts.reactivate', 'account.reactivated');
        $access($v3->post('/users/{id:[0-9]+}/deactivate', [UserController::class, 'deactivate']), 'accounts.deactivate', 'account.deactivated');

        // Portal provisioning (API §7.2; W1, AC-6, R18)
        $access($v3->get('/portal-provisioning/candidates', [ProvisioningController::class, 'candidates']), 'accounts.provision');
        $access($v3->post('/volunteers/{id:[0-9]+}/portal-account', [ProvisioningController::class, 'provision']), 'accounts.provision', 'account.provisioned', true);

        // Own profile (API §7.3; R12)
        $access($v3->get('/me/profile', [ProfileController::class, 'show']), 'self.profile.view');
        $access($v3->patch('/me/profile', [ProfileController::class, 'update']), 'self.profile.edit', 'volunteer.self_updated');

        // Volunteer records and confidential notes (API §7.4; W1, R6)
        $access($v3->get('/volunteers', [VolunteerController::class, 'list']), 'volunteers.browse');
        $access($v3->post('/volunteers', [VolunteerController::class, 'create']), 'volunteers.create', 'volunteer.created', true);
        $access($v3->get('/volunteers/{id:[0-9]+}', [VolunteerController::class, 'show']), 'volunteers.view');
        $access($v3->patch('/volunteers/{id:[0-9]+}', [VolunteerController::class, 'update']), 'volunteers.edit', 'volunteer.updated');
        $access($v3->post('/volunteers/{id:[0-9]+}/accept', [VolunteerController::class, 'accept']), 'volunteers.accept', 'volunteer.accepted');
        $access($v3->post('/volunteers/{id:[0-9]+}/status-changes', [VolunteerController::class, 'changeStatus']), 'volunteers.status.change', 'volunteer.status_changed');
        $access($v3->get('/volunteers/{id:[0-9]+}/status-history', [VolunteerController::class, 'history']), 'volunteers.view');
        $access($v3->get('/volunteers/{id:[0-9]+}/notes', [VolunteerController::class, 'notes']), 'confidential.volunteer_notes.view', 'note.viewed');
        $access($v3->post('/volunteers/{id:[0-9]+}/notes', [VolunteerController::class, 'addNote']), 'confidential.volunteer_notes.create', 'note.created', true);

        // Roles, grants, catalogues and sessions (API §7.2)
        $access($v3->get('/users/{id:[0-9]+}/roles', [AccessController::class, 'roles']), 'roles.view');
        $access($v3->post('/users/{id:[0-9]+}/roles', [AccessController::class, 'assignRole']), 'roles.assign_ordinary', 'role.assigned', true);
        $access($v3->delete('/users/{id:[0-9]+}/roles/{assignmentId:[0-9]+}', [AccessController::class, 'removeRole']), 'roles.remove_ordinary', 'role.removed');
        $access($v3->get('/users/{id:[0-9]+}/grants', [AccessController::class, 'grants']), 'grants.view');
        $access($v3->post('/users/{id:[0-9]+}/grants', [AccessController::class, 'createGrant']), 'grants.create', 'permission.granted', true);
        $access($v3->delete('/users/{id:[0-9]+}/grants/{grantId:[0-9]+}', [AccessController::class, 'revokeGrant']), 'grants.revoke', 'permission.revoked');
        $access($v3->get('/roles', [AccessController::class, 'roleCatalogue']), 'roles.view');
        $access($v3->get('/permissions', [AccessController::class, 'permissionCatalogue']), 'roles.view');
        $access($v3->get('/sessions', [AccessController::class, 'sessions']), 'sessions.view');
        $access($v3->delete('/sessions/{id:[a-f0-9]{64}}', [AccessController::class, 'revokeSession']), 'sessions.revoke', 'session.revoked');
        $access($v3->post('/users/{id:[0-9]+}/sessions/revoke-all', [AccessController::class, 'revokeAllSessions']), 'sessions.revoke', 'session.revoked');

        // System Owner governance (API §7.17)
        $access($v3->get('/system-owners', [GovernanceController::class, 'owners']), 'owners.view');
        $access($v3->delete('/system-owners/{userId:[0-9]+}', [GovernanceController::class, 'removeOwner']), 'owners.remove', 'owner.removed');
        $access($v3->post('/system-owners/technical-manager-assignments', [GovernanceController::class, 'grantTechnicalManager']), 'roles.technical_manager.grant', 'role.technical_manager.granted', true);
        $access($v3->delete('/system-owners/technical-manager-assignments/{assignmentId:[0-9]+}', [GovernanceController::class, 'revokeTechnicalManager']), 'roles.technical_manager.revoke', 'role.technical_manager.revoked');
        $access($v3->get('/operational-exceptions', [GovernanceController::class, 'exceptions']), 'exceptions.view');
        $access($v3->post('/operational-exceptions', [GovernanceController::class, 'createException']), 'exceptions.create', 'exception.created', true);
        $access($v3->post('/operational-exceptions/{id:[0-9]+}/authorise', [GovernanceController::class, 'authoriseException']), 'exceptions.authorise', 'exception.authorised');
        $access($v3->post('/operational-exceptions/{id:[0-9]+}/reject', [GovernanceController::class, 'rejectException']), 'exceptions.reject', 'exception.rejected');
        $access($v3->post('/operational-exceptions/{id:[0-9]+}/revoke', [GovernanceController::class, 'revokeException']), 'exceptions.revoke', 'exception.revoked');

        // Audit (API §7.18)
        $access($v3->get('/audit-logs/security', [AuditController::class, 'security']), 'audit.security.view');
        $access($v3->get('/audit-logs/operational', [AuditController::class, 'operational']), 'audit.operational.view');
        $access($v3->get('/audit-logs/verify', [AuditController::class, 'verify']), 'audit.verify');
        $access($v3->get('/audit-logs/{id:[0-9]+}', [AuditController::class, 'show']), RouteAccess::SERVICE_CHECKED);
    });
};
