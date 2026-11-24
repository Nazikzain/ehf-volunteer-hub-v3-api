<?php

declare(strict_types=1);

namespace Ehf\Api\Services;

use Ehf\Api\Http\Error\ApiException;
use Ehf\Api\Http\Error\ErrorCode;
use Ehf\Api\Infrastructure\Database\Row;
use Ehf\Api\Repositories\GovernanceRepository;

/**
 * G7 final-owner protection [A] (API §7.17.4). Call inside the changing transaction: the active
 * memberships are locked, so concurrent requests cannot both remove "the other" owner.
 *
 * The access an owner must keep is pending (R7). Until it is decided, the protected access is the
 * one recorded today: an active membership on an active account.
 */
final readonly class FinalOwnerGuard
{
    public function __construct(private GovernanceRepository $governance) {}

    /**
     * Refuses a change that would leave no effective owner once $userId loses owner access.
     */
    public function assertSurvives(int $userId): void
    {
        $effective = array_filter(
            $this->governance->lockActiveOwners(),
            static fn(array $owner): bool => Row::string($owner, 'status') === 'active',
        );
        $owners = array_map(static fn(array $owner): int => Row::int($owner, 'user_id'), $effective);

        if (\in_array($userId, $owners, true) && \count($owners) <= 1) {
            throw new ApiException(ErrorCode::FinalOwnerProtected);
        }
    }
}
