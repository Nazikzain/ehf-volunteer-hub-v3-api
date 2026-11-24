<?php

declare(strict_types=1);

namespace Ehf\Api\Auth;

use Ehf\Api\Audit\AuditContext;
use Ehf\Api\Audit\AuditLogger;
use Ehf\Api\Authorisation\Principal;
use Ehf\Api\Http\Error\ApiException;
use Ehf\Api\Http\Error\ErrorCode;
use Ehf\Api\Http\Error\ErrorDetail;
use Ehf\Api\Infrastructure\Database\Database;
use Ehf\Api\Infrastructure\Database\Row;
use Ehf\Api\Infrastructure\Time\Clock;
use Ehf\Api\Repositories\AccountRepository;

/**
 * Sign-in, sign-out, password change, and the token endpoints (API §7.1).
 *
 * - Sign-in failures all return the same `401 invalid_credentials`, whatever the reason (unknown
 *   email, wrong password, account not active); a dummy hash keeps timing similar.
 * - Failures count against the account and the IP address (A7 values); while either limit is
 *   reached, sign-in returns `429 rate_limited`.
 * - Activation changes the account status only — never the volunteer's operational status [A].
 * - Tokens are single use; only their SHA-256 is stored.
 */
final readonly class AuthService
{
    public function __construct(
        private Database $db,
        private AccountRepository $accounts,
        private SessionService $sessions,
        private PasswordHasher $hasher,
        private PasswordPolicy $policy,
        private RateLimiter $limiter,
        private AuthFeature $feature,
        private AuditLogger $audit,
        private Clock $clock,
    ) {}

    /**
     * @return string the new session identifier (for the cookie)
     */
    public function login(string $email, string $password, string $ip, string $agent, AuditContext $context): string
    {
        $settings = $this->feature->settings();
        $accountKey = 'login:account:' . mb_strtolower(trim($email));
        $ipKey = 'login:ip:' . $ip;
        $this->limiter->assertBelow($accountKey, $settings->loginAccountMax, $settings->loginWindowMinutes);
        $this->limiter->assertBelow($ipKey, $settings->loginIpMax, $settings->loginWindowMinutes);

        $user = $this->accounts->findByEmail(trim($email));
        $userId = $user === null ? null : Row::int($user, 'id');
        $hash = $user === null ? null : Row::stringOrNull($user, 'password_hash');
        $active = $user !== null && Row::string($user, 'status') === 'active';
        if ($hash === null) {
            $this->hasher->verifyDummy($password);
        }

        if ($userId === null || $hash === null || !$this->hasher->verify($password, $hash) || !$active) {
            $this->limiter->hit($accountKey, $settings->loginWindowMinutes);
            $this->limiter->hit($ipKey, $settings->loginWindowMinutes);
            $this->audit->denied($context, 'auth.login.failed', 'user', $userId);

            throw new ApiException(ErrorCode::InvalidCredentials);
        }

        $now = Database::timestamp($this->clock->now());

        return $this->db->transaction(function () use ($userId, $password, $hash, $now, $agent, $context): string {
            if ($this->hasher->needsRehash($hash)) {
                $this->accounts->setPassword($userId, $this->hasher->hash($password), $now);
            }
            $this->accounts->recordLogin($userId, $now);
            $session = $this->sessions->start($userId, $context->ipPrefix, $agent);
            $this->audit->success($context->withActor($userId), 'auth.login.succeeded', 'user', $userId);

            return $session['id'];
        });
    }

    public function logout(Principal $principal, AuditContext $context): void
    {
        $this->db->transaction(function () use ($principal, $context): void {
            $this->sessions->revoke($principal->sessionIdHash);
            $this->audit->success($context, 'auth.logout', 'user', $principal->userId);
        });
    }

    public function changePassword(Principal $principal, string $current, string $new, AuditContext $context): void
    {
        $this->feature->settings();
        $user = $this->accounts->find($principal->userId);
        $hash = $user === null ? null : Row::stringOrNull($user, 'password_hash');
        if ($hash === null || !$this->hasher->verify($current, $hash)) {
            $this->audit->denied($context, 'auth.password.changed', 'user', $principal->userId);

            throw new ApiException(ErrorCode::InvalidCredentials, 'The current password is incorrect.');
        }
        $this->policy->check($new, 'new_password');

        $this->db->transaction(function () use ($principal, $new, $context): void {
            $this->accounts->setPassword($principal->userId, $this->hasher->hash($new), Database::timestamp($this->clock->now()));
            $this->sessions->revokeAllForUser($principal->userId, $principal->sessionIdHash);
            $this->audit->success($context, 'auth.password.changed', 'user', $principal->userId);
        });
    }

    /**
     * @return array{valid: bool, expired: bool, email_hint: ?string}
     */
    public function inspectInvitation(string $token): array
    {
        $this->feature->settings();
        $row = $this->accounts->findToken(self::hashToken($token), 'invitation');
        $user = $row === null ? null : $this->accounts->find(Row::int($row, 'user_id'));
        if ($row === null || $user === null || $row['used_at'] !== null || $row['revoked_at'] !== null || Row::string($user, 'status') !== 'invited') {
            return ['valid' => false, 'expired' => false, 'email_hint' => null];
        }

        $expired = $this->isExpired($row);

        return ['valid' => !$expired, 'expired' => $expired, 'email_hint' => $expired ? null : self::maskEmail(Row::string($user, 'email'))];
    }

    /**
     * @return array{valid: bool, expired: bool}
     */
    public function inspectReset(string $token): array
    {
        $this->feature->settings();
        $row = $this->accounts->findToken(self::hashToken($token), 'password_reset');
        $user = $row === null ? null : $this->accounts->find(Row::int($row, 'user_id'));
        if ($row === null || $user === null || $row['used_at'] !== null || $row['revoked_at'] !== null || Row::string($user, 'status') !== 'active') {
            return ['valid' => false, 'expired' => false];
        }

        $expired = $this->isExpired($row);

        return ['valid' => !$expired, 'expired' => $expired];
    }

    /**
     * Sets the first password and activates the account (account status only [A]).
     *
     * @return string the new session identifier
     */
    public function activate(string $token, string $password, string $confirmation, string $agent, AuditContext $context): string
    {
        $this->checkNewPassword($password, $confirmation);

        return $this->db->transaction(function () use ($token, $password, $agent, $context): string {
            $row = $this->redeemable($token, 'invitation', 'invited');
            $userId = Row::int($row, 'user_id');
            $now = Database::timestamp($this->clock->now());

            $this->accounts->activate($userId, $this->hasher->hash($password), $now);
            $this->accounts->useToken(Row::int($row, 'id'), $now);
            $this->accounts->revokeTokens($userId, 'invitation', $now);
            $session = $this->sessions->start($userId, $context->ipPrefix, $agent);
            $this->audit->success($context->withActor($userId), 'account.activated', 'user', $userId);

            return $session['id'];
        });
    }

    public function resetPassword(string $token, string $password, string $confirmation, AuditContext $context): void
    {
        $this->checkNewPassword($password, $confirmation);

        $this->db->transaction(function () use ($token, $password, $context): void {
            $row = $this->redeemable($token, 'password_reset', 'active');
            $userId = Row::int($row, 'user_id');
            $now = Database::timestamp($this->clock->now());

            $this->accounts->setPassword($userId, $this->hasher->hash($password), $now);
            $this->accounts->useToken(Row::int($row, 'id'), $now);
            $this->accounts->revokeTokens($userId, 'password_reset', $now);
            $this->sessions->revokeAllForUser($userId);
            $this->audit->success($context->withActor($userId), 'auth.reset.completed', 'user', $userId);
        });
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($local, 0, 1) . '***@' . $domain;
    }

    /**
     * @return array<string, scalar|null>
     */
    private function redeemable(string $token, string $type, string $requiredStatus): array
    {
        $row = $this->accounts->findToken(self::hashToken($token), $type, true);
        if ($row === null || $row['revoked_at'] !== null || $this->isExpired($row)) {
            throw new ApiException(ErrorCode::TokenExpired);
        }
        $user = $this->accounts->find(Row::int($row, 'user_id'), true);
        if ($row['used_at'] !== null || $user === null || Row::string($user, 'status') !== $requiredStatus) {
            throw new ApiException(ErrorCode::TokenUsed);
        }

        return $row;
    }

    /**
     * @param array<string, scalar|null> $token
     */
    private function isExpired(array $token): bool
    {
        return Row::time(Row::string($token, 'expires_at')) <= $this->clock->now();
    }

    private function checkNewPassword(string $password, string $confirmation): void
    {
        if (!hash_equals($password, $confirmation)) {
            throw new ApiException(ErrorCode::ValidationFailed, details: [new ErrorDetail('password_confirmation', 'mismatch', 'Must match the password.')]);
        }
        $this->policy->check($password);
    }
}
