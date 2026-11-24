<?php

declare(strict_types=1);

namespace Ehf\Api\Tests\Integration;

use Ehf\Api\Tests\Support\IntegrationTestCase;

/**
 * System Owner and Technical Manager governance (API §6.8–6.10, §7.17; AR-13–AR-15).
 */
final class GovernanceTest extends IntegrationTestCase
{
    public function testOwnerGrantsAndRevokesTheTechnicalManagerRole(): void
    {
        $candidate = self::createUser('future-tm@example.test', 'Future TM');
        $candidateSession = $this->login('x', 'future-tm@example.test');
        $owner = $this->login('owner');

        $granted = $this->call('POST', '/api/v3/system-owners/technical-manager-assignments', $owner, ['user_id' => $candidate, 'reason' => 'Covering IT', 'password_confirmation' => self::PASSWORD]);
        self::assertSame(201, $granted->getStatusCode(), (string) $granted->getBody());
        $assignment = self::data($granted);
        self::assertSame('technical_manager', $assignment['role']);
        self::assertSame(self::$users['owner'], $assignment['authorised_by_owner']);

        $authorisation = self::db()->rows("SELECT kind, owner_user_id, subject_user_id, action, password_confirmed_at FROM owner_authorisations WHERE subject_user_id = ? AND action = 'grant_technical_manager'", [$candidate]);
        self::assertCount(1, $authorisation);
        self::assertSame(self::$users['owner'], (int) $authorisation[0]['owner_user_id']);
        self::assertNotNull($authorisation[0]['password_confirmed_at']);

        self::assertSame('system_owner', self::db()->scalar("SELECT acting_role FROM audit_logs WHERE action = 'role.technical_manager.granted' AND outcome = 'success' ORDER BY id DESC LIMIT 1"));
        self::assertFalse(self::data($this->call('GET', '/api/v3/auth/session', $candidateSession))['authenticated'], 'A role change ends the target\'s sessions.');

        $tm = $this->login('x', 'future-tm@example.test');
        self::assertSame(['technical_manager'], self::data($this->call('GET', '/api/v3/auth/session', $tm))['roles']);

        $assignmentId = self::int($assignment['id']);
        $revoked = $this->call('DELETE', '/api/v3/system-owners/technical-manager-assignments/' . $assignmentId, $owner, ['reason' => 'Cover ended', 'password_confirmation' => self::PASSWORD]);
        self::assertSame(204, $revoked->getStatusCode(), (string) $revoked->getBody());
        self::assertSame(1, (int) self::db()->scalar("SELECT COUNT(*) FROM owner_authorisations WHERE subject_user_id = ? AND action = 'revoke_technical_manager'", $candidate));
        self::assertFalse(self::data($this->call('GET', '/api/v3/auth/session', $tm))['authenticated']);
        self::assertSame('invalid_transition', self::errorCode($this->call('DELETE', '/api/v3/system-owners/technical-manager-assignments/' . $assignmentId, $owner, ['reason' => 'Again', 'password_confirmation' => self::PASSWORD])));
    }

    public function testGuardsOnTheTechnicalManagerRole(): void
    {
        $owner = $this->login('owner');
        $tm = $this->login('technical_manager');
        $grant = static fn(int $user): array => ['user_id' => $user, 'reason' => 'Test', 'password_confirmation' => self::PASSWORD];

        // G3: never for oneself.
        self::assertSame('self_grant_forbidden', self::errorCode($this->call('POST', '/api/v3/system-owners/technical-manager-assignments', $owner, $grant(self::$users['owner']))));
        // RS4 (stricter reading): the owner's password is required.
        self::assertSame('password_confirmation_required', self::errorCode($this->call('POST', '/api/v3/system-owners/technical-manager-assignments', $owner, ['password_confirmation' => 'not-the-password'] + $grant(self::$users['target2']))));
        // G10 (interim): one role per account.
        self::assertSame('combination_not_allowed', self::errorCode($this->call('POST', '/api/v3/system-owners/technical-manager-assignments', $owner, $grant(self::$users['full_admin']))));
        // G4: the Technical Manager role is never assignable through the ordinary endpoint.
        self::assertSame('owner_authority_required', self::errorCode($this->call('POST', '/api/v3/users/' . self::$users['target2'] . '/roles', $tm, ['role' => 'technical_manager', 'reason' => 'Test'])));
        // A Technical Manager has no governance codes at all.
        self::assertSame('forbidden', self::errorCode($this->call('POST', '/api/v3/system-owners/technical-manager-assignments', $tm, $grant(self::$users['target2']))));
        // Refused attempts are audited separately.
        self::assertGreaterThanOrEqual(3, self::auditCount('role.technical_manager.granted', 'denied'));
    }

