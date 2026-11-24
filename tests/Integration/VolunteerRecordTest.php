<?php

declare(strict_types=1);

namespace Ehf\Api\Tests\Integration;

use Ehf\Api\Tests\Support\IntegrationTestCase;
use Ehf\Api\Tests\Support\TestSession;

/**
 * Volunteer records (API §7.4; Workflow WF-03) with W1 and R6 approved.
 */
final class VolunteerRecordTest extends IntegrationTestCase
{
    private static ?TestSession $admin = null;

    public function testCreateRecordsADraftWithAReferenceHistoryAndAuditWithoutValues(): void
    {
        $response = $this->call('POST', '/api/v3/volunteers', $this->admin(), [
            'full_name' => 'Amina Example', 'email' => 'Amina.Example@Example.test', 'phone' => '07700 900123',
            'skills' => 'First aid', 'availability' => ['type' => 'specific_days', 'days' => [3, 1]],
            'address_line1' => '1 Example Street', 'postcode' => 'm1 1aa', 'emergency_contact_name' => 'Sam Example',
        ]);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $v = self::data($response);
        self::assertMatchesRegularExpression('/^V-\d{6}$/', self::string($v['reference']));
        self::assertSame('draft', $v['status']);
        self::assertSame('amina.example@example.test', $v['email'], 'stored in lower case (W1 comparison ignores case)');
        self::assertSame('M1 1AA', $v['postcode']);
        self::assertSame(['type' => 'specific_days', 'days' => [1, 3]], $v['availability']);
        self::assertSame('1 Example Street', $v['address_line1'], 'R6: Full Admin writes and reads sensitive fields');
        self::assertNull($v['nationality']);
        self::assertSame(['basic', 'contact', 'sensitive'], self::map(\Ehf\Api\Tests\Support\TestApp::json($response)['meta'])['field_sets']);

        $id = self::int($v['id']);
        self::assertSame([['from' => null, 'to' => 'draft']], array_map(static fn(array $h): array => ['from' => $h['from'], 'to' => $h['to']], self::items($this->call('GET', "/api/v3/volunteers/{$id}/status-history", $this->admin()))));

        $details = (string) self::db()->scalar("SELECT details FROM audit_logs WHERE action = 'volunteer.created' AND resource_id = ? AND outcome = 'success'", $id);
        self::assertStringContainsString('address_line1', $details);
        self::assertStringNotContainsString('Example Street', $details, 'audit records field names only');
        self::assertStringNotContainsString('amina', strtolower($details));
    }

    public function testW1RequiresAFullNameAndAnEmailOrPhone(): void
    {
        $noName = $this->call('POST', '/api/v3/volunteers', $this->admin(), ['email' => 'no.name@example.test']);
        self::assertSame(422, $noName->getStatusCode());
        self::assertContains('full_name', array_column(\Ehf\Api\Tests\Support\TestApp::error($noName)['details'], 'field'));

        $noContact = $this->call('POST', '/api/v3/volunteers', $this->admin(), ['full_name' => 'No Contact']);
        self::assertSame(422, $noContact->getStatusCode());
        self::assertSame(['email', 'phone'], array_column(\Ehf\Api\Tests\Support\TestApp::error($noContact)['details'], 'field'));

        $phoneOnly = $this->call('POST', '/api/v3/volunteers', $this->admin(), ['full_name' => 'Phone Only', 'phone' => '+44 161 496 0000']);
        self::assertSame(201, $phoneOnly->getStatusCode(), (string) $phoneOnly->getBody());

        $badEmail = $this->call('POST', '/api/v3/volunteers', $this->admin(), ['full_name' => 'Bad', 'email' => 'not-an-email']);
        self::assertSame(422, $badEmail->getStatusCode());

        $unknown = $this->call('POST', '/api/v3/volunteers', $this->admin(), ['full_name' => 'X', 'phone' => '0161 496 0001', 'status' => 'active']);
        self::assertSame(400, $unknown->getStatusCode(), 'status is changed only through action endpoints');

        // Clearing the last contact detail on edit is refused too.
        $v = self::data($phoneOnly);
        $clear = $this->call('PATCH', '/api/v3/volunteers/' . self::int($v['id']), $this->admin(), ['phone' => null, 'version' => $v['version']]);
        self::assertSame(422, $clear->getStatusCode());
    }

