<?php

namespace Tests\Feature;

use App\Mail\UserRequestOrderMail;
use App\Models\ServiceRequest;
use App\Services\RequestCreationService;
use App\Services\RequestNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\Concerns\CreatesRequestFixtures;
use Tests\TestCase;

/**
 * B2.2C.1 — isolated verification of RequestNotificationService.
 *
 * The mail transport is always faked or mocked: no SMTP server is contacted and
 * no real email is sent. Because the controller integration is not active yet,
 * the future orchestration (notify only when `wasCreated`) is simulated
 * explicitly and labelled as such — these are NOT HTTP controller tests.
 */
class RequestNotificationServiceTest extends TestCase
{
    use CreatesRequestFixtures;
    use RefreshDatabase;

    private function notification(): RequestNotificationService
    {
        return app(RequestNotificationService::class);
    }

    private function persistedRequestForDriver(int $driverId): ServiceRequest
    {
        return ServiceRequest::query()->create([
            'platform' => 'mobile',
            'app_user_id' => $this->makeAppUser()->id,
            'driver_id' => $driverId,
            'status' => 'active',
        ]);
    }

    public function test_confirmed_new_request_is_notified_exactly_once(): void
    {
        Mail::fake();

        $fixture = $this->makeRequestFixture(1);
        $request = $this->persistedRequestForDriver($fixture['driver']->id);

        $outcome = $this->notification()->notifyDriverOfNewRequest($request);

        $this->assertSame(RequestNotificationService::OUTCOME_SENT, $outcome);
        Mail::assertSent(UserRequestOrderMail::class, 1);
        Mail::assertSent(
            UserRequestOrderMail::class,
            fn (UserRequestOrderMail $mail): bool => $mail->hasTo($fixture['driver']->email),
        );
    }

    public function test_missing_driver_is_skipped_safely(): void
    {
        Mail::fake();

        $fixture = $this->makeRequestFixture(1);
        $request = $this->persistedRequestForDriver($fixture['driver']->id);

        $fixture['driver']->delete();

        $outcome = $this->notification()->notifyDriverOfNewRequest($request);

        $this->assertSame(RequestNotificationService::OUTCOME_SKIPPED_NO_DRIVER, $outcome);
        Mail::assertNothingSent();
    }

    public function test_driver_without_email_is_skipped_safely(): void
    {
        Mail::fake();

        $fixture = $this->makeRequestFixture(1, driverOverrides: ['email' => null]);
        $request = $this->persistedRequestForDriver($fixture['driver']->id);

        $outcome = $this->notification()->notifyDriverOfNewRequest($request);

        $this->assertSame(RequestNotificationService::OUTCOME_SKIPPED_INVALID_RECIPIENT, $outcome);
        Mail::assertNothingSent();
    }

    public function test_driver_with_malformed_email_is_skipped_safely(): void
    {
        Mail::fake();

        $fixture = $this->makeRequestFixture(1, driverOverrides: ['email' => 'not-an-email']);
        $request = $this->persistedRequestForDriver($fixture['driver']->id);

        $outcome = $this->notification()->notifyDriverOfNewRequest($request);

        $this->assertSame(RequestNotificationService::OUTCOME_SKIPPED_INVALID_RECIPIENT, $outcome);
        Mail::assertNothingSent();
    }

    public function test_transport_exception_is_swallowed_and_reported_as_failed(): void
    {
        $fixture = $this->makeRequestFixture(1);
        $request = $this->persistedRequestForDriver($fixture['driver']->id);

        $pending = Mockery::mock();
        $pending->shouldReceive('send')->once()->andThrow(new RuntimeException('smtp timeout'));
        Mail::shouldReceive('to')->once()->andReturn($pending);

        $outcome = $this->notification()->notifyDriverOfNewRequest($request);

        $this->assertSame(RequestNotificationService::OUTCOME_FAILED, $outcome);
    }

    public function test_request_remains_persisted_after_notification_failure(): void
    {
        $fixture = $this->makeRequestFixture(1);
        $request = $this->persistedRequestForDriver($fixture['driver']->id);

        $pending = Mockery::mock();
        $pending->shouldReceive('send')->once()->andThrow(new RuntimeException('smtp timeout'));
        Mail::shouldReceive('to')->once()->andReturn($pending);

        $this->notification()->notifyDriverOfNewRequest($request);

        $this->assertDatabaseCount('service_requests', 1);
        $this->assertNotNull(ServiceRequest::query()->find($request->id));
    }

    public function test_creation_alone_sends_no_notification_before_the_notification_step(): void
    {
        Mail::fake();

        $fixture = $this->makeRequestFixture(1);
        $user = $this->makeAppUser();

        $result = app(RequestCreationService::class)->create(
            $user,
            $fixture['vehicle']->id,
            $fixture['driver']->id,
            [$fixture['services'][0]->id],
            (string) Str::uuid(),
        );

        $this->assertTrue($result['wasCreated']);
        Mail::assertNothingSent();
    }

    public function test_simulated_orchestration_notifies_once_for_created_and_never_for_replay(): void
    {
        Mail::fake();

        $fixture = $this->makeRequestFixture(1);
        $user = $this->makeAppUser();
        $ids = [$fixture['services'][0]->id];
        $key = (string) Str::uuid();

        $creation = app(RequestCreationService::class);
        $notification = $this->notification();

        // Simulated future controller orchestration (not an HTTP test).
        foreach ([$creation->create($user, $fixture['vehicle']->id, $fixture['driver']->id, $ids, $key),
            $creation->create($user, $fixture['vehicle']->id, $fixture['driver']->id, $ids, $key)] as $result) {
            if ($result['wasCreated']) {
                $notification->notifyDriverOfNewRequest($result['request']);
            }
        }

        Mail::assertSent(UserRequestOrderMail::class, 1);
        $this->assertDatabaseCount('service_requests', 1);
    }
}