    public function testAnOwnerWhoIsAlsoTechnicalManagerIsBlockedWhileR7CIsPending(): void
    {
        $dual = self::createUser('dual@example.test', 'Dual');
        $membership = self::db()->insert("INSERT INTO system_owner_memberships (user_id, added_via, added_by_membership_id, added_by_user_id, add_reason, active_key) VALUES (?, 'owner', ?, ?, 'Test', ?)", [$dual, self::membershipId(self::$users['owner']), self::$users['owner'], $dual]);
        self::grantTechnicalManager($dual, self::membershipId(self::$users['owner']), self::$users['owner']);
        $session = $this->login('x', 'dual@example.test');

        $response = $this->call('POST', '/api/v3/system-owners/technical-manager-assignments', $session, ['user_id' => self::$users['target2'], 'reason' => 'Test', 'password_confirmation' => self::PASSWORD]);
        self::assertSame(403, $response->getStatusCode());
        self::assertSame('forbidden', self::errorCode($response));
        self::assertStringContainsString('R7-C', (string) $response->getBody());
        self::assertGreaterThan(0, $membership);
    }

    public function testOwnerRemovalKeepsAFinalOwner(): void
    {
        $a = self::createUser('owner-a@example.test', 'Owner A');
        $b = self::createUser('owner-b@example.test', 'Owner B');
        $root = self::membershipId(self::$users['owner']);
        foreach ([$a, $b] as $user) {
            self::db()->insert("INSERT INTO system_owner_memberships (user_id, added_via, added_by_membership_id, added_by_user_id, add_reason, active_key) VALUES (?, 'owner', ?, ?, 'Test', ?)", [$user, $root, self::$users['owner'], $user]);
        }
        $sessionA = $this->login('x', 'owner-a@example.test');

        self::assertSame('self_grant_forbidden', self::errorCode($this->call('DELETE', "/api/v3/system-owners/{$a}", $sessionA, ['reason' => 'Test', 'password_confirmation' => self::PASSWORD])));
        self::assertSame(204, $this->call('DELETE', "/api/v3/system-owners/{$b}", $sessionA, ['reason' => 'Stepping down', 'password_confirmation' => self::PASSWORD])->getStatusCode());
        self::assertSame('not_found', self::errorCode($this->call('DELETE', "/api/v3/system-owners/{$b}", $sessionA, ['reason' => 'Again', 'password_confirmation' => self::PASSWORD])));
        self::assertSame(1, self::auditCount('owner.removed', 'success'));
    }

    public function testTheFinalOwnerCannotBeSuspendedOrDeactivated(): void
    {
        // Leave a single effective owner: deactivate every other owner account directly.
        $owners = array_map('intval', array_column(self::db()->rows('SELECT user_id FROM system_owner_memberships WHERE removed_at IS NULL'), 'user_id'));
        foreach ($owners as $id) {
            if ($id !== self::$users['owner']) {
                self::db()->insert("UPDATE users SET status = 'deactivated' WHERE id = ?", [$id]);
            }
        }
        $tm = $this->login('technical_manager');
        $version = (int) self::db()->scalar('SELECT version FROM users WHERE id = ?', self::$users['owner']);

        $suspend = $this->call('POST', '/api/v3/users/' . self::$users['owner'] . '/suspend', $tm, ['reason' => 'Test', 'version' => $version]);
        self::assertSame(403, $suspend->getStatusCode());
        self::assertSame('final_owner_protected', self::errorCode($suspend));
        self::assertSame('final_owner_protected', self::errorCode($this->call('POST', '/api/v3/users/' . self::$users['owner'] . '/deactivate', $tm, ['reason' => 'Test', 'version' => $version])));
        self::assertSame('active', self::db()->scalar('SELECT status FROM users WHERE id = ?', self::$users['owner']));
        self::assertGreaterThan(0, self::auditCount('account.suspended', 'denied'));

        // Restore the second owner so later tests in this class are unaffected.
        self::db()->insert("UPDATE users SET status = 'active' WHERE id = ?", [self::$users['owner2']]);
    }

