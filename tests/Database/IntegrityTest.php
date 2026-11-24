<?php

declare(strict_types=1);

namespace Ehf\Api\Tests\Database;

use Ehf\Api\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

/**
 * The database refuses data that breaks an approved integrity rule of Database V1.4 (Phase 3
 * task 4). Each test builds a small set of valid records inside a transaction, which is rolled
 * back afterwards, then attempts violations.
 *
 * The rows are synthetic test data only (example.test addresses, fictional names).
 */
final class IntegrityTest extends TestCase
{
    private static ?TestDatabase $db = null;

    /** @var array<string, int> */
    private array $w = [];

    public static function setUpBeforeClass(): void
    {
        self::$db = TestDatabase::create();
        self::$db?->migrate();
    }

    public static function tearDownAfterClass(): void
    {
        self::$db?->drop();
        self::$db = null;
    }

    protected function setUp(): void
    {
        if (self::$db === null) {
            self::markTestSkipped('DB_TEST_* not set: integrity tests need a throwaway MySQL or MariaDB server.');
        }

        self::$db->pdo->beginTransaction();
        $this->w = $this->world();
    }

    protected function tearDown(): void
    {
        if (self::$db !== null && self::$db->pdo->inTransaction()) {
            self::$db->pdo->rollBack();
        }
    }

    public function testCatalogueBackedColumnsAcceptOnlyTheirDomain(): void
    {
        $this->refused('users', ['email' => 'x1@example.test', 'display_name' => 'X', 'status' => 'bogus'], 'unknown value');
        $this->refused('users', ['email' => 'x2@example.test', 'display_name' => 'X', 'status' => 'open'], 'value of another domain');
        $this->refused('users', ['email' => 'x3@example.test', 'display_name' => 'X', 'status' => 'open', 'status_domain' => 'period'], 'domain column is pinned');
        $this->refused('volunteers', ['reference' => 'V-000009', 'full_name' => 'X', 'status' => 'invited'], 'account value on a volunteer');
        $this->accepted('users', ['email' => 'x4@example.test', 'display_name' => 'X', 'status' => 'active']);
    }

    public function testApprovedReferenceAndCertificateNumberFormats(): void
    {
        foreach (['V-1', 'X-000001', 'V-0000001', 'v-000001', 'V-00000A'] as $reference) {
            $this->refused('volunteers', ['reference' => $reference, 'full_name' => 'X'], "reference {$reference}");
        }

        foreach (['EHF-26-00001', 'EHF-2026-0001', 'ehf-2026-00001', 'EHF-2026-000001'] as $number) {
            $this->refused('certificates', $this->certificate(['certificate_number' => $number, 'status' => 'revoked', 'active_period_id' => null, 'revoked_by_user_id' => $this->w['admin'], 'revoked_at' => '2026-03-01 00:00:00', 'revoke_reason' => 'x']), "number {$number}");
        }
    }

    public function testVolunteerAcceptanceIsAllOrNothingAndAccountLinkIsUnique(): void
    {
        $this->refused('volunteers', ['reference' => 'V-000010', 'full_name' => 'X', 'accepted_at' => '2026-01-01 00:00:00'], 'accepted_at without accepted_by');
        $this->refused('volunteers', ['reference' => 'V-000011', 'full_name' => 'X', 'accepted_by_user_id' => $this->w['admin']], 'accepted_by without accepted_at');
        $this->accepted('volunteers', ['reference' => 'V-000012', 'full_name' => 'X', 'user_id' => $this->w['vuser']]);
        $this->refused('volunteers', ['reference' => 'V-000013', 'full_name' => 'Y', 'user_id' => $this->w['vuser']], 'one volunteer record per account');
        $this->accepted('volunteers', ['reference' => 'V-000014', 'full_name' => 'Z']); // email and phone optional (AC-4 / W1)
    }

