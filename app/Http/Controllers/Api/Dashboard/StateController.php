<?php

namespace App\Http\Controllers\Api\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\City;
use App\Models\Country;
use App\Models\State;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class StateController extends Controller
{
    public function index(Request $request): \Illuminate\Http\JsonResponse
    {
        $states = State::query()
            ->when($request->query('countryId'), fn ($query, $countryId) => $query->where('country_id', $countryId))
            ->when($request->query('query'), function ($query, $search) {
                $query->where(function ($query) use ($search) {
                    $query->where('name_en', 'like', "%{$search}%")
                        ->orWhere('name_ar', 'like', "%{$search}%");
                });
            })
            ->latest('id')
            ->paginate((int) $request->query('limit', 10));

        return response()->success([
            'list' => $states->getCollection()->map(fn (State $state) => $this->stateResource($state))->values(),
            'metaData' => $this->paginationMeta($states),
        ]);
    }

    public function storeBulk(Request $request): \Illuminate\Http\JsonResponse
    {
        $payload = $request->all();
        unset($payload['_locale']);

        $data = validator($payload, [
            '*.stateEN' => ['required', 'string', 'max:255'],
            '*.stateAR' => ['required', 'string', 'max:255'],
            '*.countryID' => ['required', 'array'],
            '*.active' => ['nullable', 'string'],
        ])->validate();

        $states = collect($data)->map(function (array $item) {
            $countryId = $item['countryID']['$oid'] ?? $item['countryID']['oid'] ?? null;
            if (! $countryId || ! Country::query()->whereKey($countryId)->exists()) {
                throw ValidationException::withMessages(['countryID' => 'The selected countryID is invalid.']);
            }

            return State::query()->create([
                'name_en' => $item['stateEN'],
                'name_ar' => $item['stateAR'],
                'country_id' => $countryId,
                'status' => 'active',
            ]);
        });

        return response()->success([
            'count' => $states->count(),
            'list' => $states->map(fn (State $state) => $this->stateResource($state))->values(),
        ], 'States imported successfully');
    }

    public function show(State $state): \Illuminate\Http\JsonResponse
    {
        return response()->success($this->stateResource($state->load('country')));
    }

    public function cities(State $state): \Illuminate\Http\JsonResponse
    {
        $cities = $state->cities()->latest('id')->get();

        return response()->success([
            'count' => $cities->count(),
            'list' => $cities->map(fn (City $city) => app(CityController::class)->cityResource($city))->values(),
        ]);
    }

    public function stateResource(State $state): array
    {
        return [
            'id' => $state->id,
            '_id' => (string) $state->id,
            'name' => ['en' => $state->name_en, 'ar' => $state->name_ar],
            'status' => $state->status,
            'countryId' => $state->country ? app(CountryController::class)->countryResource($state->country) : (string) $state->country_id,
            'createdAt' => optional($state->created_at)->toJSON(),
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
