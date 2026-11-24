<?php

declare(strict_types=1);

namespace Ehf\Api\Tests\Integration;

use Ehf\Api\Authorisation\FieldPolicy;
use Ehf\Api\Tests\Support\IntegrationTestCase;
use Ehf\Api\Tests\Support\TestApp;
use Ehf\Api\Tests\Support\TestSession;

/**
 * GET and PATCH /me/profile (API §7.3) with R12 approved: the record comes only from the session,
 * every own field is readable, only the R12 fields are editable, and notes never appear.
 */
final class ProfileTest extends IntegrationTestCase
{
    private static int $volunteerId = 0;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        if (self::$db === null) {
            return;
        }
        self::$volunteerId = self::createVolunteer(
            ['full_name' => 'Valerie Volunteer', 'email' => 'valerie@example.test', 'phone' => '0161 496 0200', 'user_id' => self::$users['volunteer']],
            ['address_line1' => '2 Own Road', 'nationality' => 'Own Nationality', 'emergency_contact_name' => 'Kin Own'],
        );
        self::db()->insert('INSERT INTO volunteer_notes (volunteer_id, body) VALUES (?, ?)', [self::$volunteerId, 'Staff-only remark']);
    }

    public function testOwnProfileHasEveryOwnFieldButNoNotes(): void
    {
        $response = $this->call('GET', '/api/v3/me/profile', $this->volunteer());
        $profile = self::data($response);

        self::assertSame(self::$volunteerId, $profile['id']);
        self::assertSame('valerie@example.test', $profile['email']);
        self::assertSame('Own Nationality', $profile['nationality']);
        self::assertSame('Kin Own', $profile['emergency_contact_name']);
        self::assertSame(FieldPolicy::SELF_EDITABLE, $profile['editable_fields']);
        self::assertStringNotContainsString('Staff-only', (string) $response->getBody());
        self::assertArrayNotHasKey('notes', $profile);
        self::assertArrayNotHasKey('user_id', $profile);
    }

    public function testR12FieldsCanBeEditedAndAreAuditedByName(): void
    {
        $profile = self::data($this->call('GET', '/api/v3/me/profile', $this->volunteer()));
        $changes = [
            'preferred_name' => 'Val', 'phone' => '0161 496 0201', 'address_line1' => '3 New Road', 'address_line2' => 'Flat 1',
            'town' => 'Manchester', 'postcode' => 'M13 9PL', 'emergency_contact_name' => 'New Kin',
            'emergency_contact_phone' => '0161 496 0202', 'emergency_contact_relationship' => 'Sibling',
        ];

        $response = $this->call('PATCH', '/api/v3/me/profile', $this->volunteer(), $changes + ['version' => $profile['version']]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $updated = self::data($response);
        foreach ($changes as $field => $value) {
            self::assertSame($value, $updated[$field], $field);
        }
        self::assertSame('active', $updated['status']);
        $details = (string) self::db()->scalar("SELECT details FROM audit_logs WHERE action = 'volunteer.self_updated' AND outcome = 'success' ORDER BY id DESC LIMIT 1");
        self::assertStringContainsString('emergency_contact_name', $details);
        self::assertStringNotContainsString('New Kin', $details);

        self::assertSame('stale_version', self::errorCode($this->call('PATCH', '/api/v3/me/profile', $this->volunteer(), ['town' => 'Salford', 'version' => $profile['version']])));
    }

    public function testOtherFieldsAreNotEditable(): void
    {
        $profile = self::data($this->call('GET', '/api/v3/me/profile', $this->volunteer()));

        foreach (['full_name' => 'Someone Else', 'email' => 'new@example.test', 'nationality' => 'Other', 'skills' => 'Anything', 'status' => 'ended', 'reference' => 'V-999999'] as $field => $value) {
            $response = $this->call('PATCH', '/api/v3/me/profile', $this->volunteer(), [$field => $value, 'town' => 'Leeds', 'version' => $profile['version']]);
            self::assertSame(422, $response->getStatusCode(), $field);
            self::assertSame('field_not_editable', self::errorCode($response));
            self::assertSame([$field], array_column(TestApp::error($response)['details'], 'field'));
        }
        self::assertSame(400, $this->call('PATCH', '/api/v3/me/profile', $this->volunteer(), ['volunteer_id' => 1, 'version' => $profile['version']])->getStatusCode(), 'no volunteer id is accepted');
        self::assertSame($profile['version'], self::data($this->call('GET', '/api/v3/me/profile', $this->volunteer()))['version'], 'nothing changed');
    }

    public function testW1StillAppliesAndAVolunteerWithoutARecordGets404(): void
    {
        $id = self::createVolunteer(['full_name' => 'Phone Only Self', 'phone' => '0161 496 0203', 'user_id' => self::createUser('phone.only@example.test', 'Phone Only')]);
        $user = (int) self::db()->scalar('SELECT user_id FROM volunteers WHERE id = ?', $id);
        self::assignRole($user, 'volunteer', self::$users['technical_manager']);
        $session = $this->login('phone', 'phone.only@example.test');

        $clear = $this->call('PATCH', '/api/v3/me/profile', $session, ['phone' => null, 'version' => 1]);
        self::assertSame(422, $clear->getStatusCode(), 'W1: an email or phone must remain');

        self::assignRole(self::createUser('no.record@example.test', 'No Record'), 'volunteer', self::$users['technical_manager']);
        $none = $this->login('none', 'no.record@example.test');
        self::assertSame(404, $this->call('GET', '/api/v3/me/profile', $none)->getStatusCode());
        self::assertSame(404, $this->call('PATCH', '/api/v3/me/profile', $none, ['town' => 'X', 'version' => 1])->getStatusCode());
    }

    private function volunteer(): TestSession
    {
        return $this->login('volunteer');
    }
}
