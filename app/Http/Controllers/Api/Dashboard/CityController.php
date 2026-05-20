<?php

namespace App\Http\Controllers\Api\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\City;
use App\Models\State;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CityController extends Controller
{
    public function index(Request $request): \Illuminate\Http\JsonResponse
    {
        $cities = City::query()
            ->with('state')
            ->when($request->query('stateId'), fn ($query, $stateId) => $query->where('state_id', $stateId))
            ->when($request->query('query'), function ($query, $search) {
                $query->where(function ($query) use ($search) {
                    $query->where('name_en', 'like', "%{$search}%")
                        ->orWhere('name_ar', 'like', "%{$search}%");
                });
            })
            ->latest('id')
            ->paginate((int) $request->query('limit', 10));

        return response()->success([
            'list' => $cities->getCollection()->map(fn (City $city) => $this->cityResource($city))->values(),
            'metaData' => $this->paginationMeta($cities),
        ]);
    }

    public function storeBulk(Request $request): \Illuminate\Http\JsonResponse
    {
        $payload = $request->all();
        unset($payload['_locale']);

        $data = validator($payload, [
            '*.cityEN' => ['required', 'string', 'max:255'],
            '*.cityAR' => ['required', 'string', 'max:255'],
            '*.stateID' => ['required', 'array'],
            '*.active' => ['nullable', 'string'],
        ])->validate();

        $cities = collect($data)->map(function (array $item) {
            $stateId = $item['stateID']['$oid'] ?? $item['stateID']['oid'] ?? null;
            if (! $stateId || ! State::query()->whereKey($stateId)->exists()) {
                throw ValidationException::withMessages(['stateID' => 'The selected stateID is invalid.']);
            }

            return City::query()->create([
                'name_en' => $item['cityEN'],
                'name_ar' => $item['cityAR'],
                'state_id' => $stateId,
                'status' => 'active',
            ]);
        });

        return response()->success([
            'count' => $cities->count(),
            'list' => $cities->map(fn (City $city) => $this->cityResource($city))->values(),
        ], 'Cities imported successfully');
    }

    public function show(City $city): \Illuminate\Http\JsonResponse
    {
        return response()->success($this->cityResource($city->load('state')));
    }

    public function cityResource(City $city): array
    {
        return [
            'id' => $city->id,
            '_id' => (string) $city->id,
            'name' => ['en' => $city->name_en, 'ar' => $city->name_ar],
            'status' => $city->status,
            'stateId' => $city->state ? app(StateController::class)->stateResource($city->state) : (string) $city->state_id,
            'state' => $city->state ? app(StateController::class)->stateResource($city->state) : null,
            'createdAt' => optional($city->created_at)->toJSON(),
        ];
    }

    private function paginationMeta($paginator): array
    {
        return [
            'totalCount' => $paginator->total(),
            'currentCount' => $paginator->count(),
            'currentPage' => $paginator->currentPage(),
            'itemsPerPage' => $paginator->perPage(),
            'lastPage' => $paginator->lastPage(),
        ];
    }
}