    public function testW1DuplicateIsTheSameEmailIgnoringCase(): void
    {
        $first = self::data($this->call('POST', '/api/v3/volunteers', $this->admin(), ['full_name' => 'First', 'email' => 'dup@example.test']));

        $again = $this->call('POST', '/api/v3/volunteers', $this->admin(), ['full_name' => 'Second', 'email' => 'DUP@example.test']);
        self::assertSame(409, $again->getStatusCode());
        self::assertSame('duplicate', self::errorCode($again));

        $other = self::data($this->call('POST', '/api/v3/volunteers', $this->admin(), ['full_name' => 'Other', 'email' => 'other@example.test']));
        $patch = $this->call('PATCH', '/api/v3/volunteers/' . self::int($other['id']), $this->admin(), ['email' => 'Dup@Example.test', 'version' => $other['version']]);
        self::assertSame('duplicate', self::errorCode($patch));

        // A record keeps its own address; the same name alone is not a duplicate (manual check).
        $same = $this->call('PATCH', '/api/v3/volunteers/' . self::int($first['id']), $this->admin(), ['email' => 'dup@example.test', 'full_name' => 'First Renamed', 'version' => $first['version']]);
        self::assertSame(200, $same->getStatusCode(), (string) $same->getBody());
        self::assertSame(201, $this->call('POST', '/api/v3/volunteers', $this->admin(), ['full_name' => 'First Renamed', 'phone' => '0161 496 0002'])->getStatusCode());
    }

    public function testEditUsesVersionsAndAuditsOnlyRealChanges(): void
    {
        $v = self::data($this->call('POST', '/api/v3/volunteers', $this->admin(), ['full_name' => 'Edit Me', 'email' => 'edit.me@example.test']));
        $id = self::int($v['id']);
        $before = self::auditCount('volunteer.updated', 'success');

        $unchanged = $this->call('PATCH', "/api/v3/volunteers/{$id}", $this->admin(), ['full_name' => 'Edit Me', 'version' => $v['version']]);
        self::assertSame($v['version'], self::data($unchanged)['version']);
        self::assertSame($before, self::auditCount('volunteer.updated', 'success'));

        $changed = self::data($this->call('PATCH', "/api/v3/volunteers/{$id}", $this->admin(), ['nationality' => 'Example', 'availability' => ['type' => 'flexible'], 'version' => $v['version']]));
        self::assertSame(self::int($v['version']) + 1, $changed['version']);
        self::assertSame('Example', $changed['nationality']);
        self::assertSame(['type' => 'flexible', 'days' => []], $changed['availability']);
        self::assertSame($before + 1, self::auditCount('volunteer.updated', 'success'));

        $stale = $this->call('PATCH', "/api/v3/volunteers/{$id}", $this->admin(), ['full_name' => 'Too Late', 'version' => $v['version']]);
        self::assertSame('stale_version', self::errorCode($stale));
    }

