<?php

namespace App\Http\Controllers\Api\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Country;
use App\Models\State;
use Illuminate\Http\Request;

class CountryController extends Controller
{
    public function index(Request $request): \Illuminate\Http\JsonResponse
    {
        $countries = Country::query()
            ->when($request->query('query'), function ($query, $search) {
                $query->where(function ($query) use ($search) {
                    $query->where('name_en', 'like', "%{$search}%")
                        ->orWhere('name_ar', 'like', "%{$search}%")
                        ->orWhere('country_code', 'like', "%{$search}%")
                        ->orWhere('currency', 'like', "%{$search}%");
                });
            })
            ->latest('id')
            ->paginate((int) $request->query('limit', 10));

        return response()->success([
            'list' => $countries->getCollection()->map(fn (Country $country) => $this->countryResource($country))->values(),
            'metaData' => $this->paginationMeta($countries),
        ]);
    }

    public function store(Request $request): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate($this->rules());
        $country = Country::query()->create($this->payload($data));

        return response()->success($this->countryResource($country), 'Country created successfully');
    }

    public function show(Country $country): \Illuminate\Http\JsonResponse
    {
        return response()->success($this->countryResource($country));
    }

    public function update(Request $request, Country $country): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate($this->rules(false));
        $country->update($this->payload($data, $country));

        return response()->success($this->countryResource($country->fresh()), 'Country updated successfully');
    }

    public function states(Country $country): \Illuminate\Http\JsonResponse
    {
        $states = $country->states()->latest('id')->get();

        return response()->success([
            'count' => $states->count(),
            'list' => $states->map(fn (State $state) => app(StateController::class)->stateResource($state))->values(),
        ]);
    }

    public function destroy(Country $country): \Illuminate\Http\JsonResponse
    {
        $country->delete();

        return response()->success(null, 'Country deleted successfully');
    }

    public function countryResource(Country $country): array
    {
        return [
            'id' => $country->id,
            '_id' => (string) $country->id,
            'name' => ['en' => $country->name_en, 'ar' => $country->name_ar],
            'countryCode' => $country->country_code,
            'currency' => $country->currency,
            'status' => $country->status,
            'createdAt' => optional($country->created_at)->toJSON(),
        ];
    }

    private function rules(bool $creating = true): array
    {
        return [
            'name.en' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'name.ar' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'countryCode' => [$creating ? 'required' : 'sometimes', 'string', 'max:50'],
            'currency' => [$creating ? 'required' : 'sometimes', 'string', 'max:50'],
            'status' => ['nullable', 'string', 'max:50'],
        ];
    }

    private function payload(array $data, ?Country $country = null): array
    {
        $name = $data['name'] ?? [];

        return [
            'name_en' => $name['en'] ?? $country?->name_en,
            'name_ar' => $name['ar'] ?? $country?->name_ar,
            'country_code' => $data['countryCode'] ?? $country?->country_code,
            'currency' => $data['currency'] ?? $country?->currency,
            'status' => $data['status'] ?? $country?->status ?? 'active',
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
