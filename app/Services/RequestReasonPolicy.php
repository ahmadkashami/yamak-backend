<?php

namespace App\Services;

use InvalidArgumentException;

/**
 * Pure validation of request status-history reasons (B2.5B step 3B).
 *
 * This class holds only reason-selection rules, never status-transition rules.
 * `RequestLifecyclePolicy` remains responsible for allowed transitions.
 *
 * The persisted `request_reasons` catalog is authoritative. This policy does
 * NOT maintain a second catalog: the caller must pass the trusted catalog code
 * set explicitly. A future integration slice must load those codes from
 * server-side data, never from an API caller.
 *
 * No Eloquent, database, HTTP, authentication, authorization, notification or
 * event behaviour is present.
 */
final class RequestReasonPolicy
{
    /**
     * Target statuses that require at least one selected reason.
     *
     * @var list<string>
     */
    private const STATUSES_REQUIRING_REASON = [
        'not_completed',
        'cancelled',
    ];

    /**
     * The only reason code that additionally requires a non-empty note.
     */
    private const OTHER_REASON_CODE = 'other';

    /**
     * Validate one reason selection against the trusted catalog code set.
     *
     * For `not_completed` and `cancelled`, at least one reason is required.
     * Other target statuses impose no mandatory selection. Selected codes are
     * always validated when present: strings only, non-blank, no duplicates and
     * exact membership in the supplied trusted catalog. Selecting `other`
     * always requires a non-empty note after trimming whitespace.
     *
     * @param  array<int|string, mixed>  $reasonCodes
     * @param  list<string>  $approvedCatalogCodes
     *
     * @throws InvalidArgumentException
     */
    public static function assertValid(
        string $targetStatus,
        array $reasonCodes,
        ?string $reasonNote,
        array $approvedCatalogCodes,
    ): void {
        foreach ($reasonCodes as $reasonCode) {
            if (! is_string($reasonCode)) {
                throw new InvalidArgumentException('Reason codes must be strings.');
            }

            if (trim($reasonCode) === '') {
                throw new InvalidArgumentException('Blank reason codes are not allowed.');
            }
        }

        if (count($reasonCodes) !== count(array_unique($reasonCodes, SORT_STRING))) {
            throw new InvalidArgumentException('Duplicate reason codes are not allowed.');
        }

        foreach ($reasonCodes as $reasonCode) {
            if (! in_array($reasonCode, $approvedCatalogCodes, true)) {
                throw new InvalidArgumentException(sprintf('Unknown reason code [%s].', $reasonCode));
            }
        }

        if (
            in_array($targetStatus, self::STATUSES_REQUIRING_REASON, true)
            && $reasonCodes === []
        ) {
            throw new InvalidArgumentException(sprintf(
                'At least one reason is required when the target status is [%s].',
                $targetStatus,
            ));
        }

        if (
            in_array(self::OTHER_REASON_CODE, $reasonCodes, true)
            && ($reasonNote === null || trim($reasonNote) === '')
        ) {
            throw new InvalidArgumentException('The [other] reason requires a non-empty reasonNote.');
        }
    }

    /**
     * Non-throwing form of {@see self::assertValid()}.
     *
     * @param  array<int|string, mixed>  $reasonCodes
     * @param  list<string>  $approvedCatalogCodes
     */
    public static function isValid(
        string $targetStatus,
        array $reasonCodes,
        ?string $reasonNote,
        array $approvedCatalogCodes,
    ): bool {
        try {
            self::assertValid($targetStatus, $reasonCodes, $reasonNote, $approvedCatalogCodes);

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }
}