    public function testExceptionsFollowTheirLifecycle(): void
    {
        $owner = $this->login('owner');
        $request = ['recipient_user_id' => self::$users['technical_manager'], 'permission' => 'hours.view', 'reason' => 'Investigating a reported problem'];

        // R19 (interim): no code is eligible, so every request is refused.
        $refused = $this->call('POST', '/api/v3/operational-exceptions', $owner, $request);
        self::assertSame('validation_failed', self::errorCode($refused));
        self::assertStringContainsString('R19', (string) $refused->getBody());

        // Make one code eligible in this test database only, to exercise the approved lifecycle.
        self::db()->insert("UPDATE permissions SET exception_eligible = 1 WHERE code = 'hours.view'");
        try {
            self::assertSame('validation_failed', self::errorCode($this->call('POST', '/api/v3/operational-exceptions', $owner, ['permission' => 'accounts.view'] + $request)), 'Only operational codes.');
            self::assertSame('validation_failed', self::errorCode($this->call('POST', '/api/v3/operational-exceptions', $owner, ['recipient_user_id' => self::$users['full_admin']] + $request)), 'Recipients must hold technical_manager.');

            $created = $this->call('POST', '/api/v3/operational-exceptions', $owner, ['expires_at' => '2026-11-25T09:00:00Z'] + $request);
            self::assertSame(201, $created->getStatusCode(), (string) $created->getBody());
            $exception = self::data($created);
            self::assertSame('pending', $exception['status']);
            $exceptionId = self::int($exception['id']);

            $tm = $this->login('technical_manager');
            self::assertArrayNotHasKey('hours.view', $this->permissions($tm), 'Pending grants nothing.');

            $owner2 = $this->login('owner2');
            $authorised = $this->call('POST', "/api/v3/operational-exceptions/{$exceptionId}/authorise", $owner2, ['password_confirmation' => self::PASSWORD, 'version' => $exception['version']]);
            self::assertSame(200, $authorised->getStatusCode(), (string) $authorised->getBody());
            self::assertSame('authorised', self::data($authorised)['status']);

            $tm = $this->login('technical_manager');
            self::assertSame('organisation', $this->permissions($tm)['hours.view'] ?? null);

            // Ineffective immediately after expiry, without any other change.
            self::$clock->advance('+1 day');
            $tm = $this->login('technical_manager');
            self::assertArrayNotHasKey('hours.view', $this->permissions($tm));
            self::$clock->advance('-1 day');

            $revoked = $this->call('POST', "/api/v3/operational-exceptions/{$exceptionId}/revoke", $owner, ['reason' => 'Done', 'version' => self::data($authorised)['version']]);
            self::assertSame('revoked', self::data($revoked)['status']);
            $tm = $this->login('technical_manager');
            self::assertArrayNotHasKey('hours.view', $this->permissions($tm));

            // The recipient never authorises; stale versions are refused.
            $second = self::data($this->call('POST', '/api/v3/operational-exceptions', $owner, $request));
            $secondId = self::int($second['id']);
            self::assertSame('stale_version', self::errorCode($this->call('POST', "/api/v3/operational-exceptions/{$secondId}/reject", $owner, ['reason' => 'No', 'version' => 99])));
            self::assertSame('rejected', self::data($this->call('POST', "/api/v3/operational-exceptions/{$secondId}/reject", $owner, ['reason' => 'No', 'version' => $second['version']]))['status']);
        } finally {
            self::db()->insert("UPDATE permissions SET exception_eligible = 0 WHERE code = 'hours.view'");
        }
    }

    public function testATechnicalManagerCannotRequestAnException(): void
    {
        self::assertSame('forbidden', self::errorCode($this->call('POST', '/api/v3/operational-exceptions', $this->login('technical_manager'), ['recipient_user_id' => self::$users['technical_manager'], 'permission' => 'hours.view', 'reason' => 'x'])));
    }
}
