<?php

declare(strict_types=1);

namespace Ehf\Api\Tests\Integration;

use Ehf\Api\Tests\Support\IntegrationTestCase;
use Ehf\Api\Tests\Support\TestApp;
use Ehf\Api\Tests\Support\TestSession;

/**
 * Portal account provisioning (API §7.2; Workflow WF-01, WF-02) with W1, R18 and AC-6 Option 1
 * approved; AC-2, A5 and A11 pending.
 */
final class ProvisioningTest extends IntegrationTestCase
{
    private static ?TestSession $tm = null;

    public function testCandidatesAreEligibleUnlinkedVolunteersWithMinimumData(): void
    {
        $withEmail = self::createVolunteer(['full_name' => 'Cand With Email', 'email' => 'cand.with@example.test', 'phone' => '0161 496 0100', 'status' => 'accepted'], ['town' => 'Hiddentown']);
        $noEmail = self::createVolunteer(['full_name' => 'Cand No Email', 'phone' => '0161 496 0101', 'status' => 'active']);
        foreach (['draft', 'inactive', 'ended', 'withdrawn'] as $status) {
            self::createVolunteer(['full_name' => "Cand Not {$status}", 'phone' => '0161 496 0102', 'status' => $status]);
        }
        self::createVolunteer(['full_name' => 'Cand Linked', 'phone' => '0161 496 0103', 'status' => 'active', 'user_id' => self::createUser('cand.linked@example.test', 'Linked')]);

        $response = $this->call('GET', '/api/v3/portal-provisioning/candidates?q=Cand', $this->tm());
        $items = self::items($response);

        self::assertSame([
            ['volunteer_id' => $noEmail, 'volunteer_reference' => self::reference($noEmail), 'name' => 'Cand No Email', 'invitation_email' => null],
            ['volunteer_id' => $withEmail, 'volunteer_reference' => self::reference($withEmail), 'name' => 'Cand With Email', 'invitation_email' => 'cand.with@example.test'],
        ], $items, 'W1 eligibility; AC-6: recorded email or null; R18: shown in full');
        $body = (string) $response->getBody();
        self::assertStringNotContainsString('0161 496', $body, 'no phone');
        self::assertStringNotContainsString('Hiddentown', $body, 'no sensitive data');
        self::assertStringNotContainsString('accepted', $body, 'no operational status');
    }

