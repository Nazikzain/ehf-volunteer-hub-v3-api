<?php

declare(strict_types=1);

namespace Ehf\Api\Tests\Integration;

use Ehf\Api\Tests\Support\IntegrationTestCase;

/**
 * Accounts, ordinary roles, grants and sessions (API §7.2), including the field-leak check that
 * account responses carry account data only.
 */
final class AccountAndAccessTest extends IntegrationTestCase
{
    private const array ACCOUNT_FIELDS = ['created_at', 'display_name', 'email', 'id', 'invitation_status', 'last_login_at', 'roles', 'status', 'version', 'volunteer_reference'];

    public function testAccountLifecycle(): void
    {
        $tm = $this->login('technical_manager');

        $created = $this->call('POST', '/api/v3/users', $tm, ['display_name' => 'New Staff', 'email' => 'New.Staff@Example.TEST']);
        self::assertSame(201, $created->getStatusCode());
        $user = self::data($created);
        self::assertSame('new.staff@example.test', $user['email']);
        self::assertSame('invited', $user['status']);
        self::assertSame('duplicate', self::errorCode($this->call('POST', '/api/v3/users', $tm, ['display_name' => 'Again', 'email' => 'new.staff@example.test'])));
        self::assertSame('unknown_field', self::errorCode($this->call('POST', '/api/v3/users', $tm, ['display_name' => 'X', 'email' => 'x@example.test', 'status' => 'active'])));

        $id = self::int($user['id']);
        $edited = $this->call('PATCH', "/api/v3/users/{$id}", $tm, ['display_name' => 'Renamed Staff', 'version' => 1]);
        self::assertSame('Renamed Staff', self::data($edited)['display_name']);
        self::assertSame('stale_version', self::errorCode($this->call('PATCH', "/api/v3/users/{$id}", $tm, ['display_name' => 'Lost Update', 'version' => 1])));
        self::assertSame('field_not_editable', self::errorCode($this->call('PATCH', "/api/v3/users/{$id}", $tm, ['email' => 'other@example.test', 'version' => 2])));

        self::assertSame(204, $this->call('DELETE', "/api/v3/users/{$id}/invitation", $tm, [])->getStatusCode());
        self::assertSame('invalid_transition', self::errorCode($this->call('POST', "/api/v3/users/{$id}/suspend", $tm, ['reason' => 'x', 'version' => 2])), 'Invited accounts cannot be suspended.');
        $deactivated = self::data($this->call('POST', "/api/v3/users/{$id}/deactivate", $tm, ['reason' => 'Never started', 'version' => 2]));
        self::assertSame('deactivated', $deactivated['status']);
        self::assertSame('invalid_transition', self::errorCode($this->call('POST', "/api/v3/users/{$id}/reactivate", $tm, ['version' => 3])), 'Deactivated accounts are not reactivated.');

        $active = self::createUser('pausable@example.test', 'Pausable');
        self::assertSame('suspended', self::data($this->call('POST', "/api/v3/users/{$active}/suspend", $tm, ['reason' => 'Leave', 'version' => 1]))['status']);
        self::assertSame('active', self::data($this->call('POST', "/api/v3/users/{$active}/reactivate", $tm, ['version' => 2]))['status']);

        $own = (int) self::db()->scalar('SELECT version FROM users WHERE id = ?', self::$users['technical_manager']);
        self::assertSame('forbidden', self::errorCode($this->call('POST', '/api/v3/users/' . self::$users['technical_manager'] . '/suspend', $tm, ['reason' => 'x', 'version' => $own])), 'Not one\'s own account (WF-16).');

        foreach (['account.created', 'account.updated', 'invitation.cancelled', 'account.deactivated', 'account.suspended', 'account.reactivated'] as $event) {
            self::assertGreaterThan(0, self::auditCount($event, 'success'), $event);
        }
    }

