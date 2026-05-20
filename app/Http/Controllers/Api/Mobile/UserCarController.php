<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\AppUser;
use App\Models\UserCar;
use Illuminate\Http\Request;

class UserCarController extends Controller
{
    public function index(Request $request): \Illuminate\Http\JsonResponse
    {
        /** @var AppUser $user */
        $user = $request->user();

        return response()->success(
            UserCar::query()
                ->where('app_user_id', $user->id)
                ->latest('id')
                ->get()
                ->map(fn (UserCar $car) => $this->carResource($car))
                ->values()
        );
    }

    public function store(Request $request): \Illuminate\Http\JsonResponse
    {
        /** @var AppUser $user */
        $user = $request->user();

        $data = $request->validate([
            'make' => ['nullable', 'string', 'max:255'],
            'model' => ['nullable', 'string', 'max:255'],
            'year' => ['nullable', 'string', 'max:20'],
            'type' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'max:50'],
        ]);

        $car = UserCar::query()->create([
            'app_user_id' => $user->id,
            'make' => $data['make'] ?? null,
            'model' => $data['model'] ?? null,
            'year' => $data['year'] ?? null,
            'type' => $data['type'] ?? 'Car',
            'status' => $data['status'] ?? 'active',
        ]);

        return response()->success($this->carResource($car));
    }

    public function destroy(UserCar $userCar): \Illuminate\Http\JsonResponse
    {
        $userCar->delete();

        return response()->success($this->carResource($userCar));
    }

    private function carResource(UserCar $car): array
    {
        return [
            'id' => $car->id,
            '_id' => (string) $car->id,
            'make' => $car->make,
            'model' => $car->model,
            'userId' => (string) $car->app_user_id,
            'year' => $car->year,
            'type' => $car->type,
            'status' => $car->status,
            'createdAt' => optional($car->created_at)->toJSON(),
            'updatedAt' => optional($car->updated_at)->toJSON(),
        ];
    }
}
