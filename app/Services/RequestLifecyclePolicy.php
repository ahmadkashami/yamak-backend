<?php

namespace App\Services;

use InvalidArgumentException;

/**
 * Approved request lifecycle transition policy (B2.5B step 3A).
 *
 * Pure, side-effect-free business logic: no HTTP, Eloquent, database, events,
 * observers or container registration. Only the approved status vocabulary and
 * the seven transitions below are legal.
 *
 * The initial history event (`from_status = null`) is not a normal state
 * transition and is rejected here. Creating that event belongs to a later
 * B2.5B slice.
 */
final class RequestLifecyclePolicy
{
    /**
     * The approved lifecycle statuses. Terminal statuses are included because
     * they are valid states, but they have no outgoing transitions.
     *
     * @var list<string>
     */
    private const STATUSES = [
        'contact_initiated',
        'pending',
        'accepted',
        'completed',
        'not_completed',
        'cancelled',
    ];

    /**
     * Destination statuses allowed for each non-terminal source status.
     *
     * `contact_initiated` may NOT become `pending`; it may only be accepted or
     * cancelled. Terminal statuses are intentionally absent.
     *
     * @var array<string, list<string>>
     */
    private const TRANSITIONS = [
        'contact_initiated' => ['accepted', 'cancelled'],
        'pending' => ['accepted', 'cancelled'],
        'accepted' => ['completed', 'not_completed', 'cancelled'],
    ];

    /**
     * Whether the given source may transition to the given destination.
     *
     * Returns false for null sources (the initial-history event is not a normal
     * transition), null targets, unknown statuses, same-state transitions and
     * every transition outside the approved matrix.
     */
    public static function isAllowed(?string $fromStatus, ?string $toStatus): bool
    {
        if ($fromStatus === null || $toStatus === null) {
            return false;
        }

        if (
            ! in_array($fromStatus, self::STATUSES, true)
            || ! in_array($toStatus, self::STATUSES, true)
        ) {
            return false;
        }

        return in_array($toStatus, self::TRANSITIONS[$fromStatus] ?? [], true);
    }

    /**
     * Reject an invalid transition with a consistent exception.
     *
     * @throws InvalidArgumentException
     */
    public static function assertAllowed(?string $fromStatus, ?string $toStatus): void
    {
        if (self::isAllowed($fromStatus, $toStatus)) {
            return;
        }

        throw new InvalidArgumentException(sprintf(
            'Request status transition from [%s] to [%s] is not allowed.',
            $fromStatus ?? 'NULL',
            $toStatus ?? 'NULL',
        ));
    }
}