    public function testAccountResponsesContainAccountDataOnly(): void
    {
        $tm = $this->login('technical_manager');
        $linked = self::createUser('linked@example.test', 'Linked');
        self::db()->insert("INSERT INTO volunteers (reference, user_id, full_name, email, phone, status, skills) VALUES ('V-000777', ?, 'Linked Volunteer', 'private@example.test', '07700 900000', 'active', 'Secret skills')", [$linked]);
        self::db()->insert("INSERT INTO volunteer_sensitive_details (volunteer_id, address_line1, nationality) VALUES ((SELECT id FROM volunteers WHERE user_id = ?), '1 Hidden Street', 'Hidden')", [$linked]);

        $detail = self::data($this->call('GET', "/api/v3/users/{$linked}", $tm));
        $keys = array_keys($detail);
        sort($keys);
        self::assertSame(self::ACCOUNT_FIELDS, $keys);
        self::assertSame('V-000777', $detail['volunteer_reference']);

        $list = (string) $this->call('GET', '/api/v3/users?q=linked', $tm)->getBody();
        foreach (['private@example.test', '07700', 'Secret skills', 'Hidden Street', 'password_hash', 'session_version', '$argon2'] as $leak) {
            self::assertStringNotContainsString($leak, $list);
            self::assertStringNotContainsString($leak, json_encode($detail, JSON_THROW_ON_ERROR));
        }
    }

    public function testOrdinaryRoleGuards(): void
    {
        $tm = $this->login('technical_manager');
        $person = self::createUser('role-person@example.test', 'Role Person');
        $personSession = $this->login('x', 'role-person@example.test');

        self::assertSame('self_grant_forbidden', self::errorCode($this->call('POST', '/api/v3/users/' . self::$users['technical_manager'] . '/roles', $tm, ['role' => 'full_admin', 'reason' => 'x'])));
        self::assertSame('forbidden', self::errorCode($this->call('POST', "/api/v3/users/{$person}/roles", $this->login('owner'), ['role' => 'full_admin', 'reason' => 'x'])), 'System Owner membership carries no technical codes.');

        $assigned = $this->call('POST', "/api/v3/users/{$person}/roles", $tm, ['role' => 'monitoring_admin', 'reason' => 'New monitor']);
        self::assertSame(201, $assigned->getStatusCode(), (string) $assigned->getBody());
        self::assertFalse(self::data($this->call('GET', '/api/v3/auth/session', $personSession))['authenticated'], 'Role changes end sessions.');
        self::assertSame('combination_not_allowed', self::errorCode($this->call('POST', "/api/v3/users/{$person}/roles", $tm, ['role' => 'full_admin', 'reason' => 'x'])));

        $roles = self::items($this->call('GET', "/api/v3/users/{$person}/roles", $tm));
        self::assertCount(1, $roles);
        self::assertSame('monitoring_admin', $roles[0]['role']);
        $roleId = self::int($roles[0]['id']);
        self::assertSame(204, $this->call('DELETE', "/api/v3/users/{$person}/roles/{$roleId}", $tm, ['reason' => 'Done'])->getStatusCode());
        self::assertSame('invalid_transition', self::errorCode($this->call('DELETE', "/api/v3/users/{$person}/roles/{$roleId}", $tm, ['reason' => 'Again'])));

        $tmAssignment = (int) self::db()->scalar("SELECT id FROM user_roles WHERE user_id = ? AND role_code = 'technical_manager' AND revoked_at IS NULL", self::$users['technical_manager']);
        self::assertSame('owner_authority_required', self::errorCode($this->call('DELETE', '/api/v3/users/' . self::$users['technical_manager'] . "/roles/{$tmAssignment}", $tm, ['reason' => 'x'])));

        // G5: no operational role for a Technical Manager holder.
        $tm2 = self::createUser('tm2@example.test', 'TM Two');
        self::grantTechnicalManager($tm2, self::membershipId(self::$users['owner']), self::$users['owner']);
        self::assertSame('use_operational_exception', self::errorCode($this->call('POST', "/api/v3/users/{$tm2}/roles", $tm, ['role' => 'full_admin', 'reason' => 'x'])));
        self::assertGreaterThan(0, self::auditCount('role.assigned', 'denied'));
    }