    public function testSystemOwnerMembershipRules(): void
    {
        $this->refused('system_owner_memberships', ['user_id' => $this->w['other'], 'added_via' => 'bootstrap', 'bootstrap_key' => 1, 'add_reason' => 'x', 'active_key' => $this->w['other']], 'second bootstrap owner');
        $this->refused('system_owner_memberships', ['user_id' => $this->w['other'], 'added_via' => 'bootstrap', 'add_reason' => 'x', 'active_key' => $this->w['other']], 'bootstrap without bootstrap_key (NULL-safe)');
        $this->refused('system_owner_memberships', ['user_id' => $this->w['other'], 'added_via' => 'owner', 'add_reason' => 'x', 'active_key' => $this->w['other']], 'owner added by nobody');
        $this->refused('system_owner_memberships', ['user_id' => $this->w['other'], 'added_via' => 'owner', 'added_by_membership_id' => $this->w['mB'], 'added_by_user_id' => $this->w['other'], 'add_reason' => 'x', 'active_key' => $this->w['other']], 'owner adds themselves');
        $this->refused('system_owner_memberships', ['user_id' => $this->w['other'], 'added_via' => 'owner', 'added_by_membership_id' => $this->w['mA'], 'added_by_user_id' => $this->w['ownerB'], 'add_reason' => 'x', 'active_key' => $this->w['other']], 'adder membership belongs to someone else');
        $this->refused('system_owner_memberships', ['user_id' => $this->w['ownerB'], 'added_via' => 'owner', 'added_by_membership_id' => $this->w['mA'], 'added_by_user_id' => $this->w['ownerA'], 'add_reason' => 'x', 'active_key' => $this->w['ownerB']], 'second active membership');
        $this->refused('system_owner_memberships', ['user_id' => $this->w['other'], 'added_via' => 'owner', 'added_by_membership_id' => $this->w['mA'], 'added_by_user_id' => $this->w['ownerA'], 'add_reason' => 'x'], 'active membership without active key (NULL-safe)');
        $this->refusedSql('UPDATE system_owner_memberships SET removed_at = NOW(6), removed_by_membership_id = ?, removed_by_user_id = ?, remove_reason = ?, active_key = NULL WHERE id = ?', [$this->w['mB'], $this->w['ownerB'], 'x', $this->w['mB']], 'owner removes themselves');
        $this->acceptedSql('UPDATE system_owner_memberships SET removed_at = NOW(6), removed_by_membership_id = ?, removed_by_user_id = ?, remove_reason = ?, active_key = NULL WHERE id = ?', [$this->w['mA'], $this->w['ownerA'], 'x', $this->w['mB']]);
    }

    public function testOwnerAuthorisationRules(): void
    {
        $this->refused('owner_authorisations', ['kind' => 'owner', 'owner_membership_id' => $this->w['mB'], 'owner_user_id' => $this->w['ownerB'], 'subject_user_id' => $this->w['ownerB'], 'action' => 'grant_technical_manager', 'reason' => 'x'], 'owner authorises a change to their own role');
        $this->refused('owner_authorisations', ['kind' => 'owner', 'owner_membership_id' => $this->w['mA'], 'owner_user_id' => $this->w['ownerB'], 'subject_user_id' => $this->w['tm'], 'action' => 'grant_technical_manager', 'reason' => 'x'], 'membership of another user');
        $this->refused('owner_authorisations', ['kind' => 'owner', 'subject_user_id' => $this->w['tm'], 'action' => 'grant_technical_manager', 'reason' => 'x'], 'owner authorisation without an owner');
        $this->refused('owner_authorisations', ['kind' => 'bootstrap', 'bootstrap_key' => 1, 'subject_user_id' => $this->w['tm'], 'action' => 'revoke_technical_manager', 'reason' => 'x'], 'bootstrap can only grant');
        $this->accepted('owner_authorisations', ['kind' => 'bootstrap', 'bootstrap_key' => 1, 'subject_user_id' => $this->w['ownerA'], 'action' => 'grant_technical_manager', 'reason' => 'x']);
        $this->refused('owner_authorisations', ['kind' => 'bootstrap', 'bootstrap_key' => 1, 'subject_user_id' => $this->w['tm'], 'action' => 'grant_technical_manager', 'reason' => 'x'], 'second bootstrap authorisation');
    }

    public function testTechnicalManagerRoleNeedsASingleUseOwnerGrantForTheSameUser(): void
    {
        $grant = $this->insert('owner_authorisations', ['kind' => 'owner', 'owner_membership_id' => $this->w['mB'], 'owner_user_id' => $this->w['ownerB'], 'subject_user_id' => $this->w['tm'], 'action' => 'grant_technical_manager', 'reason' => 'x']);
        $revoke = $this->insert('owner_authorisations', ['kind' => 'owner', 'owner_membership_id' => $this->w['mB'], 'owner_user_id' => $this->w['ownerB'], 'subject_user_id' => $this->w['tm'], 'action' => 'revoke_technical_manager', 'reason' => 'x']);

        $this->refused('user_roles', ['user_id' => $this->w['tm'], 'role_code' => 'technical_manager', 'reason' => 'x', 'granted_by_user_id' => $this->w['admin'], 'active_key' => 'tm1'], 'no owner authorisation');
        $this->refused('user_roles', ['user_id' => $this->w['other'], 'role_code' => 'technical_manager', 'reason' => 'x', 'owner_authorisation_id' => $grant, 'active_key' => 'tm2'], 'authorisation for another user');
        $this->refused('user_roles', ['user_id' => $this->w['tm'], 'role_code' => 'technical_manager', 'reason' => 'x', 'owner_authorisation_id' => $revoke, 'active_key' => 'tm3'], 'revoke authorisation used to grant');
        $role = $this->insert('user_roles', ['user_id' => $this->w['tm'], 'role_code' => 'technical_manager', 'reason' => 'x', 'owner_authorisation_id' => $grant, 'active_key' => 'tm4']);
        $this->refused('user_roles', ['user_id' => $this->w['tm'], 'role_code' => 'technical_manager', 'reason' => 'x', 'owner_authorisation_id' => $grant, 'active_key' => 'tm5'], 'authorisation used twice');
        $this->refusedSql('UPDATE user_roles SET revoked_at = NOW(6), revoked_by_user_id = ?, revoke_reason = ?, active_key = NULL WHERE id = ?', [$this->w['admin'], 'x', $role], 'revocation without owner revoke authorisation');
        $this->acceptedSql('UPDATE user_roles SET revoked_at = NOW(6), revoked_by_user_id = ?, revoke_reason = ?, active_key = NULL, revoke_owner_authorisation_id = ? WHERE id = ?', [$this->w['ownerB'], 'x', $revoke, $role]);
        $this->refused('user_roles', ['user_id' => $this->w['other'], 'role_code' => 'full_admin', 'reason' => 'x', 'granted_by_user_id' => $this->w['admin'], 'owner_authorisation_id' => $grant, 'active_key' => 'o1'], 'ordinary role carrying an owner authorisation');
    }

