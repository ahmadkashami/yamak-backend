<?php

namespace App\Services;

use App\Mail\UserRequestOrderMail;
use App\Models\Driver;
use App\Models\ServiceRequest;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Best-effort driver notification for the secured mobile v2 request contract
 * (B2.2B.3B.3).
 *
 * DORMANT: this class is intentionally NOT referenced by any route or
 * controller yet. `POST /api/mobile/v2/requests` still returns 503 NOT_ENABLED.
 *
 * Calling contract (documented, not wired here):
 *   - Call ONLY AFTER RequestCreationService::create() has returned a
 *     COMMITTED, newly created request (wasCreated === true) - strictly after
 *     the creation transaction has committed.
 *   - If the creation result was a replay (wasReplayed === true), DO NOT call
 *     this: a replay must never send another email.
 *   - Never invoke it while a creation transaction is open. This class opens no
 *     transaction and calls neither commit() nor afterCommit().
 *
 * Guarantees are best-effort AT-MOST-ONCE: exactly one synchronous attempt per
 * call. A transport failure is logged and swallowed so a saved request can
 * never be reported back to the caller as a failed creation. There is no retry,
 * no queue job and no outbox - those require separate approval.
 */
class RequestNotificationService
{
    /** The mail transport accepted the message without throwing. */
    public const OUTCOME_SENT = 'sent';

    /** No resolvable (non-trashed) driver for the request. */
    public const OUTCOME_SKIPPED_NO_DRIVER = 'skipped_no_driver';

    /** Driver has no usable email address. */
    public const OUTCOME_SKIPPED_INVALID_RECIPIENT = 'skipped_invalid_recipient';

    /** The synchronous send threw; the failure was logged and swallowed. */
    public const OUTCOME_FAILED = 'failed';

    /**
     * Send the existing {@see UserRequestOrderMail} to the request's current
     * driver.
     *
     * Invoke only for a newly created, committed ServiceRequest (see the class
     * contract). Never throws a mail/transport failure to the caller.
     *
     * @return string one of the OUTCOME_* constants
     */
    public function notifyDriverOfNewRequest(ServiceRequest $serviceRequest): string
    {
        $driver = $serviceRequest->driver()->first();

        if (! $driver instanceof Driver) {
            Log::warning('Request notification skipped: no driver resolved for request.', [
                'service_request_id' => $serviceRequest->getKey(),
            ]);

            return self::OUTCOME_SKIPPED_NO_DRIVER;
        }

        $email = is_string($driver->email) ? trim($driver->email) : '';

        if ($email === '') {
            Log::warning('Request notification skipped: driver has no email address.', [
                'service_request_id' => $serviceRequest->getKey(),
                'driver_id' => $driver->getKey(),
            ]);

            return self::OUTCOME_SKIPPED_INVALID_RECIPIENT;
        }

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            Log::warning('Request notification skipped: driver email is malformed.', [
                'service_request_id' => $serviceRequest->getKey(),
                'driver_id' => $driver->getKey(),
            ]);

            return self::OUTCOME_SKIPPED_INVALID_RECIPIENT;
        }

        try {
            Mail::to($email)->send(new UserRequestOrderMail(
                $serviceRequest->created_at ?? now(),
            ));

            return self::OUTCOME_SENT;
        } catch (Throwable $exception) {
            // Deliberately swallowed: the request is already committed and must
            // not be reported as a failed creation because mail failed. Only
            // non-sensitive identifiers and the transport message are logged.
            Log::error('Request notification delivery failed.', [
                'service_request_id' => $serviceRequest->getKey(),
                'driver_id' => $driver->getKey(),
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return self::OUTCOME_FAILED;
        }
    }
}
