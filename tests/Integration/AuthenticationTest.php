<?php

declare(strict_types=1);

namespace Ehf\Api\Tests\Integration;

use Ehf\Api\Tests\Support\IntegrationTestCase;
use Ehf\Api\Tests\Support\TestSession;

/**
 * Sign-in, sessions, CSRF/Origin, password change, token redemption and repeat protection
 * (API §5, §7.1, §4.1).
 */
final class AuthenticationTest extends IntegrationTestCase
{
    public function testSignInSetsASecureSessionCookieAndReturnsTheSession(): void
    {
        $response = $this->call('POST', '/api/v3/auth/login', null, ['email' => 'Full_Admin@Example.TEST', 'password' => self::PASSWORD]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $session = TestSession::fromResponse($response);
        foreach (['Path=/', 'Secure', 'HttpOnly', 'SameSite=Lax', 'Max-Age=43200'] as $attribute) {
            self::assertStringContainsString($attribute, $session->setCookie);
        }
        self::assertStringStartsWith('__Host-ehf_session=', $session->setCookie);

        $data = self::data($response);
        self::assertTrue($data['authenticated']);
        self::assertSame(['full_admin'], $data['roles']);
        self::assertIsArray($data['permissions']);
        self::assertSame('organisation', $data['permissions']['volunteers.view'] ?? null);
        self::assertArrayNotHasKey('accounts.view', $data['permissions']);
        self::assertSame([
            'basic' => true,
            'confidential.volunteer_notes' => true,
            'contact' => ['email', 'phone'],
            'sensitive' => ['address_line1', 'address_line2', 'emergency_contact_name', 'emergency_contact_phone', 'emergency_contact_relationship', 'nationality', 'postcode', 'town'],
        ], $data['field_access']); // R6
        self::assertSame($session->csrf, $data['csrf_token']);

        $again = self::data($this->call('GET', '/api/v3/auth/session', $session));
        self::assertTrue($again['authenticated']);
        self::assertSame($data['user'], $again['user']);

        // Only the hash of the session identifier is stored.
        self::assertSame(0, (int) self::db()->scalar('SELECT COUNT(*) FROM sessions WHERE id_hash = ?', $session->id));
        self::assertSame(1, (int) self::db()->scalar('SELECT COUNT(*) FROM sessions WHERE id_hash = ?', hash('sha256', $session->id)));
        self::assertGreaterThan(0, self::auditCount('auth.login.succeeded', 'success'));
    }

    public function testEverySignInFailureLooksTheSame(): void
    {
        self::createUser('suspended@example.test', 'Suspended', 'suspended');
        self::createUser('invited@example.test', 'Invited', 'invited', null);

        $responses = [
            $this->call('POST', '/api/v3/auth/login', null, ['email' => 'volunteer@example.test', 'password' => 'wrong-password-123']),
            $this->call('POST', '/api/v3/auth/login', null, ['email' => 'nobody@example.test', 'password' => self::PASSWORD]),
            $this->call('POST', '/api/v3/auth/login', null, ['email' => 'suspended@example.test', 'password' => self::PASSWORD]),
            $this->call('POST', '/api/v3/auth/login', null, ['email' => 'invited@example.test', 'password' => self::PASSWORD]),
        ];

        foreach ($responses as $response) {
            self::assertSame(401, $response->getStatusCode());
            $error = \Ehf\Api\Tests\Support\TestApp::error($response);
            self::assertSame('invalid_credentials', $error['code']);
            self::assertSame('The email address or password is incorrect.', $error['message']);
            self::assertFalse($response->hasHeader('Set-Cookie'));
        }
        self::assertGreaterThanOrEqual(4, self::auditCount('auth.login.failed', 'denied'));
    }

    public function testRepeatedFailuresAreRateLimitedPerAccountAndPerIp(): void
    {
        self::createUser('limited@example.test', 'Limited');
        for ($i = 0; $i < 5; ++$i) {
            self::assertSame(401, $this->call('POST', '/api/v3/auth/login', null, ['email' => 'limited@example.test', 'password' => 'wrong-password-123'])->getStatusCode());
        }
        $blocked = $this->call('POST', '/api/v3/auth/login', null, ['email' => 'limited@example.test', 'password' => self::PASSWORD]);
        self::assertSame(429, $blocked->getStatusCode(), 'Even the correct password is refused while the account limit is reached.');
        self::assertSame('rate_limited', self::errorCode($blocked));
        self::assertMatchesRegularExpression('/^\d+$/', $blocked->getHeaderLine('Retry-After'));

        for ($i = 0; $i < 20; ++$i) {
            $this->call('POST', '/api/v3/auth/login', null, ['email' => "probe{$i}@example.test", 'password' => 'x'], [], '198.51.100.7');
        }
        self::assertSame(429, $this->call('POST', '/api/v3/auth/login', null, ['email' => 'monitoring_admin@example.test', 'password' => self::PASSWORD], [], '198.51.100.7')->getStatusCode());

        // A new window resets the counters.
        self::$clock->advance('+16 minutes');
        self::assertSame(200, $this->call('POST', '/api/v3/auth/login', null, ['email' => 'limited@example.test', 'password' => self::PASSWORD])->getStatusCode());
    }

    public function testSignOutEndsTheSession(): void
    {
        $session = $this->login('volunteer');

        $response = $this->call('POST', '/api/v3/auth/logout', $session);
        self::assertSame(204, $response->getStatusCode());
        self::assertStringContainsString('Max-Age=0', $response->getHeaderLine('Set-Cookie'));

        $after = $this->call('POST', '/api/v3/auth/logout', $session);
        self::assertSame(401, $after->getStatusCode());
        self::assertSame('session_expired', self::errorCode($after));
        self::assertFalse(self::data($this->call('GET', '/api/v3/auth/session', $session))['authenticated']);
    }

    public function testIdleAndAbsoluteTimeouts(): void
    {
        $idle = $this->login('monitoring_admin');
        self::$clock->advance('+29 minutes');
        self::assertTrue(self::data($this->call('GET', '/api/v3/auth/session', $idle))['authenticated']);
        self::$clock->advance('+31 minutes');
        self::assertFalse(self::data($this->call('GET', '/api/v3/auth/session', $idle))['authenticated'], 'Idle for more than 30 minutes.');

        $busy = $this->login('monitoring_admin');
        for ($i = 0; $i < 36; ++$i) { // active every 20 minutes for 12 hours
            self::$clock->advance('+20 minutes');
            $this->call('GET', '/api/v3/auth/session', $busy);
        }
        self::assertFalse(self::data($this->call('GET', '/api/v3/auth/session', $busy))['authenticated'], 'Past the absolute limit.');
    }

    public function testCsrfAndOriginProtectStateChangingRequests(): void
    {
        $session = $this->login('technical_manager');
        $body = ['display_name' => 'CSRF Probe', 'email' => 'csrf-probe@example.test'];

        $noOrigin = $this->call('POST', '/api/v3/users', $session, $body, ['Origin' => '']);
        self::assertSame('origin_not_allowed', self::errorCode($noOrigin));
        $badOrigin = $this->call('POST', '/api/v3/users', $session, $body, ['Origin' => 'https://evil.example']);
        self::assertSame('origin_not_allowed', self::errorCode($badOrigin));
        $noToken = $this->call('POST', '/api/v3/users', $session, $body, ['X-CSRF-Token' => '']);
        self::assertSame('csrf_invalid', self::errorCode($noToken));
        $badToken = $this->call('POST', '/api/v3/users', $session, $body, ['X-CSRF-Token' => str_repeat('A', 43)]);
        self::assertSame('csrf_invalid', self::errorCode($badToken));
        self::assertSame(0, (int) self::db()->scalar("SELECT COUNT(*) FROM users WHERE email = 'csrf-probe@example.test'"));

        self::assertSame(201, $this->call('POST', '/api/v3/users', $session, $body)->getStatusCode());
        self::assertSame(200, $this->call('GET', '/api/v3/users', $session, null, ['Origin' => ''])->getStatusCode(), 'Reads need no Origin.');

        $preLogin = $this->call('POST', '/api/v3/auth/login', null, ['email' => 'volunteer@example.test', 'password' => self::PASSWORD], ['Origin' => 'https://evil.example']);
        self::assertSame('origin_not_allowed', self::errorCode($preLogin));
    }

    public function testSuspensionEndsSessions(): void
    {
        $user = self::createUser('to-suspend@example.test', 'To Suspend');
        $victim = $this->login('x', 'to-suspend@example.test');
        $tm = $this->login('technical_manager');

        self::assertSame(200, $this->call('POST', "/api/v3/users/{$user}/suspend", $tm, ['reason' => 'Test', 'version' => 1])->getStatusCode());
        self::assertSame('session_expired', self::errorCode($this->call('POST', '/api/v3/auth/logout', $victim)));
    }

    public function testPasswordChangeEndsOtherSessionsAndKeepsThisOne(): void
    {
        $user = self::createUser('changer@example.test', 'Changer');
        $first = $this->login('x', 'changer@example.test');
        $second = $this->login('x', 'changer@example.test');

        self::assertSame('invalid_credentials', self::errorCode($this->call('POST', '/api/v3/auth/password/change', $second, ['current_password' => 'wrong-password-1', 'new_password' => 'a-brand-new-password'])));
        self::assertSame('password_policy', self::errorCode($this->call('POST', '/api/v3/auth/password/change', $second, ['current_password' => self::PASSWORD, 'new_password' => 'short'])));
        self::assertSame(204, $this->call('POST', '/api/v3/auth/password/change', $second, ['current_password' => self::PASSWORD, 'new_password' => 'a-brand-new-password'])->getStatusCode());

        self::assertFalse(self::data($this->call('GET', '/api/v3/auth/session', $first))['authenticated']);
        self::assertTrue(self::data($this->call('GET', '/api/v3/auth/session', $second))['authenticated']);
        self::assertSame(401, $this->call('POST', '/api/v3/auth/login', null, ['email' => 'changer@example.test', 'password' => self::PASSWORD])->getStatusCode());
        $this->login('x', 'changer@example.test', 'a-brand-new-password');
        self::assertGreaterThan(0, $user);
    }

    public function testInvitationActivationChangesOnlyTheAccountStatus(): void
    {
        $user = self::createUser('newcomer@example.test', 'Newcomer', 'invited', null);
        self::db()->insert("INSERT INTO volunteers (reference, user_id, full_name, status) VALUES ('V-000501', ?, 'Newcomer', 'accepted')", [$user]);
        $token = self::token($user, 'invitation');

        $inspect = self::data($this->call('POST', '/api/v3/auth/invitations/inspect', null, ['token' => $token]));
        self::assertSame(['valid' => true, 'expired' => false, 'email_hint' => 'n***@example.test'], $inspect);
        self::assertSame(['valid' => false, 'expired' => false, 'email_hint' => null], self::data($this->call('POST', '/api/v3/auth/invitations/inspect', null, ['token' => str_repeat('0', 48)])));

        self::assertSame('validation_failed', self::errorCode($this->call('POST', '/api/v3/auth/activate', null, ['token' => $token, 'password' => 'a-long-enough-pass', 'password_confirmation' => 'different-password'])));
        self::assertSame('password_policy', self::errorCode($this->call('POST', '/api/v3/auth/activate', null, ['token' => $token, 'password' => 'short', 'password_confirmation' => 'short'])));

        $activated = $this->call('POST', '/api/v3/auth/activate', null, ['token' => $token, 'password' => 'a-long-enough-pass', 'password_confirmation' => 'a-long-enough-pass']);
        self::assertSame(200, $activated->getStatusCode(), (string) $activated->getBody());
        self::assertTrue(self::data($activated)['authenticated']);
        self::assertSame('active', self::db()->scalar('SELECT status FROM users WHERE id = ?', $user));
        self::assertSame('accepted', self::db()->scalar('SELECT status FROM volunteers WHERE user_id = ?', $user), 'Activation never changes the volunteer status.');

        self::assertSame('token_used', self::errorCode($this->call('POST', '/api/v3/auth/activate', null, ['token' => $token, 'password' => 'a-long-enough-pass', 'password_confirmation' => 'a-long-enough-pass'])));

        $late = self::createUser('late@example.test', 'Late', 'invited', null);
        $expired = self::token($late, 'invitation', '+1 hour');
        self::$clock->advance('+2 hours');
        self::assertSame(['valid' => false, 'expired' => true, 'email_hint' => null], self::data($this->call('POST', '/api/v3/auth/invitations/inspect', null, ['token' => $expired])));
        self::assertSame('token_expired', self::errorCode($this->call('POST', '/api/v3/auth/activate', null, ['token' => $expired, 'password' => 'a-long-enough-pass', 'password_confirmation' => 'a-long-enough-pass'])));
    }

    public function testPasswordResetEndsEverySession(): void
    {
        $user = self::createUser('forgetful@example.test', 'Forgetful');
        $old = $this->login('x', 'forgetful@example.test');
        $token = self::token($user, 'password_reset', '+1 hour');

        self::assertSame(['valid' => true, 'expired' => false], self::data($this->call('POST', '/api/v3/auth/password/reset/inspect', null, ['token' => $token])));
        self::assertSame(204, $this->call('POST', '/api/v3/auth/password/reset', null, ['token' => $token, 'password' => 'a-reset-password-1', 'password_confirmation' => 'a-reset-password-1'])->getStatusCode());

        self::assertFalse(self::data($this->call('GET', '/api/v3/auth/session', $old))['authenticated']);
        $this->login('x', 'forgetful@example.test', 'a-reset-password-1');
        self::assertSame('token_used', self::errorCode($this->call('POST', '/api/v3/auth/password/reset', null, ['token' => $token, 'password' => 'a-reset-password-2', 'password_confirmation' => 'a-reset-password-2'])));
    }

    public function testTokenEndpointsAreRateLimitedPerIp(): void
    {
        $codes = [];
        for ($i = 0; $i < 11; ++$i) {
            $codes[] = $this->call('POST', '/api/v3/auth/invitations/inspect', null, ['token' => str_repeat('1', 48)], [], '192.0.2.9')->getStatusCode();
        }

        self::assertSame(array_fill(0, 10, 200), \array_slice($codes, 0, 10));
        self::assertSame(429, $codes[10]);
    }

    public function testIdempotencyKeyReplaysTheSameCreate(): void
    {
        $tm = $this->login('technical_manager');
        $body = ['display_name' => 'Once Only', 'email' => 'once@example.test'];

        $first = $this->call('POST', '/api/v3/users', $tm, $body, ['Idempotency-Key' => 'create-once-1']);
        $second = $this->call('POST', '/api/v3/users', $tm, $body, ['Idempotency-Key' => 'create-once-1']);
        self::assertSame(201, $first->getStatusCode());
        self::assertSame(201, $second->getStatusCode());
        $replayed = self::data($second);
        $original = self::data($first);
        ksort($replayed);
        ksort($original);
        self::assertSame($original, $replayed, 'The same response (the database may store JSON keys in another order).');
        self::assertSame(1, (int) self::db()->scalar("SELECT COUNT(*) FROM users WHERE email = 'once@example.test'"));

        $mismatch = $this->call('POST', '/api/v3/users', $tm, ['display_name' => 'Other', 'email' => 'other-once@example.test'], ['Idempotency-Key' => 'create-once-1']);
        self::assertSame('idempotency_mismatch', self::errorCode($mismatch));
        self::assertSame('duplicate', self::errorCode($this->call('POST', '/api/v3/users', $tm, $body)));
    }

    public function testSignInStaysOffUntilItsSettingsAreConfigured(): void
    {
        $session = $this->login('volunteer');
        $saved = self::$app;
        self::$app = self::buildApp(null);
        try {
            $login = $this->call('POST', '/api/v3/auth/login', null, ['email' => 'volunteer@example.test', 'password' => self::PASSWORD]);
            self::assertSame(503, $login->getStatusCode());
            self::assertSame('maintenance', self::errorCode($login));
            self::assertFalse(self::data($this->call('GET', '/api/v3/auth/session', $session))['authenticated'], 'No session is accepted while sign-in is off.');
            self::assertSame(401, $this->call('GET', '/api/v3/users', $session)->getStatusCode());
            self::assertSame(503, $this->call('POST', '/api/v3/auth/invitations/inspect', null, ['token' => str_repeat('2', 48)])->getStatusCode());
        } finally {
            self::$app = $saved;
        }
    }
}