    public function testStatusLifecycleFollowsWorkflowSection32(): void
    {
        $v = self::data($this->call('POST', '/api/v3/volunteers', $this->admin(), ['full_name' => 'Lifecycle', 'email' => 'lifecycle@example.test']));
        $id = self::int($v['id']);
        $version = self::int($v['version']);

        $wrongWay = $this->call('POST', "/api/v3/volunteers/{$id}/status-changes", $this->admin(), ['to_status' => 'accepted', 'reason' => 'Skip', 'version' => $version]);
        self::assertSame('invalid_transition', self::errorCode($wrongWay), 'acceptance is its own endpoint');
        self::assertSame('invalid_transition', self::errorCode($this->call('POST', "/api/v3/volunteers/{$id}/status-changes", $this->admin(), ['to_status' => 'active', 'reason' => 'Skip', 'version' => $version])));

        $accepted = self::data($this->call('POST', "/api/v3/volunteers/{$id}/accept", $this->admin(), ['note' => 'Interviewed', 'version' => $version]));
        self::assertSame('accepted', $accepted['status']);
        self::assertNotNull($accepted['accepted_at']);
        self::assertSame('invalid_transition', self::errorCode($this->call('POST', "/api/v3/volunteers/{$id}/accept", $this->admin(), ['version' => $accepted['version']])));
        self::assertSame('stale_version', self::errorCode($this->call('POST', "/api/v3/volunteers/{$id}/status-changes", $this->admin(), ['to_status' => 'active', 'reason' => 'Start', 'version' => $version])));

        $current = $accepted;
        foreach (['active', 'inactive', 'active', 'ended'] as $to) {
            $response = $this->call('POST', "/api/v3/volunteers/{$id}/status-changes", $this->admin(), ['to_status' => $to, 'reason' => "To {$to}", 'version' => $current['version']]);
            self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
            $current = self::data($response);
            self::assertSame($to, $current['status']);
        }
        self::assertSame('invalid_transition', self::errorCode($this->call('POST', "/api/v3/volunteers/{$id}/status-changes", $this->admin(), ['to_status' => 'active', 'reason' => 'Back', 'version' => $current['version']])), 'ended is final');

        $history = self::items($this->call('GET', "/api/v3/volunteers/{$id}/status-history", $this->admin()));
        self::assertSame(['draft', 'accepted', 'active', 'inactive', 'active', 'ended'], array_column($history, 'to'));
        self::assertSame('Interviewed', $history[1]['reason']);
        self::assertSame(self::$users['full_admin'], $history[1]['by']);

        $missingReason = $this->call('POST', "/api/v3/volunteers/{$id}/status-changes", $this->admin(), ['to_status' => 'ended', 'version' => $current['version']]);
        self::assertSame(422, $missingReason->getStatusCode());

        $draft = self::data($this->call('POST', '/api/v3/volunteers', $this->admin(), ['full_name' => 'Withdraws', 'phone' => '0161 496 0003']));
        $withdrawn = $this->call('POST', '/api/v3/volunteers/' . self::int($draft['id']) . '/status-changes', $this->admin(), ['to_status' => 'withdrawn', 'reason' => 'Changed mind', 'version' => $draft['version']]);
        self::assertSame('withdrawn', self::data($withdrawn)['status']);
    }

    public function testEndingAVolunteerNeverChangesTheLinkedAccount(): void
    {
        $user = self::createUser('linked.volunteer@example.test', 'Linked Volunteer');
        $id = self::createVolunteer(['full_name' => 'Linked Volunteer', 'email' => 'linked.volunteer@example.test', 'user_id' => $user]);
        $version = (int) self::db()->scalar('SELECT version FROM volunteers WHERE id = ?', $id);

        $ended = $this->call('POST', "/api/v3/volunteers/{$id}/status-changes", $this->admin(), ['to_status' => 'ended', 'reason' => 'Moved away', 'version' => $version]);
        self::assertSame(200, $ended->getStatusCode(), (string) $ended->getBody());
        self::assertSame('active', self::db()->scalar('SELECT status FROM users WHERE id = ?', $user), 'W1: no automatic account change');
    }

    public function testNobodyAcceptsOrChangesTheStatusOfTheirOwnLinkedRecord(): void
    {
        $id = self::createVolunteer(['full_name' => 'Full Admin Themselves', 'phone' => '0161 496 0004', 'status' => 'draft', 'user_id' => self::$users['full_admin']]);
        $before = self::auditCount('volunteer.accepted', 'denied');

        $response = $this->call('POST', "/api/v3/volunteers/{$id}/accept", $this->admin(), ['version' => 1]);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('forbidden', self::errorCode($response));
        self::assertSame($before + 1, self::auditCount('volunteer.accepted', 'denied'));
        self::assertSame('draft', self::db()->scalar('SELECT status FROM volunteers WHERE id = ?', $id));
    }