    public function testNoSelfGrantsAndCompleteRevocations(): void
    {
        $this->refused('user_roles', ['user_id' => $this->w['admin'], 'role_code' => 'full_admin', 'reason' => 'x', 'granted_by_user_id' => $this->w['admin'], 'active_key' => 'a1'], 'role granted to oneself (G3)');
        $this->refused('user_roles', ['user_id' => $this->w['other'], 'role_code' => 'full_admin', 'reason' => 'x', 'active_key' => 'a2'], 'ordinary role without a granting user');
        $this->refused('user_roles', ['user_id' => $this->w['other'], 'role_code' => 'full_admin', 'reason' => 'x', 'granted_by_user_id' => $this->w['admin'], 'scope_type' => 'project'], 'project scope without a project');
        $role = $this->insert('user_roles', ['user_id' => $this->w['other'], 'role_code' => 'monitoring_admin', 'reason' => 'x', 'granted_by_user_id' => $this->w['admin'], 'active_key' => 'a3']);
        $this->refusedSql('UPDATE user_roles SET revoked_at = NOW(6), revoked_by_user_id = user_id, revoke_reason = ?, active_key = NULL WHERE id = ?', ['x', $role], 'revoking one\'s own role');
        $this->refusedSql('UPDATE user_roles SET revoked_at = NOW(6), active_key = NULL WHERE id = ?', [$role], 'incomplete revocation');
        $this->refusedSql('UPDATE user_roles SET revoked_at = NOW(6), revoked_by_user_id = ?, revoke_reason = ? WHERE id = ?', [$this->w['admin'], 'x', $role], 'revoked but still active');

        $this->refused('user_permission_grants', ['user_id' => $this->w['admin'], 'permission_code' => 'hours.view', 'reason' => 'x', 'granted_by_user_id' => $this->w['admin'], 'active_key' => 'g1'], 'grant to oneself (G3)');
        $this->refused('user_permission_grants', ['user_id' => $this->w['other'], 'permission_code' => 'not.a.code', 'reason' => 'x', 'granted_by_user_id' => $this->w['admin'], 'active_key' => 'g2'], 'unknown permission');
        $this->accepted('user_permission_grants', ['user_id' => $this->w['other'], 'permission_code' => 'hours.view', 'reason' => 'x', 'granted_by_user_id' => $this->w['admin'], 'active_key' => 'g3']);
    }

