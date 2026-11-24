<?php

declare(strict_types=1);

namespace Ehf\Api\Tests\Integration;

use Ehf\Api\Tests\Support\IntegrationTestCase;

/**
 * Audit records and their hash chain (API §11, §7.18), and technical health (§7.19).
 */
final class AuditTest extends IntegrationTestCase
{
    public function testActionsAreAuditedWithoutSecretsAndTheChainVerifies(): void
    {
        $tm = $this->login('technical_manager');
        $this->call('POST', '/api/v3/users', $tm, ['display_name' => 'Audited', 'email' => 'audited@example.test']);
        $this->call('POST', '/api/v3/auth/login', null, ['email' => 'audited@example.test', 'password' => 'a-guess-password']);
        $this->call('POST', '/api/v3/auth/password/change', $this->login('volunteer'), ['current_password' => self::PASSWORD, 'new_password' => 'another-good-password']);

        $verify = self::data($this->call('GET', '/api/v3/audit-logs/verify', $tm));
        self::assertTrue($verify['intact']);
        self::assertGreaterThanOrEqual(5, $verify['checked']);

        $entries = self::items($this->call('GET', '/api/v3/audit-logs/security?per_page=100', $tm));
        $actions = array_column($entries, 'action');
        foreach (['auth.login.succeeded', 'auth.login.failed', 'account.created', 'auth.password.changed'] as $action) {
            self::assertContains($action, $actions);
        }
        $created = array_values(array_filter($entries, static fn(array $e): bool => $e['action'] === 'account.created'))[0];
        self::assertSame(['id' => self::$users['technical_manager'], 'acting_role' => 'technical_manager'], $created['actor']);
        self::assertMatchesRegularExpression('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/', self::string($created['request_id']));
        $createdId = self::int($created['id']);

        $raw = json_encode(self::db()->rows('SELECT * FROM audit_logs'), JSON_THROW_ON_ERROR);
        foreach ([self::PASSWORD, 'a-guess-password', 'another-good-password', '$argon2'] as $secret) {
            self::assertStringNotContainsString($secret, $raw);
        }
        self::assertSame(0, (int) self::db()->scalar("SELECT COUNT(*) FROM audit_logs WHERE ip_prefix NOT LIKE '%.0/24' AND ip_prefix IS NOT NULL"), 'Only shortened IP addresses are stored.');

        $one = self::data($this->call('GET', "/api/v3/audit-logs/{$createdId}", $tm));
        self::assertSame('account.created', $one['action']);
        self::assertSame('forbidden', self::errorCode($this->call('GET', "/api/v3/audit-logs/{$createdId}", $this->login('full_admin'))), 'Security entries need audit.security.view.');
    }

    public function testTamperingIsDetected(): void
    {
        $tm = $this->login('technical_manager');
        $target = (int) self::db()->scalar('SELECT MIN(id) FROM audit_logs');
        self::db()->insert("UPDATE audit_logs SET action = 'auth.logout' WHERE id = ?", [$target]);

        $verify = self::data($this->call('GET', '/api/v3/audit-logs/verify', $tm));
        self::assertFalse($verify['intact']);
        self::assertSame($target, $verify['first_break_id']);
    }

    public function testOperationalAuditIsVisibleToNobodyWhileR10IsPending(): void
    {
        foreach (['full_admin', 'monitoring_admin', 'technical_manager', 'owner'] as $persona) {
            self::assertSame('forbidden', self::errorCode($this->call('GET', '/api/v3/audit-logs/operational', $this->login($persona))), $persona);
        }
    }

    public function testSystemHealthRevealsNoSecrets(): void
    {
        $health = self::data($this->call('GET', '/api/v3/system/health', $this->login('technical_manager')));

        self::assertSame(['php_version', 'database', 'migration_level', 'storage_writable', 'sign_in_configured', 'email_configured', 'queue_backlog'], array_keys($health));
        self::assertTrue($health['sign_in_configured']);
        self::assertFalse($health['email_configured']);
        self::assertSame('20261124002000', $health['migration_level']);
        $raw = json_encode($health, JSON_THROW_ON_ERROR);
        foreach (['password', 'throwaway', 'DB_', 'root'] as $secret) {
            self::assertStringNotContainsStringIgnoringCase($secret, $raw);
        }
    }
}