    public function testGrantGuardsAndEffect(): void
    {
        $tm = $this->login('technical_manager');
        $person = self::createUser('grantee@example.test', 'Grantee');
        $grant = static fn(string $code): array => ['permission' => $code, 'reason' => 'Temporary need', 'expires_at' => '2026-11-24T12:00:00Z'];

        self::assertSame('owner_authority_required', self::errorCode($this->call('POST', "/api/v3/users/{$person}/grants", $tm, $grant('owners.add'))), 'G8');
        self::assertSame('self_grant_forbidden', self::errorCode($this->call('POST', '/api/v3/users/' . self::$users['technical_manager'] . '/grants', $tm, $grant('accounts.view'))), 'G3');
        $tm2 = self::createUser('tm3@example.test', 'TM Three');
        self::grantTechnicalManager($tm2, self::membershipId(self::$users['owner']), self::$users['owner']);
        self::assertSame('use_operational_exception', self::errorCode($this->call('POST', "/api/v3/users/{$tm2}/grants", $tm, $grant('hours.view'))), 'G5');
        self::assertSame('validation_failed', self::errorCode($this->call('POST', "/api/v3/users/{$person}/grants", $tm, $grant('no.such.code'))));

        $created = $this->call('POST', "/api/v3/users/{$person}/grants", $tm, $grant('hours.view'));
        self::assertSame(201, $created->getStatusCode(), (string) $created->getBody());
        self::assertSame('duplicate', self::errorCode($this->call('POST', "/api/v3/users/{$person}/grants", $tm, $grant('hours.view'))));

        $session = $this->login('x', 'grantee@example.test');
        self::assertSame('organisation', $this->permissions($session)['hours.view'] ?? null);
        self::$clock->advance('+4 hours');
        $session = $this->login('x', 'grantee@example.test');
        self::assertArrayNotHasKey('hours.view', $this->permissions($session), 'Expired grants stop counting.');
        self::$clock->advance('-4 hours');

        $grantId = self::int(self::data($created)['id']);
        self::assertSame(204, $this->call('DELETE', "/api/v3/users/{$person}/grants/{$grantId}", $tm, ['reason' => 'Done'])->getStatusCode());
        self::assertSame(1, self::auditCount('permission.revoked', 'success'));
    }

    public function testSessionAdministration(): void
    {
        $tm = $this->login('technical_manager');
        $user = self::createUser('watched@example.test', 'Watched');
        $watched = $this->login('x', 'watched@example.test');

        $sessions = self::items($this->call('GET', "/api/v3/sessions?user_id={$user}", $tm));
        self::assertCount(1, $sessions);
        $sessionId = self::string($sessions[0]['id']);
        self::assertSame(hash('sha256', $watched->id), $sessionId, 'Only the hash identifies a session.');
        self::assertArrayNotHasKey('csrf_hash', $sessions[0]);

        self::assertSame(204, $this->call('DELETE', '/api/v3/sessions/' . $sessionId, $tm, [])->getStatusCode());
        self::assertFalse(self::data($this->call('GET', '/api/v3/auth/session', $watched))['authenticated']);
        self::assertSame('not_found', self::errorCode($this->call('DELETE', '/api/v3/sessions/' . $sessionId, $tm, [])));

        $again = $this->login('x', 'watched@example.test');
        self::assertSame(204, $this->call('POST', "/api/v3/users/{$user}/sessions/revoke-all", $tm, ['reason' => 'Lost phone'])->getStatusCode());
        self::assertFalse(self::data($this->call('GET', '/api/v3/auth/session', $again))['authenticated']);
    }
}