    public function testOperationalExceptionRules(): void
    {
        $base = ['recipient_user_id' => $this->w['tm'], 'permission_code' => 'hours.view', 'reason' => 'x', 'requested_by_user_id' => $this->w['ownerA']];

        $this->refused('operational_exceptions', ['permission_code' => 'owners.add'] + $base, 'governance code');
        $this->refused('operational_exceptions', ['permission_code' => 'accounts.create'] + $base, 'technical code');
        $this->refused('operational_exceptions', ['permission_category' => 'technical', 'permission_code' => 'accounts.create'] + $base, 'category is pinned to operational');
        $this->refused('operational_exceptions', ['requested_by_user_id' => $this->w['tm']] + $base, 'requester is the recipient');
        $this->refused('operational_exceptions', ['authorised_by_membership_id' => $this->w['mB'], 'authorised_by_user_id' => $this->w['ownerB'], 'authorised_at' => '2026-01-01 00:00:00'] + $base, 'pending with an authoriser');
        $this->refused('operational_exceptions', ['status' => 'authorised', 'authorised_by_user_id' => $this->w['ownerB'], 'authorised_at' => '2026-01-01 00:00:00'] + $base, 'authoriser without membership (pair, NULL-safe)');
        $this->refused('operational_exceptions', ['status' => 'authorised', 'authorised_by_membership_id' => $this->w['mA'], 'authorised_by_user_id' => $this->w['ownerB'], 'authorised_at' => '2026-01-01 00:00:00'] + $base, 'membership of another user');
        $this->refused('operational_exceptions', ['recipient_user_id' => $this->w['ownerB'], 'status' => 'authorised', 'authorised_by_membership_id' => $this->w['mB'], 'authorised_by_user_id' => $this->w['ownerB'], 'authorised_at' => '2026-01-01 00:00:00'] + $base, 'recipient authorises');
        $this->refused('operational_exceptions', ['status' => 'rejected', 'rejected_by_membership_id' => $this->w['mB'], 'rejected_by_user_id' => $this->w['ownerB'], 'rejected_at' => '2026-01-01 00:00:00'] + $base, 'rejected without reason');
        $this->refused('operational_exceptions', ['status' => 'expired', 'authorised_by_membership_id' => $this->w['mB'], 'authorised_by_user_id' => $this->w['ownerB'], 'authorised_at' => '2026-01-01 00:00:00'] + $base, 'expired without expiry');
        $this->refused('operational_exceptions', ['scope_type' => 'project'] + $base, 'project scope without project');

        $id = $this->insert('operational_exceptions', ['status' => 'authorised', 'authorised_by_membership_id' => $this->w['mB'], 'authorised_by_user_id' => $this->w['ownerB'], 'authorised_at' => '2026-01-01 00:00:00'] + $base);
        $this->refusedSql('UPDATE operational_exceptions SET status = ?, revoked_at = NOW(6), revoke_reason = ? WHERE id = ?', ['revoked', 'x', $id], 'revoked without revoker');
        $this->acceptedSql('UPDATE operational_exceptions SET status = ?, revoked_by_membership_id = ?, revoked_by_user_id = ?, revoked_at = NOW(6), revoke_reason = ? WHERE id = ?', ['revoked', $this->w['mA'], $this->w['ownerA'], 'x', $id]);
    }

    public function testPeriodCompletionNeedsItsFrozenValues(): void
    {
        $this->refusedSql("UPDATE volunteering_periods SET status = 'completed' WHERE id = ?", [$this->w['p1']], 'completed without end date and snapshot');
        $this->refusedSql('UPDATE volunteering_periods SET approved_minutes_snapshot = 10 WHERE id = ?', [$this->w['p1']], 'open period with a snapshot');
        $this->refused('volunteering_periods', ['volunteer_id' => $this->w['v1'], 'start_date' => '2026-05-01', 'end_date' => '2026-04-01'], 'end before start');
        $this->acceptedSql("UPDATE volunteering_periods SET status = 'completed', end_date = '2026-06-30', completed_at = NOW(6), completed_by_user_id = ?, approved_minutes_snapshot = 120 WHERE id = ?", [$this->w['admin'], $this->w['p1']]);
    }

    public function testActiveKeysAllowOneActiveRow(): void
    {
        $assignment = ['volunteer_id' => $this->w['v1'], 'project_id' => $this->w['P'], 'role' => 'Helper', 'start_date' => '2026-01-01', 'active_key' => 1];
        $first = $this->insert('volunteer_assignments', $assignment);
        $this->refused('volunteer_assignments', $assignment, 'second active assignment to the same project');
        $this->refused('volunteer_assignments', ['active_key' => null] + $assignment, 'active assignment without active key (NULL-safe)');
        $this->acceptedSql("UPDATE volunteer_assignments SET status = 'ended', active_key = NULL, ended_at = NOW(6), ended_by_user_id = ? WHERE id = ?", [$this->w['admin'], $first]);
        $this->accepted('volunteer_assignments', $assignment);

        $this->accepted('project_staff', ['project_id' => $this->w['P'], 'user_id' => $this->w['admin'], 'added_by_user_id' => $this->w['other'], 'active_key' => $this->w['admin']]);
        $this->accepted('project_staff', ['project_id' => $this->w['Q'], 'user_id' => $this->w['admin'], 'added_by_user_id' => $this->w['other'], 'active_key' => $this->w['admin']]);
        $this->refused('project_staff', ['project_id' => $this->w['P'], 'user_id' => $this->w['admin'], 'added_by_user_id' => $this->w['other'], 'active_key' => $this->w['admin']], 'same person twice on one project');

        $this->accepted('task_assignees', ['task_id' => $this->w['tP'], 'volunteer_id' => $this->w['v1'], 'assigned_by_user_id' => $this->w['admin'], 'active_key' => 1]);
        $this->refused('task_assignees', ['task_id' => $this->w['tP'], 'volunteer_id' => $this->w['v1'], 'assigned_by_user_id' => $this->w['admin'], 'active_key' => 1], 'same assignee twice');
        $this->accepted('task_assignees', ['task_id' => $this->w['tP'], 'volunteer_id' => $this->w['v2'], 'assigned_by_user_id' => $this->w['admin'], 'active_key' => 1]); // several assignees allowed by the schema (W2)
    }

