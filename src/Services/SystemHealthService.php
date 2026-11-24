<?php

declare(strict_types=1);

namespace Ehf\Api\Services;

use Ehf\Api\Config\Settings;
use Ehf\Api\Database\PhinxConfig;
use Ehf\Api\Infrastructure\Database\Database;
use Ehf\Api\Infrastructure\Database\EngineInfo;

/**
 * `GET /system/health` for Technical Managers (API §7.19): versions, migration level, storage,
 * email and queue state. No secrets and no personal data.
 */
final readonly class SystemHealthService
{
    public function __construct(
        private Database $db,
        private Settings $settings,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function report(): array
    {
        $engine = EngineInfo::detect($this->db->pdo());
        $migration = $this->db->value('SELECT MAX(version) FROM ' . PhinxConfig::MIGRATION_TABLE);
        $logDirectory = \dirname($this->settings->logPath);

        return [
            'php_version' => PHP_VERSION,
            'database' => ['engine' => $engine->engine, 'version' => $engine->version, 'supported' => $engine->meetsMinimum()],
            'migration_level' => $migration === null ? null : (string) $migration,
            'storage_writable' => is_dir($logDirectory) && is_writable($logDirectory),
            'sign_in_configured' => $this->settings->auth !== null,
            'email_configured' => false,
            'queue_backlog' => (int) $this->db->value("SELECT COUNT(*) FROM email_outbox WHERE status = 'queued'"),
        ];
    }
}
