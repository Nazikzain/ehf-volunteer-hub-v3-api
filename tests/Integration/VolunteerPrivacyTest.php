<?php

declare(strict_types=1);

namespace Ehf\Api\Tests\Integration;

use Ehf\Api\Authorisation\PermissionResolver;
use Ehf\Api\Authorisation\Principal;
use Ehf\Api\Services\VolunteerService;
use Ehf\Api\Tests\Support\IntegrationTestCase;

/**
 * Field-level privacy for volunteer data (API §6.7, Appendix B) with R6, R13 and R18 approved:
 * Monitoring Admin sees contact fields only inside a decision context; Technical Managers and
 * System Owners never see volunteer contact or sensitive data outside provisioning; nobody but
 * Full Admin (R6) and the volunteer themselves sees sensitive fields; notes reach Full Admin only.
 */
final class VolunteerPrivacyTest extends IntegrationTestCase
{
    private const array SENTINELS = [
        'email' => 'sentinel.leak@example.test',
        'phone' => '0161 496 0999',
        'address_line1' => 'SENTINEL Address One',
        'address_line2' => 'SENTINEL Address Two',
        'town' => 'SENTINEL Town',
        'postcode' => 'ZZ9 9ZZ',
        'nationality' => 'SENTINEL Nationality',
        'emergency_contact_name' => 'SENTINEL Kin',
        'emergency_contact_phone' => '0161 496 0998',
        'emergency_contact_relationship' => 'SENTINEL Relation',
    ];

    private const string NOTE = 'SENTINEL confidential note';

    public function testFullAdminWritesAndReadsEverythingButAuditKeepsNoValues(): void
    {
        [$id] = $this->sentinelVolunteer();
        $body = (string) $this->call('GET', "/api/v3/volunteers/{$id}", $this->login('full_admin'))->getBody();
        foreach (self::SENTINELS as $value) {
            self::assertStringContainsString($value, $body);
        }

        $audit = (string) self::db()->scalar("SELECT GROUP_CONCAT(COALESCE(details, '') SEPARATOR ' ') FROM audit_logs");
        foreach ([...array_values(self::SENTINELS), self::NOTE] as $value) {
            self::assertStringNotContainsStringIgnoringCase($value, $audit, 'audit details hold field names only');
        }
    }

    public function testNoOtherRoleSeesVolunteerContactSensitiveOrNoteDataAnywhere(): void
    {
        [$id] = $this->sentinelVolunteer();
        $endpoints = [
            '/api/v3/volunteers', "/api/v3/volunteers/{$id}", "/api/v3/volunteers/{$id}/status-history", "/api/v3/volunteers/{$id}/notes",
            '/api/v3/portal-provisioning/candidates', '/api/v3/users?per_page=100', '/api/v3/audit-logs/security?per_page=100',
            '/api/v3/system-owners', '/api/v3/me/profile', '/api/v3/auth/session',
        ];

        foreach (['monitoring_admin', 'technical_manager', 'owner', 'volunteer'] as $persona) {
            $session = $this->login($persona);
            foreach ($endpoints as $path) {
                $body = (string) $this->call('GET', $path, $session)->getBody();
                foreach ([...array_values(self::SENTINELS), self::NOTE] as $value) {
                    self::assertStringNotContainsStringIgnoringCase($value, $body, "{$persona} GET {$path}");
                }
            }
        }
    }