    public function testHoursRules(): void
    {
        $entry = ['volunteer_id' => $this->w['v1'], 'project_id' => $this->w['P'], 'activity_date' => '2026-02-02', 'duration_minutes' => 30, 'description' => 'x'];
        $this->refused('hours_entries', ['duration_minutes' => 0] + $entry, 'zero minutes');
        $this->refused('hours_entries', ['task_id' => $this->w['tQ']] + $entry, 'task of another project');
        $this->accepted('hours_entries', ['task_id' => $this->w['tP'], 'duration_minutes' => 2000] + $entry); // no maximum (W3)

        $revision = ['hours_entry_id' => $this->w['h1'], 'revision_no' => 2, 'kind' => 'resubmission', 'project_id' => $this->w['P'], 'activity_date' => '2026-02-01', 'duration_minutes' => 90, 'description' => 'x', 'created_by_user_id' => $this->w['vuser']];
        $this->refused('hours_entry_revisions', ['task_id' => $this->w['tQ']] + $revision, 'revision task of another project');
        $this->refused('hours_entry_revisions', ['kind' => 'staff_correction'] + $revision, 'staff correction without reason');
        $this->refused('hours_entry_revisions', ['revision_no' => 1] + $revision, 'duplicate revision number');

        $this->refused('hours_decisions', ['hours_entry_id' => $this->w['h1'], 'revision_no' => 1, 'decision' => 'reject', 'decided_by_user_id' => $this->w['admin']], 'rejection without reason');
        $this->refused('hours_decisions', ['hours_entry_id' => $this->w['h1'], 'revision_no' => 9, 'decision' => 'approve', 'decided_by_user_id' => $this->w['admin']], 'decision on a revision that does not exist');
        $this->refused('hours_decisions', ['hours_entry_id' => $this->w['h1'], 'revision_no' => 1, 'decision' => 'accept', 'decided_by_user_id' => $this->w['admin']], 'task report decision value');
        $this->accepted('hours_decisions', ['hours_entry_id' => $this->w['h1'], 'revision_no' => 1, 'decision' => 'request_correction', 'reason' => 'Please add the location', 'decided_by_user_id' => $this->w['admin']]);
    }

    public function testOneActiveCertificatePerPeriod(): void
    {
        $this->refused('certificates', $this->certificate(['certificate_number' => 'EHF-2026-00002']), 'second issued certificate for the period');
        $this->refused('certificates', $this->certificate(['certificate_number' => 'EHF-2026-00003', 'active_period_id' => null]), 'issued without active key (NULL-safe)');
        $this->refused('certificates', $this->certificate(['certificate_number' => 'EHF-2026-00004', 'active_period_id' => $this->w['p2']]), 'active key of another period');
        $this->refusedSql("UPDATE certificates SET status = 'revoked', active_period_id = NULL WHERE id = ?", [$this->w['c1']], 'revoked without who, when and why');
        $this->acceptedSql("UPDATE certificates SET status = 'revoked', active_period_id = NULL, revoked_by_user_id = ?, revoked_at = NOW(6), revoke_reason = 'x' WHERE id = ?", [$this->w['admin'], $this->w['c1']]);
        $this->accepted('certificates', $this->certificate(['certificate_number' => 'EHF-2026-00005'])); // a new certificate after revocation
    }

    public function testCertificatesPeriodsAndReplacementsBelongToOneVolunteer(): void
    {
        $this->refused('certificates', $this->certificate(['certificate_number' => 'EHF-2026-00010', 'volunteer_id' => $this->w['v2'], 'active_period_id' => $this->w['p1']]), 'period of another volunteer');

        $this->acceptedSql("UPDATE certificates SET status = 'replaced', active_period_id = NULL WHERE id = ?", [$this->w['c1']]);
        $p1b = $this->insert('volunteering_periods', ['volunteer_id' => $this->w['v1'], 'start_date' => '2027-01-01']);
        $this->refused('certificates', $this->certificate(['certificate_number' => 'EHF-2027-00001', 'period_id' => $p1b, 'active_period_id' => $p1b, 'replaces_certificate_id' => $this->w['c1']]), 'replacement for another period');
        $this->refused('certificates', $this->certificate(['certificate_number' => 'EHF-2026-00011', 'volunteer_id' => $this->w['v2'], 'period_id' => $this->w['p2'], 'active_period_id' => $this->w['p2'], 'replaces_certificate_id' => $this->w['c1']]), 'replacement for another volunteer');
        $replacement = $this->insert('certificates', $this->certificate(['certificate_number' => 'EHF-2026-00012', 'replaces_certificate_id' => $this->w['c1']]));
        $this->refused('certificates', $this->certificate(['certificate_number' => 'EHF-2026-00013', 'status' => 'replaced', 'active_period_id' => null, 'replaces_certificate_id' => $this->w['c1']]), 'a certificate is replaced only once');
        self::assertGreaterThan(0, $replacement);
    }