    public function testProvisioningCreatesAnInvitedLinkedAccountAndChangesNoVolunteerData(): void
    {
        $id = self::createVolunteer(['full_name' => 'Prov Recorded', 'email' => 'prov.recorded@example.test', 'status' => 'accepted']);
        $before = self::db()->rows('SELECT full_name, email, phone, status, version, updated_at FROM volunteers WHERE id = ?', [$id]);

        $response = $this->call('POST', "/api/v3/volunteers/{$id}/portal-account", $this->tm(), ['send_invitation' => false]);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $result = self::data($response);
        $user = self::map($result['user']);
        self::assertSame(['id', 'email', 'status'], array_keys($user));
        self::assertSame('prov.recorded@example.test', $user['email'], 'AC-6: taken from the recorded contact email');
        self::assertSame('invited', $user['status']);
        self::assertNull($result['invitation'], 'no invitation sent (A5/A11 pending)');

        $userId = self::int($user['id']);
        self::assertSame($userId, (int) self::db()->scalar('SELECT user_id FROM volunteers WHERE id = ?', $id));
        self::assertSame($before, self::db()->rows('SELECT full_name, email, phone, status, version, updated_at FROM volunteers WHERE id = ?', [$id]), 'volunteer data and status unchanged');
        self::assertSame(0, (int) self::db()->scalar('SELECT COUNT(*) FROM user_roles WHERE user_id = ?', $userId), 'AC-2 pending: the volunteer role is assigned separately');
        self::assertSame(0, (int) self::db()->scalar('SELECT COUNT(*) FROM auth_tokens WHERE user_id = ?', $userId));
        self::assertSame(1, (int) self::db()->scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'account.provisioned' AND resource_id = ? AND category = 'security' AND outcome = 'success'", $userId));

        // The account endpoint shows account data only, with the volunteer as a reference.
        $account = self::data($this->call('GET', "/api/v3/users/{$userId}", $this->tm()));
        self::assertSame(self::reference($id), $account['volunteer_reference']);
        self::assertSame('none', $account['invitation_status']);

        // Already linked.
        self::assertSame('account_exists', self::errorCode($this->call('POST', "/api/v3/volunteers/{$id}/portal-account", $this->tm(), ['send_invitation' => false])));
    }

    public function testAc6RequiresAnInvitationEmailWhenNoneIsRecordedAndNeverWritesItBack(): void
    {
        $id = self::createVolunteer(['full_name' => 'Prov No Email', 'phone' => '0161 496 0104', 'status' => 'active']);

        $missing = $this->call('POST', "/api/v3/volunteers/{$id}/portal-account", $this->tm(), ['send_invitation' => false]);
        self::assertSame(422, $missing->getStatusCode());
        self::assertSame('validation_failed', self::errorCode($missing));
        self::assertSame(['invitation_email'], array_column(TestApp::error($missing)['details'], 'field'));

        $supplied = $this->call('POST', "/api/v3/volunteers/{$id}/portal-account", $this->tm(), ['invitation_email' => 'Prov.Supplied@Example.test', 'send_invitation' => false]);
        self::assertSame(201, $supplied->getStatusCode(), (string) $supplied->getBody());
        self::assertSame('prov.supplied@example.test', self::map(self::data($supplied)['user'])['email']);
        self::assertNull(self::db()->scalar('SELECT email FROM volunteers WHERE id = ?', $id), 'the sign-in email is account data only');
    }

    public function testRefusalsRevealNoOperationalDetail(): void
    {
        $draft = self::createVolunteer(['full_name' => 'Prov Draft', 'email' => 'prov.draft@example.test', 'status' => 'draft']);
        $ended = self::createVolunteer(['full_name' => 'Prov Ended', 'email' => 'prov.ended@example.test', 'status' => 'ended']);
        foreach ([$draft, $ended] as $id) {
            $response = $this->call('POST', "/api/v3/volunteers/{$id}/portal-account", $this->tm(), ['send_invitation' => false]);
            self::assertSame(409, $response->getStatusCode());
            self::assertSame('not_eligible_for_provisioning', self::errorCode($response));
            self::assertStringNotContainsString('draft', (string) $response->getBody());
            self::assertStringNotContainsString('ended', (string) $response->getBody());
        }

        $taken = self::createVolunteer(['full_name' => 'Prov Taken', 'email' => 'target@example.test', 'status' => 'active']);
        self::assertSame('duplicate', self::errorCode($this->call('POST', "/api/v3/volunteers/{$taken}/portal-account", $this->tm(), ['send_invitation' => false])), 'sign-in emails are unique among accounts');

        self::assertSame(404, $this->call('POST', '/api/v3/volunteers/999999/portal-account', $this->tm(), ['send_invitation' => false])->getStatusCode());
        self::assertSame(422, $this->call('POST', "/api/v3/volunteers/{$taken}/portal-account", $this->tm(), ['send_invitation' => 'no'])->getStatusCode());
    }

    public function testSendingAnInvitationStaysOffUntilA5AndA11(): void
    {
        $id = self::createVolunteer(['full_name' => 'Prov Send', 'email' => 'prov.send@example.test', 'status' => 'active']);
        $users = (int) self::db()->scalar('SELECT COUNT(*) FROM users');

        foreach ([[], ['send_invitation' => true]] as $body) {
            $response = $this->call('POST', "/api/v3/volunteers/{$id}/portal-account", $this->tm(), $body);
            self::assertSame(503, $response->getStatusCode());
            self::assertSame('maintenance', self::errorCode($response));
        }
        self::assertSame($users, (int) self::db()->scalar('SELECT COUNT(*) FROM users'), 'nothing created');
        self::assertNull(self::db()->scalar('SELECT user_id FROM volunteers WHERE id = ?', $id));
    }

    public function testActivationOfAProvisionedAccountChangesTheAccountOnlyAndOpensTheOwnProfile(): void
    {
        $id = self::createVolunteer(['full_name' => 'Prov Activate', 'email' => 'prov.activate@example.test', 'phone' => '0161 496 0105', 'status' => 'accepted'], ['address_line1' => '5 Own Street']);
        $userId = self::int(self::map(self::data($this->call('POST', "/api/v3/volunteers/{$id}/portal-account", $this->tm(), ['send_invitation' => false]))['user'])['id']);
        $assigned = $this->call('POST', "/api/v3/users/{$userId}/roles", $this->tm(), ['role' => 'volunteer', 'reason' => 'Portal access']);
        self::assertSame(201, $assigned->getStatusCode(), (string) $assigned->getBody());

        // Issuing invitation tokens needs A5/A11; the test inserts one to exercise redemption.
        $token = self::token($userId, 'invitation');
        $activated = $this->call('POST', '/api/v3/auth/activate', null, ['token' => $token, 'password' => 'a-long-volunteer-passphrase', 'password_confirmation' => 'a-long-volunteer-passphrase']);
        self::assertSame(200, $activated->getStatusCode(), (string) $activated->getBody());
        self::assertSame('active', self::db()->scalar('SELECT status FROM users WHERE id = ?', $userId));
        self::assertSame('accepted', self::db()->scalar('SELECT status FROM volunteers WHERE id = ?', $id), 'activation never changes the volunteer status [A]');

        $session = $this->login('prov', 'prov.activate@example.test', 'a-long-volunteer-passphrase');
        self::assertSame($id, self::data($this->call('GET', '/api/v3/auth/session', $session))['volunteer_id']);
        $profile = self::data($this->call('GET', '/api/v3/me/profile', $session));
        self::assertSame($id, $profile['id']);
        self::assertSame('5 Own Street', $profile['address_line1'], 'own sensitive fields are readable');
    }

    private static function reference(int $volunteerId): string
    {
        return (string) self::db()->scalar('SELECT reference FROM volunteers WHERE id = ?', $volunteerId);
    }

    private function tm(): TestSession
    {
        return self::$tm ??= $this->login('technical_manager');
    }

    public static function tearDownAfterClass(): void
    {
        self::$tm = null;
        parent::tearDownAfterClass();
    }
}
