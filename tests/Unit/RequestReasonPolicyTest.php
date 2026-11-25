<?php

namespace Tests\Unit;

use App\Services\RequestReasonPolicy;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * B2.5B step 3B — pure reason-policy verification.
 *
 * No database is touched: every assertion calls the real policy class only. The
 * catalog constant below is test input representing the trusted server-side
 * catalog; the policy itself contains no second catalog.
 */
class RequestReasonPolicyTest extends TestCase
{
    /**
     * @var list<string>
     */
    private const APPROVED_CATALOG = [
        'too_far',
        'service_cannot_be_provided',
        'materials_unavailable',
        'customer_unreachable',
        'customer_cancelled',
        'provider_unavailable',
        'service_unavailable',
        'location_inaccessible',
        'price_not_agreed',
        'other',
    ];

    /**
     * @param  array<int|string, mixed>  $reasonCodes
     * @param  list<string>|null  $approvedCatalogCodes
     */
    private function assertSelectionValid(
        string $targetStatus,
        array $reasonCodes,
        ?string $reasonNote = null,
        ?array $approvedCatalogCodes = null,
    ): void {
        $approvedCatalogCodes ??= self::APPROVED_CATALOG;

        $this->assertTrue(
            RequestReasonPolicy::isValid(
                $targetStatus,
                $reasonCodes,
                $reasonNote,
                $approvedCatalogCodes,
            ),
            "Reason selection for [{$targetStatus}] must be valid.",
        );

        RequestReasonPolicy::assertValid(
            $targetStatus,
            $reasonCodes,
            $reasonNote,
            $approvedCatalogCodes,
        );
    }

    /**
     * @param  array<int|string, mixed>  $reasonCodes
     * @param  list<string>|null  $approvedCatalogCodes
     */
    private function assertSelectionRejected(
        string $targetStatus,
        array $reasonCodes,
        ?string $reasonNote = null,
        ?array $approvedCatalogCodes = null,
    ): void {
        $approvedCatalogCodes ??= self::APPROVED_CATALOG;

        $this->assertFalse(
            RequestReasonPolicy::isValid(
                $targetStatus,
                $reasonCodes,
                $reasonNote,
                $approvedCatalogCodes,
            ),
            "Reason selection for [{$targetStatus}] must be rejected.",
        );

        $thrown = null;

        try {
            RequestReasonPolicy::assertValid(
                $targetStatus,
                $reasonCodes,
                $reasonNote,
                $approvedCatalogCodes,
            );
        } catch (InvalidArgumentException $exception) {
            $thrown = $exception;
        }

        $this->assertInstanceOf(
            InvalidArgumentException::class,
            $thrown,
            "Reason selection for [{$targetStatus}] must throw InvalidArgumentException.",
        );
        $this->assertNotSame('', trim($thrown->getMessage()));
    }

    public function test_not_completed_with_one_approved_reason_is_valid(): void
    {
        $this->assertSelectionValid('not_completed', ['too_far']);
    }

    public function test_cancelled_with_one_approved_reason_is_valid(): void
    {
        $this->assertSelectionValid('cancelled', ['customer_cancelled']);
    }

    public function test_not_completed_without_reasons_is_rejected(): void
    {
        $this->assertSelectionRejected('not_completed', []);
    }

    public function test_cancelled_without_reasons_is_rejected(): void
    {
        $this->assertSelectionRejected('cancelled', []);
    }

    public function test_multiple_distinct_approved_reasons_are_valid(): void
    {
        $this->assertSelectionValid('not_completed', [
            'too_far',
            'materials_unavailable',
        ]);

        $this->assertSelectionValid('cancelled', [
            'customer_cancelled',
            'price_not_agreed',
        ]);
    }

    public function test_duplicate_reasons_are_rejected(): void
    {
        $this->assertSelectionRejected('not_completed', [
            'too_far',
            'too_far',
        ]);

        $this->assertSelectionRejected('cancelled', [
            'materials_unavailable',
            'materials_unavailable',
        ]);
    }

    public function test_unknown_reason_code_is_rejected(): void
    {
        foreach (['unknown_reason', 'TOO_FAR', ' too_far', 'too_far '] as $unknownCode) {
            $this->assertSelectionRejected('cancelled', [$unknownCode]);
        }
    }

    public function test_blank_and_non_string_reason_codes_are_rejected(): void
    {
        foreach ([
            [''],
            ['   '],
            [null],
            [1],
            [true],
            [['too_far']],
            ['too_far', 123],
        ] as $invalidSelection) {
            $this->assertSelectionRejected('cancelled', $invalidSelection);
        }
    }

    public function test_other_without_reason_note_is_rejected(): void
    {
        $this->assertSelectionRejected('not_completed', ['other']);
        $this->assertSelectionRejected('not_completed', ['other'], '');
    }

    public function test_other_with_whitespace_only_reason_note_is_rejected(): void
    {
        $this->assertSelectionRejected('cancelled', ['other'], '   ');
        $this->assertSelectionRejected('cancelled', ['other'], "\t\n ");
    }

    public function test_other_with_meaningful_reason_note_is_valid(): void
    {
        $this->assertSelectionValid(
            'not_completed',
            ['other'],
            'Customer requested a different scope.',
        );

        $this->assertSelectionValid('cancelled', ['other'], '  Customer moved away.  ');
    }

    public function test_normal_approved_reason_does_not_require_reason_note(): void
    {
        $this->assertSelectionValid('not_completed', ['too_far'], null);
        $this->assertSelectionValid('cancelled', ['customer_cancelled'], '');
    }

    public function test_no_mandatory_reason_is_imposed_on_other_target_statuses(): void
    {
        foreach (['pending', 'contact_initiated', 'accepted', 'completed'] as $targetStatus) {
            $this->assertSelectionValid($targetStatus, []);
            $this->assertSelectionValid($targetStatus, [], 'Optional note without other.');
        }
    }

    public function test_reasons_are_not_forbidden_on_statuses_that_do_not_require_them(): void
    {
        foreach (['pending', 'contact_initiated', 'accepted', 'completed'] as $targetStatus) {
            $this->assertSelectionValid($targetStatus, ['too_far']);
        }
    }

    public function test_catalog_membership_uses_only_the_trusted_catalog_argument(): void
    {
        $narrowCatalog = ['too_far'];

        $this->assertSelectionValid('cancelled', ['too_far'], null, $narrowCatalog);
        $this->assertSelectionRejected('cancelled', ['materials_unavailable'], null, $narrowCatalog);

        $syntheticTrustedCatalog = ['server_loaded_code'];

        $this->assertSelectionValid('cancelled', ['server_loaded_code'], null, $syntheticTrustedCatalog);
        $this->assertSelectionRejected('cancelled', ['too_far'], null, $syntheticTrustedCatalog);
    }
}
