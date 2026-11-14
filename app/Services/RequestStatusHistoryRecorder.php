<?php

namespace App\Services;

use App\Models\ServiceRequest;
use App\Models\ServiceRequestStatusHistory;
use InvalidArgumentException;

/**
 * Records the single initial status-history entry for an existing request.
 *
 * B2.5B step 4B-1. This collaborator is HTTP-independent and knows nothing
 * about channels, coordinates, idempotency keys or API payloads. It records
 * history only: it never creates a request, changes request attributes,
 * performs a normal transition, attaches reasons, opens a transaction,
 * triggers notifications or calls an external service.
 *
 * Transaction ownership: the recorder deliberately does NOT call
 * DB::transaction(). The future B2.5C creation service must call
 * recordInitial() inside its own transaction so the request row, services
 * pivot, idempotency claim and this initial history commit or roll back
 * together. This class alone does not guarantee request + history atomicity.
 *
 * Concurrency limitation: the "no history yet" check is an application-level
 * guard, not a database constraint. Two simultaneous callers could both pass
 * the check and insert two initial rows. Preventing that would require a
 * database-level uniqueness guarantee, which is outside this slice.
 */
class RequestStatusHistoryRecorder
{
    /**
     * The only statuses a request may have when its initial history is written.
     *
     * @var list<string>
     */
    private const INITIAL_STATUSES = [
        'pending',
        'contact_initiated',
    ];

    /**
     * The approved actor vocabulary from the B2.5A decision closure.
     *
     * @var list<string>
     */
    private const ACTOR_TYPES = [
        'system',
        'app_user',
        'admin',
        'provider',
    ];

    /**
     * Record the initial NULL -> status history entry for a persisted request.
     *
     * The caller owns the surrounding transaction and any later integration.
     * Initial creation is not a normal lifecycle transition, so
     * RequestLifecyclePolicy is intentionally not used. Reasons belong to
     * negative transitions, so RequestReasonPolicy is intentionally not used
     * and no reason link is ever created here.
     *
     * @throws InvalidArgumentException
     */
    public function recordInitial(
        ServiceRequest $request,
        string $actorType,
        ?int $actorId = null,
    ): ServiceRequestStatusHistory {
        // R1: the supplied model must represent an existing database row.
        if (! $request->exists || $request->getKey() === null) {
            throw new InvalidArgumentException(
                'The service request must be persisted before its initial history can be recorded.',
            );
        }

        // R2: read the current row through the model's own connection. The
        // supplied model is never refreshed, repaired or written to. Missing
        // and soft-deleted rows are detected here as well.
        $persisted = $request->newQuery()
            ->withTrashed()
            ->find($request->getKey());

        if (! $persisted instanceof ServiceRequest) {
            throw new InvalidArgumentException(
                'The service request no longer exists in the database.',
            );
        }

        if ($persisted->trashed()) {
            throw new InvalidArgumentException(
                'The service request is deleted; its initial history cannot be recorded.',
            );
        }

        if ($request->status !== $persisted->status) {
            throw new InvalidArgumentException(
                'The service request model status does not match the persisted status.',
            );
        }

        // R3: only the two approved initial target statuses are accepted.
        if (! in_array($persisted->status, self::INITIAL_STATUSES, true)) {
            throw new InvalidArgumentException(sprintf(
                'The status [%s] is not a valid initial request status.',
                $persisted->status ?? 'NULL',
            ));
        }

        // R5: the actor type must be an approved, non-blank vocabulary value.
        if (trim($actorType) === '' || ! in_array($actorType, self::ACTOR_TYPES, true)) {
            throw new InvalidArgumentException(sprintf(
                'The actor type [%s] is not approved.',
                $actorType,
            ));
        }

        // R6: application-level guard only. Existing history is never modified.
        if ($persisted->statusHistories()->exists()) {
            throw new InvalidArgumentException(
                'The service request already has status history; the initial entry cannot be recorded.',
            );
        }

        // R4/R7: the relationship supplies service_request_id and uses the
        // same connection. from_status and reason_note are NULL and no reasons
        // are attached. R8/R9: no lifecycle or reason policy is invoked.
        return $persisted->statusHistories()->create([
            'from_status' => null,
            'to_status' => $persisted->status,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'reason_note' => null,
        ]);
    }
}
