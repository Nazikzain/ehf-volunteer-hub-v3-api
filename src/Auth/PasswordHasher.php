<?php

declare(strict_types=1);

namespace Ehf\Api\Auth;

/**
 * Secure password hashing [A] (API §5): Argon2id where PHP supports it, otherwise PHP's default
 * (bcrypt). PHP's default cost parameters are used: the specific costs are a recommendation
 * that has not been approved.
 */
final class PasswordHasher
{
    /** A hash of a random value, verified against when no account matches, so timing does not reveal accounts. */
    private ?string $dummy = null;

    public function hash(string $password): string
    {
        return password_hash($password, self::algorithm());
    }

    public function verify(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, self::algorithm());
    }

    public function verifyDummy(string $password): void
    {
        $this->dummy ??= $this->hash(bin2hex(random_bytes(16)));
        password_verify($password, $this->dummy);
    }

    private static function algorithm(): string
    {
        return \defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
    }
}
