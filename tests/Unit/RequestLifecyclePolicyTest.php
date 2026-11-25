<?php

namespace Tests\Unit;

use App\Services\RequestLifecyclePolicy;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * B2.5B step 3A — pure transition-policy verification.
 *
 * No database is touched: every assertion calls the real policy class only.
 */
class RequestLifecyclePolicyTest extends TestCase
{
    /**
     * @var list<string>
     */
    private const APPROVED_STATUSES = [
        'contact_initiated',
        'pending',
        'accepted',
        'completed',
        'not_completed',
        'cancelled',
    ];

    /**
     * @var list<array{0: string, 1: string}>
     */
    private const APPROVED_TRANSITIONS = [
        ['pending', 'accepted'],
        ['pending', 'cancelled'],
        ['contact_initiated', 'accepted'],
        ['contact_initiated', 'cancelled'],
        ['accepted', 'completed'],
        ['accepted', 'not_completed'],
        ['accepted', 'cancelled'],
    ];

    private function assertTransitionRejected(?string $fromStatus, ?string $toStatus): void
    {
        $label = sprintf('[%s] -> [%s]', $fromStatus ?? 'NULL', $toStatus ?? 'NULL');

        $this->assertFalse(
            RequestLifecyclePolicy::isAllowed($fromStatus, $toStatus),
            "{$label} must be rejected.",
        );

        $thrown = null;

        try {
            RequestLifecyclePolicy::assertAllowed($fromStatus, $toStatus);
        } catch (InvalidArgumentException $exception) {
            $thrown = $exception;
        }

        $this->assertInstanceOf(
            InvalidArgumentException::class,
            $thrown,
            "{$label} must throw InvalidArgumentException.",
        );
        $this->assertStringContainsString(
            'not allowed',
            $thrown->getMessage(),
            "{$label} must use the consistent rejection message.",
        );
    }

    public function test_all_seven_approved_transitions_are_allowed(): void
    {
        foreach (self::APPROVED_TRANSITIONS as [$fromStatus, $toStatus]) {
            $label = "[{$fromStatus}] -> [{$toStatus}]";

            $this->assertTrue(
                RequestLifecyclePolicy::isAllowed($fromStatus, $toStatus),
                "{$label} must be allowed.",
            );

            RequestLifecyclePolicy::assertAllowed($fromStatus, $toStatus);
        }
    }

    public function test_every_other_transition_between_approved_statuses_is_rejected(): void
    {
        $approved = [];

        foreach (self::APPROVED_TRANSITIONS as [$fromStatus, $toStatus]) {
            $approved["{$fromStatus}>{$toStatus}"] = true;
        }

        $rejectedCount = 0;

        foreach (self::APPROVED_STATUSES as $fromStatus) {
            foreach (self::APPROVED_STATUSES as $toStatus) {
                if (isset($approved["{$fromStatus}>{$toStatus}"])) {
                    continue;
                }

                $this->assertTransitionRejected($fromStatus, $toStatus);
                $rejectedCount++;
            }
        }

        $this->assertSame(29, $rejectedCount);
    }

    public function test_terminal_statuses_reject_all_outgoing_transitions(): void
    {
        foreach (['completed', 'not_completed', 'cancelled'] as $terminalStatus) {
            foreach (self::APPROVED_STATUSES as $toStatus) {
                $this->assertTransitionRejected($terminalStatus, $toStatus);
            }
        }
    }

    public function test_same_state_transitions_are_rejected(): void
    {
        foreach (self::APPROVED_STATUSES as $status) {
            $this->assertTransitionRejected($status, $status);
        }
    }

    public function test_contact_initiated_to_pending_is_rejected(): void
    {
        $this->assertTransitionRejected('contact_initiated', 'pending');
    }

    public function test_unknown_statuses_are_rejected(): void
    {
        foreach (['unknown', 'processing', 'ACCEPTED', ' pending'] as $unknownStatus) {
            foreach (self::APPROVED_STATUSES as $approvedStatus) {
                $this->assertTransitionRejected($unknownStatus, $approvedStatus);
                $this->assertTransitionRejected($approvedStatus, $unknownStatus);
            }
        }
    }

    public function test_legacy_active_is_rejected_as_source_and_target(): void
    {
        foreach (self::APPROVED_STATUSES as $approvedStatus) {
            $this->assertTransitionRejected('active', $approvedStatus);
            $this->assertTransitionRejected($approvedStatus, 'active');
        }

        $this->assertTransitionRejected('active', 'active');
    }

    public function test_empty_status_strings_are_rejected(): void
    {
        $this->assertTransitionRejected('', 'accepted');
        $this->assertTransitionRejected('pending', '');
        $this->assertTransitionRejected('', '');
    }

    public function test_initial_null_from_status_is_not_a_normal_transition(): void
    {
        foreach (self::APPROVED_STATUSES as $toStatus) {
            $this->assertTransitionRejected(null, $toStatus);
        }

        $this->assertTransitionRejected('pending', null);
        $this->assertTransitionRejected(null, null);
    }
}