    public function testCertificateHoursAreTracedToTheApprovedRevisionOfTheSameVolunteer(): void
    {
        $row = ['certificate_id' => $this->w['c1'], 'hours_entry_id' => $this->w['h1'], 'volunteer_id' => $this->w['v1'], 'revision_no' => 1, 'hours_revision_id' => $this->w['r1'],
            'duration_minutes' => 120, 'activity_date' => '2026-02-01', 'project_id' => $this->w['P'], 'hours_decision_id' => $this->w['d1']];

        $this->refused('certificate_hours', ['duration_minutes' => 999] + $row, 'snapshot minutes differ from the revision');
        $this->refused('certificate_hours', ['activity_date' => '2026-02-02'] + $row, 'snapshot date differs from the revision');
        $this->refused('certificate_hours', ['decision_code' => 'reject'] + $row, 'decision code pinned to approve');
        $reject = $this->insert('hours_decisions', ['hours_entry_id' => $this->w['h1'], 'revision_no' => 1, 'decision' => 'reject', 'reason' => 'x', 'decided_by_user_id' => $this->w['admin']]);
        $this->refused('certificate_hours', ['hours_decision_id' => $reject] + $row, 'a reject decision');
        $this->refused('certificate_hours', ['hours_decision_id' => $this->w['d2']] + $row, 'a decision on another entry');
        $this->refused('certificate_hours', ['hours_entry_id' => $this->w['h2'], 'hours_revision_id' => $this->w['r2'], 'hours_decision_id' => $this->w['d2']] + $row, 'another volunteer\'s hours');
        $this->refused('certificate_hours', ['volunteer_id' => $this->w['v2']] + $row, 'volunteer differs from the certificate');
        $this->accepted('certificate_hours', $row);
    }

    public function testReviewPeriodBelongsToTheReviewedVolunteer(): void
    {
        $review = ['volunteer_id' => $this->w['v2'], 'period_id' => $this->w['p1'], 'review_date' => '2026-03-01', 'author_user_id' => $this->w['admin']];
        $this->refused('performance_reviews', $review, 'period of another volunteer');
        $id = $this->insert('performance_reviews', ['volunteer_id' => $this->w['v1']] + $review);

        $version = ['review_id' => $id, 'version_no' => 1, 'created_by_user_id' => $this->w['admin'], 'draft_key' => 1];
        $versionId = $this->insert('performance_review_versions', $version);
        $this->refused('performance_review_versions', ['version_no' => 2] + $version, 'second working draft');
        $this->refused('performance_review_versions', ['version_no' => 3, 'kind' => 'submitted', 'draft_key' => 1] + $version, 'submitted version must be frozen');
        $this->refused('performance_review_ratings', ['version_id' => $versionId, 'criterion_id' => $this->w['criterion'], 'rating' => 6], 'rating outside 1–5');
        $this->accepted('performance_review_ratings', ['version_id' => $versionId, 'criterion_id' => $this->w['criterion'], 'rating' => 4]);
        $this->refusedSql('UPDATE performance_reviews SET published_at = NOW(6) WHERE id = ?', [$id], 'published without a published version');
    }