    public function testFieldsTheCallerMayNotWriteAreRefusedAndUnreadableOnesOmitted(): void
    {
        // A user holding only volunteers.create and volunteers.view by grant: basic fields only.
        $clerk = self::createUser('records.clerk@example.test', 'Records Clerk');
        foreach (['volunteers.create', 'volunteers.view', 'volunteers.browse'] as $code) {
            self::grant($clerk, $code, self::$users['technical_manager']);
        }
        $session = $this->login('clerk', 'records.clerk@example.test');
        $before = self::auditCount('volunteer.created', 'denied');

        $refused = $this->call('POST', '/api/v3/volunteers', $session, ['full_name' => 'Refused', 'email' => 'refused@example.test', 'address_line1' => 'Hidden']);
        self::assertSame(403, $refused->getStatusCode());
        self::assertSame('field_not_permitted', self::errorCode($refused));
        self::assertSame(['email', 'address_line1'], array_column(\Ehf\Api\Tests\Support\TestApp::error($refused)['details'], 'field'));
        self::assertSame($before + 1, self::auditCount('volunteer.created', 'denied'));
        self::assertSame(0, (int) self::db()->scalar("SELECT COUNT(*) FROM volunteers WHERE full_name = 'Refused'"));

        // Without contact codes the clerk cannot meet W1 (email or phone) and so cannot create a record.
        self::assertSame(422, $this->call('POST', '/api/v3/volunteers', $session, ['full_name' => 'Name Only'])->getStatusCode());

        $id = self::createVolunteer(['full_name' => 'Seen By Clerk', 'email' => 'seen.by.clerk@example.test', 'phone' => '0161 496 0005'], ['town' => 'Hiddentown']);
        $response = $this->call('GET', "/api/v3/volunteers/{$id}", $session);
        $record = self::data($response);
        foreach (['email', 'phone', 'town', 'address_line1', 'nationality'] as $field) {
            self::assertArrayNotHasKey($field, $record);
        }
        self::assertStringNotContainsString('Hiddentown', (string) $response->getBody());
        self::assertSame(['basic'], self::map(\Ehf\Api\Tests\Support\TestApp::json($response)['meta'])['field_sets']);

        // q covers only readable fields: the clerk cannot find a record by its email.
        self::assertSame([], self::items($this->call('GET', '/api/v3/volunteers?q=seen.by.clerk%40', $session)));
        self::assertCount(1, self::items($this->call('GET', '/api/v3/volunteers?q=seen.by.clerk%40', $this->admin())));
    }

    public function testBrowseFiltersSortsAndPages(): void
    {
        foreach (['Zed Browse', 'Abe Browse', 'Mid Browse'] as $name) {
            self::createVolunteer(['full_name' => $name, 'phone' => '0161 496 0010', 'status' => $name === 'Mid Browse' ? 'inactive' : 'active']);
        }

        $sorted = self::items($this->call('GET', '/api/v3/volunteers?q=Browse&sort=-full_name', $this->admin()));
        self::assertSame(['Zed Browse', 'Mid Browse', 'Abe Browse'], array_column($sorted, 'full_name'));

        $inactive = self::items($this->call('GET', '/api/v3/volunteers?q=Browse&status=inactive', $this->admin()));
        self::assertSame(['Mid Browse'], array_column($inactive, 'full_name'));

        $page = $this->call('GET', '/api/v3/volunteers?q=Browse&per_page=2&page=2', $this->admin());
        self::assertCount(1, self::items($page));
        self::assertSame(3, self::map(\Ehf\Api\Tests\Support\TestApp::json($page)['meta'])['total']);

        self::assertSame(422, $this->call('GET', '/api/v3/volunteers?sort=email', $this->admin())->getStatusCode());
    }

    public function testConfidentialNotesAreAddedViewedWithAuditAndNeverEdited(): void
    {
        $id = self::createVolunteer(['full_name' => 'Noted', 'phone' => '0161 496 0006']);
        $before = self::auditCount('note.viewed', 'success');

        $note = $this->call('POST', "/api/v3/volunteers/{$id}/notes", $this->admin(), ['body' => 'Prefers morning shifts.']);
        self::assertSame(201, $note->getStatusCode(), (string) $note->getBody());
        self::assertSame(self::$users['full_admin'], self::data($note)['created_by']);

        $notes = self::items($this->call('GET', "/api/v3/volunteers/{$id}/notes", $this->admin()));
        self::assertSame(['Prefers morning shifts.'], array_column($notes, 'body'));
        self::assertSame($before + 1, self::auditCount('note.viewed', 'success'));
        self::assertStringNotContainsString('morning', (string) self::db()->scalar("SELECT GROUP_CONCAT(COALESCE(details, '')) FROM audit_logs"));

        // Notes are never part of the record, and there is no edit route.
        self::assertStringNotContainsString('morning', (string) $this->call('GET', "/api/v3/volunteers/{$id}", $this->admin())->getBody());
        self::assertSame(404, $this->call('PATCH', "/api/v3/volunteers/{$id}/notes", $this->admin(), ['body' => 'x'])->getStatusCode());
        self::assertSame(404, $this->call('GET', '/api/v3/volunteers/999999/notes', $this->admin())->getStatusCode());
    }

    private function admin(): TestSession
    {
        return self::$admin ??= $this->login('full_admin');
    }

    public static function tearDownAfterClass(): void
    {
        self::$admin = null;
        parent::tearDownAfterClass();
    }
}
