<?php

declare(strict_types=1);

namespace Ehf\Api\Auth;

use Ehf\Api\Http\Error\ApiException;
use Ehf\Api\Http\Error\ErrorCode;
use Ehf\Api\Infrastructure\Database\Database;
use Ehf\Api\Infrastructure\Time\Clock;

/**
 * Fixed-window counters in `rate_limit_counters` (Database V1.4 §5.2.8). Keys are hashed, so no
 * email address or IP address is stored. Limits come from configuration (A7, pending).
 */
final readonly class RateLimiter
{
    public function __construct(
        private Database $db,
        private Clock $clock,
    ) {}

    /**
     * Refuses with `429 rate_limited` (and Retry-After) once `$limit` hits are recorded in the
     * current window.
     */
    public function assertBelow(string $key, int $limit, int $windowMinutes): void
    {
        [$windowStart, $retryAfter] = $this->window($windowMinutes);
        $count = $this->db->value('SELECT count FROM rate_limit_counters WHERE key_hash = ? AND window_start = ?', [self::hashKey($key), $windowStart]);
        if ($count !== null && (int) $count >= $limit) {
            throw new ApiException(ErrorCode::RateLimited, headers: ['Retry-After' => (string) $retryAfter]);
        }
    }

    public function hit(string $key, int $windowMinutes): void
    {
        [$windowStart] = $this->window($windowMinutes);
        $this->db->execute(
            'INSERT INTO rate_limit_counters (key_hash, window_start, count) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE count = count + 1',
            [self::hashKey($key), $windowStart],
        );
    }

    /**
     * @return array{0: string, 1: int} window start and seconds until the next window
     */
    private function window(int $minutes): array
    {
        $seconds = $minutes * 60;
        $now = $this->clock->now()->getTimestamp();
        $start = intdiv($now, $seconds) * $seconds;

        return [
            Database::timestamp((new \DateTimeImmutable('@' . $start))->setTimezone(new \DateTimeZone('UTC'))),
            max(1, $start + $seconds - $now),
        ];
    }

    private static function hashKey(string $key): string
    {
        return hash('sha256', $key);
    }
}