    public function testOtherApprovedRules(): void
    {
        $this->refused('exports', ['requested_by_user_id' => $this->w['admin'], 'report_type' => 'hours', 'format' => 'csv', 'filters' => '{}', 'fields' => '[]', 'row_count' => 0, 'expires_at' => '2026-12-01 00:00:00'], 'CSV export (A12)');
        $this->accepted('exports', ['requested_by_user_id' => $this->w['admin'], 'report_type' => 'hours', 'format' => 'xlsx', 'filters' => '{}', 'fields' => '[]', 'row_count' => 0, 'expires_at' => '2026-12-01 00:00:00']);
        $this->refused('exports', ['requested_by_user_id' => $this->w['admin'], 'report_type' => 'hours', 'format' => 'pdf', 'filters' => '{not json', 'fields' => '[]', 'row_count' => 0, 'expires_at' => '2026-12-01 00:00:00'], 'invalid JSON');

        $this->refused('field_catalogue', ['table_name' => 'volunteers', 'column_name' => 'nickname', 'field_set' => 'contact', 'description' => 'x'], 'contact field without codes');
        $this->refused('field_catalogue', ['table_name' => 'volunteers', 'column_name' => 'nickname', 'field_set' => 'basic', 'access_code' => 'volunteers.view', 'write_code' => 'volunteers.view', 'description' => 'x'], 'basic field with codes');
        $this->refused('permissions', ['code' => 'owners.test', 'category' => 'governance', 'action_type' => 'manage', 'grantable' => 1, 'description' => 'x'], 'grantable governance code (G8)');
        $this->refused('permissions', ['code' => 'accounts.test', 'category' => 'technical', 'action_type' => 'view', 'exception_eligible' => 1, 'description' => 'x'], 'exception-eligible technical code');
        $this->refusedSql("UPDATE roles SET is_ordinary = 1 WHERE code = 'technical_manager'", [], 'technical_manager is not an ordinary role');
        $this->refused('auth_tokens', ['user_id' => $this->w['admin'], 'type' => 'magic_link', 'token_hash' => str_repeat('a', 64), 'expires_at' => '2026-12-01 00:00:00'], 'unknown token type');
        $this->refused('files', ['storage_key' => str_repeat('b', 64), 'original_name' => 'x', 'mime_detected' => 'text/plain', 'size_bytes' => 1, 'sha256' => str_repeat('c', 64), 'purpose' => 'public_link'], 'unknown file purpose');
        $this->refused('audit_logs', ['category' => 'operational', 'action' => 'x', 'outcome' => 'maybe', 'prev_hash' => str_repeat('0', 64), 'hash' => str_repeat('1', 64)], 'unknown audit outcome');

        $report = $this->insert('task_reports', ['task_id' => $this->w['tP'], 'volunteer_id' => $this->w['v1']]);
        $version = $this->insert('task_report_versions', ['task_report_id' => $report, 'version_no' => 1, 'title' => 'Report', 'submitted_by_user_id' => $this->w['vuser']]);
        $this->refused('task_report_decisions', ['task_report_id' => $report, 'version_id' => $version, 'decision' => 'request_correction', 'decided_by_user_id' => $this->w['admin']], 'correction request without feedback');
        $other = $this->insert('task_reports', ['task_id' => $this->w['tP'], 'volunteer_id' => $this->w['v2']]);
        $this->refused('task_report_decisions', ['task_report_id' => $other, 'version_id' => $version, 'decision' => 'accept', 'decided_by_user_id' => $this->w['admin']], 'decision on another report\'s version');
        $this->refused('task_report_attachments', ['task_report_id' => $report, 'version_id' => $version, 'file_id' => $this->w['file'], 'removed_at' => '2026-03-01 00:00:00'], 'removing a submitted attachment');
    }

    public function testForeignKeysRestrictDeletion(): void
    {
        $this->refusedSql('DELETE FROM volunteers WHERE id = ?', [$this->w['v1']], 'volunteer with history');
        $this->refusedSql('DELETE FROM users WHERE id = ?', [$this->w['admin']], 'user who acted');
        $this->refusedSql("DELETE FROM status_catalogue WHERE domain = 'certificate' AND code = 'issued'", [], 'status value in use');
        $this->refusedSql("DELETE FROM permissions WHERE code = 'hours.view'", [], 'permission in a bundle');
    }

