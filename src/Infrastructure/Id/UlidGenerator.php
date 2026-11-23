<?php

declare(strict_types=1);

namespace Ehf\Api\Infrastructure\Id;

/**
 * Generates ULIDs: a 48-bit millisecond timestamp followed by 80 random bits,
 * encoded as 26 characters of Crockford base32. Lexicographically sortable by time.
 *
 * Used for request IDs (API §4.2–4.3); fits `audit_logs.request_id CHAR(26)` (Database V1.4 §5.12).
 */
final class UlidGenerator implements RequestIdGenerator
{
    private const string ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    /** @var \Closure(): int */
    private readonly \Closure $clock;

    /**
     * @param (\Closure(): int)|null $clock returns the current time in milliseconds (injectable for tests)
     */
    public function __construct(?\Closure $clock = null)
    {
        $this->clock = $clock ?? static fn(): int => (int) floor(microtime(true) * 1000);
    }

    public function generate(): string
    {
        $time = ($this->clock)();
        if ($time < 0 || $time > 0xFFFFFFFFFFFF) {
            throw new \RangeException('ULID timestamp out of range.');
        }

        $timePart = '';
        for ($i = 0; $i < 10; ++$i) {
            $timePart = self::ALPHABET[$time % 32] . $timePart;
            $time = intdiv($time, 32);
        }

        $bits = '';
        foreach (str_split(random_bytes(10)) as $byte) {
            $bits .= str_pad(decbin(\ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $randomPart = '';
        foreach (str_split($bits, 5) as $chunk) {
            $randomPart .= self::ALPHABET[(int) bindec($chunk)];
        }

        return $timePart . $randomPart;
    }

    public static function isValid(string $value): bool
    {
        return preg_match('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/', $value) === 1;
    }
}
