<?php

declare(strict_types=1);

namespace Ehf\Api\Services;

/**
 * Minimal availability check for `GET /api/v3/health` (API §7.1, public).
 *
 * Deliberately reports nothing about versions, configuration, storage or the database:
 * that detail belongs to `GET /system/health`, which needs `system.health`
 * (Technical Manager) and arrives with authentication in Phase 4 (API §7.19).
 */
final class HealthService
{
    /**
     * @return array{status: 'ok'}
     */
    public function status(): array
    {
        return ['status' => 'ok'];
    }
}