    /**
     * A small valid world: users, two volunteers with one period each, two projects with one task
     * each, an approved hours entry per volunteer, a template, an issued certificate, and two
     * System Owners (one bootstrap, one added by the first).
     *
     * @return array<string, int>
     */
    private function world(): array
    {
        /** @var array<string, int> $w */
        $w = [];
        foreach (['admin', 'other', 'ownerA', 'ownerB', 'tm', 'vuser'] as $name) {
            $w[$name] = $this->insert('users', ['email' => "{$name}@example.test", 'display_name' => ucfirst($name), 'status' => 'active']);
        }

        $w['mA'] = $this->insert('system_owner_memberships', ['user_id' => $w['ownerA'], 'added_via' => 'bootstrap', 'bootstrap_key' => 1, 'add_reason' => 'Test bootstrap', 'active_key' => $w['ownerA']]);
        $w['mB'] = $this->insert('system_owner_memberships', ['user_id' => $w['ownerB'], 'added_via' => 'owner', 'added_by_membership_id' => $w['mA'], 'added_by_user_id' => $w['ownerA'], 'add_reason' => 'Second owner', 'active_key' => $w['ownerB']]);

        $w['v1'] = $this->insert('volunteers', ['reference' => 'V-000001', 'full_name' => 'Test Volunteer One']);
        $w['v2'] = $this->insert('volunteers', ['reference' => 'V-000002', 'full_name' => 'Test Volunteer Two']);
        $w['p1'] = $this->insert('volunteering_periods', ['volunteer_id' => $w['v1'], 'start_date' => '2026-01-01']);
        $w['p2'] = $this->insert('volunteering_periods', ['volunteer_id' => $w['v2'], 'start_date' => '2026-01-01']);

        $w['P'] = $this->insert('projects', ['name' => 'Test Project P', 'start_date' => '2026-01-01', 'status' => 'active']);
        $w['Q'] = $this->insert('projects', ['name' => 'Test Project Q', 'start_date' => '2026-01-01', 'status' => 'active']);
        $w['tP'] = $this->insert('tasks', ['project_id' => $w['P'], 'title' => 'Task in P']);
        $w['tQ'] = $this->insert('tasks', ['project_id' => $w['Q'], 'title' => 'Task in Q']);

        foreach ([1 => 'v1', 2 => 'v2'] as $n => $volunteer) {
            $w["h{$n}"] = $this->insert('hours_entries', ['volunteer_id' => $w[$volunteer], 'project_id' => $w['P'], 'task_id' => $w['tP'], 'activity_date' => '2026-02-01', 'duration_minutes' => 120, 'description' => 'Test session']);
            $w["r{$n}"] = $this->insert('hours_entry_revisions', ['hours_entry_id' => $w["h{$n}"], 'revision_no' => 1, 'kind' => 'submission', 'project_id' => $w['P'], 'task_id' => $w['tP'], 'activity_date' => '2026-02-01', 'duration_minutes' => 120, 'description' => 'Test session', 'created_by_user_id' => $w['vuser']]);
            $w["d{$n}"] = $this->insert('hours_decisions', ['hours_entry_id' => $w["h{$n}"], 'revision_no' => 1, 'decision' => 'approve', 'decided_by_user_id' => $w['admin']]);
        }

        $w['file'] = $this->insert('files', ['storage_key' => str_repeat('f', 64), 'original_name' => 'certificate.pdf', 'mime_detected' => 'application/pdf', 'size_bytes' => 100, 'sha256' => str_repeat('e', 64), 'purpose' => 'certificate_pdf']);
        $w['template'] = $this->insert('certificate_templates', ['name' => 'Test template', 'signatory_name' => 'Test Signatory', 'signatory_title' => 'Director', 'background_file_id' => $w['file'], 'layout' => '{}']);
        $w['criterion'] = (int) $this->db()->scalar('SELECT MIN(id) FROM review_criteria');

        $this->w = $w;
        $w['c1'] = $this->insert('certificates', $this->certificate(['certificate_number' => 'EHF-2026-00001']));

        return $w;
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function certificate(array $overrides): array
    {
        return $overrides + [
            'volunteer_id' => $this->w['v1'], 'period_id' => $this->w['p1'], 'template_id' => $this->w['template'], 'status' => 'issued',
            'active_period_id' => $this->w['p1'], 'approved_minutes' => 120, 'period_start_date' => '2026-01-01', 'period_end_date' => '2026-06-30',
            'volunteer_name_snapshot' => 'Test Volunteer One', 'signatory_name_snapshot' => 'Test Signatory', 'signatory_title_snapshot' => 'Director',
            'pdf_file_id' => $this->w['file'], 'issued_by_user_id' => $this->w['admin'],
        ];
    }

    /**
     * @param array<string, mixed> $values
     */
    private function insert(string $table, array $values): int
    {
        $columns = array_keys($values);
        $sql = \sprintf('INSERT INTO %s (%s) VALUES (%s)', $table, implode(', ', $columns), implode(', ', array_fill(0, \count($columns), '?')));
        $this->db()->pdo->prepare($sql)->execute(array_values($values));

        return (int) $this->db()->pdo->lastInsertId();
    }

    /**
     * @param array<string, mixed> $values
     */
    private function accepted(string $table, array $values): void
    {
        $this->insert($table, $values);
        $this->addToAssertionCount(1);
    }

    /**
     * @param array<string, mixed> $values
     */
    private function refused(string $table, array $values, string $rule): void
    {
        try {
            $this->insert($table, $values);
        } catch (\PDOException $e) {
            $this->assertIntegrityError($e, "{$table}: {$rule}");

            return;
        }

        self::fail("{$table}: the database accepted a row that breaks the rule: {$rule}");
    }

    /**
     * @param list<mixed> $params
     */
    private function acceptedSql(string $sql, array $params): void
    {
        $this->db()->pdo->prepare($sql)->execute($params);
        $this->addToAssertionCount(1);
    }

    /**
     * @param list<mixed> $params
     */
    private function refusedSql(string $sql, array $params, string $rule): void
    {
        try {
            $this->db()->pdo->prepare($sql)->execute($params);
        } catch (\PDOException $e) {
            $this->assertIntegrityError($e, $rule);

            return;
        }

        self::fail("The database accepted a change that breaks the rule: {$rule}");
    }

    /**
     * The refusal must come from an integrity rule, not from a mistake in the test (an unknown
     * column, a syntax error): SQLSTATE 23000 (foreign key, unique, not null; MariaDB CHECK),
     * MySQL 3819 (CHECK violated) or 22032 / 3140 (invalid JSON).
     */
    private function assertIntegrityError(\PDOException $e, string $rule): void
    {
        $info = \is_array($e->errorInfo) ? $e->errorInfo : [];
        $sqlState = \is_string($info[0] ?? null) ? $info[0] : (string) $e->getCode();
        $driverCode = \is_int($info[1] ?? null) ? $info[1] : 0;

        self::assertTrue(
            $sqlState === '23000' || $sqlState === '22032' || $driverCode === 3819 || $driverCode === 3140,
            "Refused for an unexpected reason ({$rule}): [{$sqlState}/{$driverCode}] " . $e->getMessage(),
        );
    }

    private function db(): TestDatabase
    {
        self::assertNotNull(self::$db);

        return self::$db;
    }
}
