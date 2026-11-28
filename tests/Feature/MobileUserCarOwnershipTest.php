<?php

namespace Tests\Feature;

use App\Models\AppUser;
use App\Models\User;
use App\Models\UserCar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MobileUserCarOwnershipTest extends TestCase
{
    use RefreshDatabase;

    protected function connectionsToTransact(): array
    {
        return [config('database.default')];
    }

    public function test_owner_can_soft_delete_own_car_with_existing_response(): void
    {
        $owner = $this->makeUser('owner');
        $car = $owner->cars()->create(['make' => 'Toyota', 'model' => 'Camry', 'year' => '2024']);
        Sanctum::actingAs($owner, ['mobile']);

        $this->deleteJson('/api/mobile/v1/user-cars/'.$car->id)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $car->id)
            ->assertJsonPath('data._id', (string) $car->id)
            ->assertJsonPath('data.userId', (string) $owner->id)
            ->assertJsonPath('data.make', 'Toyota')
            ->assertJsonPath('data.model', 'Camry')
            ->assertJsonPath('data.year', '2024')
            ->assertJsonPath('errors', null);

        $this->assertSoftDeleted('user_cars', ['id' => $car->id]);
        $this->assertSame('Camry', UserCar::withTrashed()->findOrFail($car->id)->model);
    }

    public function test_foreign_car_and_unknown_car_return_identical_404_without_mutation(): void
    {
        $owner = $this->makeUser('owner');
        $other = $this->makeUser('other');
        $car = $owner->cars()->create(['make' => 'Private make', 'model' => 'Private model']);
        $before = $car->fresh()->getRawOriginal();
        Sanctum::actingAs($other, ['mobile']);

        $foreign = $this->deleteJson('/api/mobile/v1/user-cars/'.$car->id, [
            'userId' => $owner->id,
            'app_user_id' => $owner->id,
        ])->assertNotFound()
            ->assertJsonPath('success', false)
            ->assertJsonPath('data', null);

        $unknown = $this->deleteJson('/api/mobile/v1/user-cars/999999999')
            ->assertNotFound()
            ->assertJsonPath('success', false)
            ->assertJsonPath('data', null);

        $this->assertSame($unknown->json(), $foreign->json());
        $this->assertSame($before, $car->fresh()->getRawOriginal());
        $this->assertDatabaseCount('user_cars', 1);
    }

    public function test_unauthenticated_delete_retains_401_and_does_not_modify_car(): void
    {
        $owner = $this->makeUser('owner');
        $car = $owner->cars()->create(['make' => 'Toyota']);
        $before = $car->fresh()->getRawOriginal();

        $this->deleteJson('/api/mobile/v1/user-cars/'.$car->id)
            ->assertUnauthorized()
            ->assertJsonPath('success', false)
            ->assertJsonPath('data', null);

        $this->assertSame($before, $car->fresh()->getRawOriginal());
    }

    public function test_already_deleted_car_returns_404(): void
    {
        $owner = $this->makeUser('owner');
        $car = $owner->cars()->create(['make' => 'Toyota']);
        $car->delete();
        $before = UserCar::withTrashed()->findOrFail($car->id)->getRawOriginal();
        Sanctum::actingAs($owner, ['mobile']);

        $this->deleteJson('/api/mobile/v1/user-cars/'.$car->id)->assertNotFound();

        $this->assertSame($before, UserCar::withTrashed()->findOrFail($car->id)->getRawOriginal());
    }

    public function test_non_app_user_with_colliding_id_cannot_delete_car(): void
    {
        $owner = $this->makeUser('owner');
        $car = $owner->cars()->create(['make' => 'Toyota']);
        $before = $car->fresh()->getRawOriginal();
        $wrongPrincipal = new User;
        $wrongPrincipal->id = $owner->id;
        Sanctum::actingAs($wrongPrincipal, ['mobile']);

        $this->deleteJson('/api/mobile/v1/user-cars/'.$car->id)->assertNotFound();

        $this->assertSame($before, $car->fresh()->getRawOriginal());
    }

    private function makeUser(string $label): AppUser
    {
        return AppUser::create([
            'name' => 'B52 '.$label,
            'email' => 'b52-'.$label.'@example.invalid',
            'password' => 'synthetic-test-password',
        ]);
    }
}