    public function testR13MonitoringAdminContactCodesCountOnlyInADecisionContext(): void
    {
        [$id] = $this->sentinelVolunteer();
        $session = $this->login('monitoring_admin');

        // Session: no record-level contact access, only the decision-context entry.
        $access = self::map(self::data($this->call('GET', '/api/v3/auth/session', $session))['field_access']);
        self::assertSame(['basic' => true, 'decision_context' => ['contact' => ['email', 'phone']]], $access);

        // Decision context (used by the hours, report and review decisions of later phases).
        $context = $this->service()->decisionContext($this->principal('monitoring_admin'), $id);
        self::assertSame(['id', 'reference', 'name', 'status', 'email', 'phone'], array_keys($context));
        self::assertSame(self::SENTINELS['email'], $context['email']);
        self::assertSame(['id', 'reference', 'name', 'status'], array_keys($this->service()->decisionContext($this->principal('technical_manager'), $id)), 'no contact codes, basic only');

        // Even with explicit record access (R14 still pending), the bundle codes do not open records.
        foreach (['volunteers.view', 'volunteers.browse'] as $code) {
            self::grant(self::$users['monitoring_admin'], $code, self::$users['technical_manager']);
        }
        $record = self::data($this->call('GET', "/api/v3/volunteers/{$id}", $session));
        self::assertArrayNotHasKey('email', $record);
        self::assertArrayNotHasKey('phone', $record);
        self::assertSame([], self::items($this->call('GET', '/api/v3/volunteers?q=sentinel.leak', $session)), 'q does not search unreadable email');

        // An explicit field grant is the approved way to authorise more [A].
        self::grant(self::$users['monitoring_admin'], 'fields.contact.email', self::$users['technical_manager']);
        $record = self::data($this->call('GET', "/api/v3/volunteers/{$id}", $session));
        self::assertSame(self::SENTINELS['email'], $record['email']);
        self::assertArrayNotHasKey('phone', $record);
        self::assertArrayNotHasKey('town', $record);

        self::db()->insert("UPDATE user_permission_grants SET revoked_at = NOW(6), revoked_by_user_id = ?, revoke_reason = 'Test', active_key = NULL WHERE user_id = ?", [self::$users['technical_manager'], self::$users['monitoring_admin']]);
        self::assertSame(403, $this->call('GET', "/api/v3/volunteers/{$id}", $session)->getStatusCode(), 'revocation applies on the next request');
    }

    public function testTechnicalManagersCannotBeGivenFieldCodes(): void
    {
        $tm2 = self::createUser('tm.two@example.test', 'Second TM');
        self::grantTechnicalManager($tm2, self::membershipId(self::$users['owner']), self::$users['owner']);

        foreach (['fields.sensitive.postcode', 'fields.contact.email', 'confidential.volunteer_notes.view'] as $code) {
            $response = $this->call('POST', "/api/v3/users/{$tm2}/grants", $this->login('technical_manager'), ['permission' => $code, 'reason' => 'Should be refused']);
            self::assertSame(422, $response->getStatusCode(), $code);
            self::assertSame('use_operational_exception', self::errorCode($response), 'G5');
        }
    }

    public function testCandidatesShowTheEmailOnlyForEligibleVolunteersAndNothingElse(): void
    {
        [, $eligible] = $this->sentinelVolunteer('accepted');
        $body = (string) $this->call('GET', '/api/v3/portal-provisioning/candidates?per_page=100', $this->login('technical_manager'))->getBody();

        self::assertStringContainsString(self::SENTINELS['email'], $body, 'R18: full invitation email for the Technical Manager');
        foreach (self::SENTINELS as $field => $value) {
            if ($field !== 'email') {
                self::assertStringNotContainsString($value, $body, $field);
            }
        }
        self::assertGreaterThan(0, $eligible);
    }

    /**
     * Creates a volunteer through the API as Full Admin, with every personal field and a note.
     *
     * @return array{0: int, 1: int} id and (when accepted) the same id
     */
    private function sentinelVolunteer(string $status = 'draft'): array
    {
        self::db()->insert('UPDATE volunteers SET email = NULL WHERE email = ?', [self::SENTINELS['email']]);
        $admin = $this->login('full_admin');
        $response = $this->call('POST', '/api/v3/volunteers', $admin, ['full_name' => 'Sentinel Person'] + self::SENTINELS);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $v = self::data($response);
        $id = self::int($v['id']);
        self::assertSame(201, $this->call('POST', "/api/v3/volunteers/{$id}/notes", $admin, ['body' => self::NOTE])->getStatusCode());
        if ($status === 'accepted') {
            self::assertSame(200, $this->call('POST', "/api/v3/volunteers/{$id}/accept", $admin, ['version' => $v['version']])->getStatusCode());

            return [$id, $id];
        }

        return [$id, 0];
    }

    private function service(): VolunteerService
    {
        $container = self::$app?->getContainer();
        self::assertNotNull($container);
        $service = $container->get(VolunteerService::class);
        self::assertInstanceOf(VolunteerService::class, $service);

        return $service;
    }

    private function principal(string $persona): Principal
    {
        $container = self::$app?->getContainer();
        self::assertNotNull($container);
        $resolver = $container->get(PermissionResolver::class);
        self::assertInstanceOf(PermissionResolver::class, $resolver);
        $resolved = $resolver->resolve(self::$users[$persona], self::$clock->now());

        return new Principal(self::$users[$persona], "{$persona}@example.test", $persona, $resolved['roles'], $resolved['permissions'], $resolved['owner_membership_id'], null, '', '', $resolved['context_only']);
    }
}
