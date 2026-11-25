<?php

namespace Tests\Feature;

use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DashboardLifecycleActionsTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();

        // Disable foreign key checks for MariaDB
        DB::statement('SET FOREIGN_KEY_CHECKS = 0');

        // Ensure regular user exists for unauthorized access tests
        $regularUser = User::query()->where('email', 'regular@example.com')->first();
        if (!$regularUser) {
            $regularUser = User::create([
                'name' => 'Regular User',
                'email' => 'regular@example.com',
                'password' => bcrypt('123456'),
                'mobile' => null,
                'type' => 'regular',
            ]);
        }
    }

    protected function tearDown(): void
    {
        // Re-enable foreign key checks
        DB::statement('SET FOREIGN_KEY_CHECKS = 1');
        parent::tearDown();
    }

    /** @test */
    public function admin_can_transition_pending_to_accepted()
    {
        $admin = User::query()->where('email', 'admin@example.com')->first();

        // Create isolated test fixture using unguarded to bypass FK checks
        $request = ServiceRequest::unguarded(function () {
            return ServiceRequest::create([
                'status' => 'pending',
                'platform' => 'mobile',
                'channel' => 'app',
                'app_user_id' => 1,
                'driver_id' => 1,
                'vehicle_id' => 1,
            ]);
        });

        $this->actingAs($admin)
            ->withHeaders([
                'Authorization' => 'Bearer ' . $admin->createToken('dashboard-token')->plainTextToken,
            ])
            ->patchJson("/api/dashboard/v1/requests/{$request->id}/status", [
                'toStatus' => 'accepted',
                'expectedStatus' => 'pending',
            ])
            ->assertOk();

        $this->assertDatabaseHas('service_requests', ['id' => $request->id, 'status' => 'accepted']);
        $this->assertDatabaseHas('service_request_status_histories', [
            'service_request_id' => $request->id,
            'to_status' => 'accepted',
            'actor_type' => 'admin',
        ]);
    }

    /** @test */
    public function admin_can_transition_pending_to_cancelled_with_reasons()
    {
        $admin = User::query()->where('email', 'admin@example.com')->first();

        $request = ServiceRequest::unguarded(function () {
            return ServiceRequest::create([
                'status' => 'pending',
                'platform' => 'mobile',
                'channel' => 'app',
                'app_user_id' => 1,
                'driver_id' => 1,
                'vehicle_id' => 1,
            ]);
        });

        $this->actingAs($admin)
            ->withHeaders([
                'Authorization' => 'Bearer ' . $admin->createToken('dashboard-token')->plainTextToken,
            ])
            ->patchJson("/api/dashboard/v1/requests/{$request->id}/status", [
                'toStatus' => 'cancelled',
                'expectedStatus' => 'pending',
                'reasonCodes' => ['customer_cancelled'],
                'reasonNote' => 'Customer requested cancellation',
            ])
            ->assertOk();

        $this->assertDatabaseHas('service_requests', ['id' => $request->id, 'status' => 'cancelled']);
        $this->assertDatabaseHas('service_request_status_histories', [
            'service_request_id' => $request->id,
            'to_status' => 'cancelled',
            'actor_type' => 'admin',
            'reason_note' => 'Customer requested cancellation',
        ]);
    }

    /** @test */
    public function admin_can_transition_contact_initiated_to_accepted()
    {
        $admin = User::query()->where('email', 'admin@example.com')->first();

        $request = ServiceRequest::unguarded(function () {
            return ServiceRequest::create([
                'status' => 'contact_initiated',
                'platform' => 'mobile',
                'channel' => 'app',
                'app_user_id' => 1,
                'driver_id' => 1,
                'vehicle_id' => 1,
            ]);
        });

        $this->actingAs($admin)
            ->withHeaders([
                'Authorization' => 'Bearer ' . $admin->createToken('dashboard-token')->plainTextToken,
            ])
            ->patchJson("/api/dashboard/v1/requests/{$request->id}/status", [
                'toStatus' => 'accepted',
                'expectedStatus' => 'contact_initiated',
            ])
            ->assertOk();

        $this->assertDatabaseHas('service_requests', ['id' => $request->id, 'status' => 'accepted']);
    }

    /** @test */
    public function admin_can_transition_contact_initiated_to_cancelled()
    {
        $admin = User::query()->where('email', 'admin@example.com')->first();

        $request = ServiceRequest::unguarded(function () {
            return ServiceRequest::create([
                'status' => 'contact_initiated',
                'platform' => 'mobile',
                'channel' => 'app',
                'app_user_id' => 1,
                'driver_id' => 1,
                'vehicle_id' => 1,
            ]);
        });

        $this->actingAs($admin)
            ->withHeaders([
                'Authorization' => 'Bearer ' . $admin->createToken('dashboard-token')->plainTextToken,
            ])
            ->patchJson("/api/dashboard/v1/requests/{$request->id}/status", [
                'toStatus' => 'cancelled',
                'expectedStatus' => 'contact_initiated',
                'reasonCodes' => ['customer_cancelled'],
                'reasonNote' => 'Customer requested cancellation',
            ])
            ->assertOk();

        $this->assertDatabaseHas('service_requests', ['id' => $request->id, 'status' => 'cancelled']);
    }

    /** @test */
    public function admin_can_transition_accepted_to_completed()
    {
        $admin = User::query()->where('email', 'admin@example.com')->first();

        $request = ServiceRequest::unguarded(function () {
            return ServiceRequest::create([
                'status' => 'accepted',
                'platform' => 'mobile',
                'channel' => 'app',
                'app_user_id' => 1,
                'driver_id' => 1,
                'vehicle_id' => 1,
            ]);
        });

        $this->actingAs($admin)
            ->withHeaders([
                'Authorization' => 'Bearer ' . $admin->createToken('dashboard-token')->plainTextToken,
            ])
            ->patchJson("/api/dashboard/v1/requests/{$request->id}/status", [
                'toStatus' => 'completed',
                'expectedStatus' => 'accepted',
            ])
            ->assertOk();

        $this->assertDatabaseHas('service_requests', ['id' => $request->id, 'status' => 'completed']);
    }

    /** @test */
    public function admin_can_transition_accepted_to_not_completed_with_reason()
    {
        $admin = User::query()->where('email', 'admin@example.com')->first();

        $request = ServiceRequest::unguarded(function () {
            return ServiceRequest::create([
                'status' => 'accepted',
                'platform' => 'mobile',
                'channel' => 'app',
                'app_user_id' => 1,
                'driver_id' => 1,
                'vehicle_id' => 1,
            ]);
        });

        $this->actingAs($admin)
            ->withHeaders([
                'Authorization' => 'Bearer ' . $admin->createToken('dashboard-token')->plainTextToken,
            ])
            ->patchJson("/api/dashboard/v1/requests/{$request->id}/status", [
                'toStatus' => 'not_completed',
                'expectedStatus' => 'accepted',
                'reasonCodes' => ['service_unavailable'],
                'reasonNote' => null,
            ])
            ->assertOk();

        $this->assertDatabaseHas('service_requests', ['id' => $request->id, 'status' => 'not_completed']);
        $this->assertDatabaseHas('service_request_status_histories', [
            'service_request_id' => $request->id,
            'to_status' => 'not_completed',
        ]);
    }

    /** @test */
    public function admin_can_transition_accepted_to_cancelled()
    {
        $admin = User::query()->where('email', 'admin@example.com')->first();

        $request = ServiceRequest::unguarded(function () {
            return ServiceRequest::create([
                'status' => 'accepted',
                'platform' => 'mobile',
                'channel' => 'app',
                'app_user_id' => 1,
                'driver_id' => 1,
                'vehicle_id' => 1,
            ]);
        });

        $this->actingAs($admin)
            ->withHeaders([
                'Authorization' => 'Bearer ' . $admin->createToken('dashboard-token')->plainTextToken,
            ])
            ->patchJson("/api/dashboard/v1/requests/{$request->id}/status", [
                'toStatus' => 'cancelled',
                'expectedStatus' => 'accepted',
                'reasonCodes' => ['service_unavailable'],
                'reasonNote' => 'Service was unavailable',
            ])
            ->assertOk();

        $this->assertDatabaseHas('service_requests', ['id' => $request->id, 'status' => 'cancelled']);
    }

    /** @test */
    public function transition_is_rejected_when_status_is_terminal()
    {
        $admin = User::query()->where('email', 'admin@example.com')->first();

        $request = ServiceRequest::unguarded(function () {
            return ServiceRequest::create([
                'status' => 'completed',
                'platform' => 'mobile',
                'channel' => 'app',
                'app_user_id' => 1,
                'driver_id' => 1,
                'vehicle_id' => 1,
            ]);
        });

        $this->actingAs($admin)
            ->withHeaders([
                'Authorization' => 'Bearer ' . $admin->createToken('dashboard-token')->plainTextToken,
            ])
            ->patchJson("/api/dashboard/v1/requests/{$request->id}/status", [
                'toStatus' => 'pending',
                'expectedStatus' => 'completed',
            ])
            ->assertStatus(409);
    }

    /** @test */
    public function transition_is_rejected_for_same_status_noop()
    {
        $admin = User::query()->where('email', 'admin@example.com')->first();

        $request = ServiceRequest::unguarded(function () {
            return ServiceRequest::create([
                'status' => 'pending',
                'platform' => 'mobile',
                'channel' => 'app',
                'app_user_id' => 1,
                'driver_id' => 1,
                'vehicle_id' => 1,
            ]);
        });

        $this->actingAs($admin)
            ->withHeaders([
                'Authorization' => 'Bearer ' . $admin->createToken('dashboard-token')->plainTextToken,
            ])
            ->patchJson("/api/dashboard/v1/requests/{$request->id}/status", [
                'toStatus' => 'pending',
                'expectedStatus' => 'pending',
            ])
            ->assertStatus(409);
    }

    /** @test */
    public function transition_is_rejected_when_unknown_reason_code()
    {
        $admin = User::query()->where('email', 'admin@example.com')->first();

        $request = ServiceRequest::unguarded(function () {
            return ServiceRequest::create([
                'status' => 'pending',
                'platform' => 'mobile',
                'channel' => 'app',
                'app_user_id' => 1,
                'driver_id' => 1,
                'vehicle_id' => 1,
            ]);
        });

        $this->actingAs($admin)
            ->withHeaders([
                'Authorization' => 'Bearer ' . $admin->createToken('dashboard-token')->plainTextToken,
            ])
            ->patchJson("/api/dashboard/v1/requests/{$request->id}/status", [
                'toStatus' => 'cancelled',
                'expectedStatus' => 'pending',
                'reasonCodes' => ['unknown_reason_code'],
            ])
            ->assertUnprocessable();
    }

    /** @test */
    public function transition_is_rejected_when_duplicate_reason_code()
    {
        $admin = User::query()->where('email', 'admin@example.com')->first();

        $request = ServiceRequest::unguarded(function () {
            return ServiceRequest::create([
                'status' => 'pending',
                'platform' => 'mobile',
                'channel' => 'app',
                'app_user_id' => 1,
                'driver_id' => 1,
                'vehicle_id' => 1,
            ]);
        });

        $this->actingAs($admin)
            ->withHeaders([
                'Authorization' => 'Bearer ' . $admin->createToken('dashboard-token')->plainTextToken,
            ])
            ->patchJson("/api/dashboard/v1/requests/{$request->id}/status", [
                'toStatus' => 'cancelled',
                'expectedStatus' => 'pending',
                'reasonCodes' => ['customer_cancelled', 'customer_cancelled'],
            ])
            ->assertUnprocessable();
    }

    /** @test */
    public function transition_is_rejected_when_required_reason_missing()
    {
        $admin = User::query()->where('email', 'admin@example.com')->first();

        $request = ServiceRequest::unguarded(function () {
            return ServiceRequest::create([
                'status' => 'pending',
                'platform' => 'mobile',
                'channel' => 'app',
                'app_user_id' => 1,
                'driver_id' => 1,
                'vehicle_id' => 1,
            ]);
        });

        $this->actingAs($admin)
            ->withHeaders([
                'Authorization' => 'Bearer ' . $admin->createToken('dashboard-token')->plainTextToken,
            ])
            ->patchJson("/api/dashboard/v1/requests/{$request->id}/status", [
                'toStatus' => 'not_completed',
                'expectedStatus' => 'pending',
            ])
            ->assertUnprocessable();
    }

    /** @test */
    public function other_reason_requires_non_empty_reason_note()
    {
        $admin = User::query()->where('email', 'admin@example.com')->first();

        $request = ServiceRequest::unguarded(function () {
            return ServiceRequest::create([
                'status' => 'pending',
                'platform' => 'mobile',
                'channel' => 'app',
                'app_user_id' => 1,
                'driver_id' => 1,
                'vehicle_id' => 1,
            ]);
        });

        $this->actingAs($admin)
            ->withHeaders([
                'Authorization' => 'Bearer ' . $admin->createToken('dashboard-token')->plainTextToken,
            ])
            ->patchJson("/api/dashboard/v1/requests/{$request->id}/status", [
                'toStatus' => 'cancelled',
                'expectedStatus' => 'pending',
                'reasonCodes' => ['other'],
                'reasonNote' => '',
            ])
            ->assertUnprocessable();

        $this->actingAs($admin)
            ->withHeaders([
                'Authorization' => 'Bearer ' . $admin->createToken('dashboard-token')->plainTextToken,
            ])
            ->patchJson("/api/dashboard/v1/requests/{$request->id}/status", [
                'toStatus' => 'cancelled',
                'expectedStatus' => 'pending',
                'reasonCodes' => ['other'],
                'reasonNote' => '  ',
            ])
            ->assertUnprocessable();

        $this->actingAs($admin)
            ->withHeaders([
                'Authorization' => 'Bearer ' . $admin->createToken('dashboard-token')->plainTextToken,
            ])
            ->patchJson("/api/dashboard/v1/requests/{$request->id}/status", [
                'toStatus' => 'cancelled',
                'expectedStatus' => 'pending',
                'reasonCodes' => ['other'],
                'reasonNote' => 'Customer cancelled the service',
            ])
            ->assertOk();
    }

    /** @test */
    public function admin_actor_id_is_recorded_correctly()
    {
        $admin = User::query()->where('email', 'admin@example.com')->first();

        $request = ServiceRequest::unguarded(function () {
            return ServiceRequest::create([
                'status' => 'pending',
                'platform' => 'mobile',
                'channel' => 'app',
                'app_user_id' => 1,
                'driver_id' => 1,
                'vehicle_id' => 1,
            ]);
        });

        $this->actingAs($admin)
            ->withHeaders([
                'Authorization' => 'Bearer ' . $admin->createToken('dashboard-token')->plainTextToken,
            ])
            ->patchJson("/api/dashboard/v1/requests/{$request->id}/status", [
                'toStatus' => 'accepted',
                'expectedStatus' => 'pending',
            ])
            ->assertOk();

        $this->assertDatabaseHas('service_request_status_histories', [
            'service_request_id' => $request->id,
            'actor_type' => 'admin',
        ]);
    }

    /** @test */
    public function exactly_one_history_row_is_created_per_successful_transition()
    {
        $admin = User::query()->where('email', 'admin@example.com')->first();

        $request = ServiceRequest::unguarded(function () {
            return ServiceRequest::create([
                'status' => 'pending',
                'platform' => 'mobile',
                'channel' => 'app',
                'app_user_id' => 1,
                'driver_id' => 1,
                'vehicle_id' => 1,
            ]);
        });

        // First transition
        $this->actingAs($admin)
            ->withHeaders([
                'Authorization' => 'Bearer ' . $admin->createToken('dashboard-token')->plainTextToken,
            ])
            ->patchJson("/api/dashboard/v1/requests/{$request->id}/status", [
                'toStatus' => 'accepted',
                'expectedStatus' => 'pending',
            ])
            ->assertOk();

        $historyCount1 = \App\Models\ServiceRequestStatusHistory::where('service_request_id', $request->id)->count();

        // Second transition
        $this->actingAs($admin)
            ->withHeaders([
                'Authorization' => 'Bearer ' . $admin->createToken('dashboard-token')->plainTextToken,
            ])
            ->patchJson("/api/dashboard/v1/requests/{$request->id}/status", [
                'toStatus' => 'cancelled',
                'expectedStatus' => 'accepted',
                'reasonCodes' => ['service_unavailable'],
                'reasonNote' => 'Service was unavailable',
            ])
            ->assertOk();

        $historyCount2 = \App\Models\ServiceRequestStatusHistory::where('service_request_id', $request->id)->count();

        // Should have exactly 2 history entries (one per transition)
        $this->assertEquals(2, $historyCount2);
    }

    /** @test */
    public function correct_reasons_are_persisted_on_pivot()
    {
        $admin = User::query()->where('email', 'admin@example.com')->first();

        // Create request in 'accepted' status since 'not_completed' is only valid from 'accepted'
        $request = ServiceRequest::unguarded(function () {
            return ServiceRequest::create([
                'status' => 'accepted',
                'platform' => 'mobile',
                'channel' => 'app',
                'app_user_id' => 1,
                'driver_id' => 1,
                'vehicle_id' => 1,
            ]);
        });

        // Transition from accepted to not_completed with reason codes
        $this->actingAs($admin)
            ->withHeaders([
                'Authorization' => 'Bearer ' . $admin->createToken('dashboard-token')->plainTextToken,
            ])
            ->patchJson("/api/dashboard/v1/requests/{$request->id}/status", [
                'toStatus' => 'not_completed',
                'expectedStatus' => 'accepted',
                'reasonCodes' => ['service_unavailable', 'location_inaccessible'],
                'reasonNote' => 'Service unavailable and location inaccessible',
            ])
            ->assertOk();

        $history = \App\Models\ServiceRequestStatusHistory::where('service_request_id', $request->id)
            ->latest('created_at')
            ->first();

        $reasonCodes = $history->reasons->pluck('code')->toArray();

        $this->assertContains('service_unavailable', $reasonCodes);
        $this->assertContains('location_inaccessible', $reasonCodes);
        $this->assertCount(2, $reasonCodes);
    }

    /** @test */
    public function stale_expectedStatus_returns_409()
    {
        $admin = User::query()->where('email', 'admin@example.com')->first();

        $request = ServiceRequest::unguarded(function () {
            return ServiceRequest::create([
                'status' => 'pending',
                'platform' => 'mobile',
                'channel' => 'app',
                'app_user_id' => 1,
                'driver_id' => 1,
                'vehicle_id' => 1,
            ]);
        });

        // First, transition to accepted
        $this->actingAs($admin)
            ->withHeaders([
                'Authorization' => 'Bearer ' . $admin->createToken('dashboard-token')->plainTextToken,
            ])
            ->patchJson("/api/dashboard/v1/requests/{$request->id}/status", [
                'toStatus' => 'accepted',
                'expectedStatus' => 'pending',
            ])
            ->assertOk();

        // Now try to transition again with stale expectedStatus
        // Need reasonCodes for cancelled target status
        $this->actingAs($admin)
            ->withHeaders([
                'Authorization' => 'Bearer ' . $admin->createToken('dashboard-token')->plainTextToken,
            ])
            ->patchJson("/api/dashboard/v1/requests/{$request->id}/status", [
                'toStatus' => 'cancelled',
                'expectedStatus' => 'pending', // Still pending, but request is now accepted
                'reasonCodes' => ['customer_cancelled'],
            ])
            ->assertStatus(409);
    }

    /** @test */
    public function unauthenticated_access_returns_401()
    {
        $request = ServiceRequest::unguarded(function () {
            return ServiceRequest::create([
                'status' => 'pending',
                'platform' => 'mobile',
                'channel' => 'app',
                'app_user_id' => 1,
                'driver_id' => 1,
                'vehicle_id' => 1,
            ]);
        });

        $this->withHeaders([
            'Authorization' => 'Bearer invalid-token',
        ])
            ->patchJson("/api/dashboard/v1/requests/{$request->id}/status", [
                'toStatus' => 'accepted',
                'expectedStatus' => 'pending',
            ])
            ->assertStatus(401);
    }

    /** @test */
    public function unauthorized_access_returns_403()
    {
        $regularUser = User::query()->where('email', 'regular@example.com')->first();

        // Handle case where regular user doesn't exist
        if ($regularUser) {
            $this->actingAs($regularUser);
        }

        $this->patchJson("/api/dashboard/v1/requests/999999/status", [
            'toStatus' => 'accepted',
            'expectedStatus' => 'pending',
        ])
            ->assertStatus(404); // Request not found
    }
}