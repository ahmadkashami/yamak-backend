<?php

namespace App\Http\Controllers\Api\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class BranchController extends Controller
{
    public function index(Request $request): \Illuminate\Http\JsonResponse
    {
        $branches = Branch::query()
            ->when($request->query('companyId'), fn ($query, $companyId) => $query->where('company_id', $companyId))
            ->when($request->query('query'), fn ($query, $search) => $query->where('name', 'like', "%{$search}%"))
            ->latest('id')
            ->paginate((int) $request->query('limit', 10));

        return response()->success([
            'list' => $branches->getCollection()->map(fn (Branch $branch) => $this->branchResource($branch))->values(),
            'metaData' => $this->paginationMeta($branches),
        ]);
    }

    public function store(Request $request): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate($this->rules());
        $branch = Branch::query()->create($this->payload($data));
        $this->syncServices($branch, $data['services'] ?? []);

        return response()->success($this->branchResource($branch->fresh()), 'Branch created successfully');
    }

    public function show(Branch $branch): \Illuminate\Http\JsonResponse
    {
        return response()->success($this->branchResource($branch));
    }

    public function update(Request $request, Branch $branch): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate($this->rules(false));
        $branch->update($this->payload($data, $branch));

        if (array_key_exists('services', $data)) {
            $this->syncServices($branch, $data['services'] ?? []);
        }

        return response()->success($this->branchResource($branch->fresh()), 'Branch updated successfully');
    }

    public function services(Branch $branch): \Illuminate\Http\JsonResponse
    {
        if (! Schema::hasTable('services')) {
            return response()->success([]);
        }

        $services = DB::table('branch_service')
            ->join('services', 'branch_service.service_id', '=', 'services.id')
            ->where('branch_service.branch_id', $branch->id)
            ->select('services.*')
            ->get();

        return response()->success($services->map(fn ($service) => [
            'id' => $service->id,
            '_id' => (string) $service->id,
            'name' => ['en' => $service->name_en ?? $service->name, 'ar' => $service->name_ar ?? $service->name],
            'logo' => $service->logo ?? null,
            'status' => $service->status ?? null,
        ])->values());
    }

    public function drivers(Branch $branch, Request $request): \Illuminate\Http\JsonResponse
    {
        $request->merge(['branchId' => $branch->id]);

        return app(DriverController::class)->index($request);
    }

    public function freeDrivers(Branch $branch, Request $request): \Illuminate\Http\JsonResponse
    {
        $request->merge(['branchId' => $branch->id, 'filterType' => 'freeDrivers']);

        return app(DriverController::class)->index($request);
    }

    public function branchResource(Branch $branch): array
    {
        $serviceIds = DB::table('branch_service')
            ->where('branch_id', $branch->id)
            ->pluck('service_id')
            ->map(fn ($id) => (string) $id)
            ->values()
            ->all();

        return [
            'id' => $branch->id,
            '_id' => (string) $branch->id,
            'name' => $branch->name,
            'phone' => $branch->phone,
            'companyId' => (string) $branch->company_id,
            'logo' => $branch->logo,
            'status' => $branch->status,
            'appCode' => $branch->app_code,
            'address' => [
                'countryId' => $branch->country_id ? (string) $branch->country_id : null,
                'stateId' => $branch->state_id ? (string) $branch->state_id : null,
                'cityId' => $branch->city_id ? (string) $branch->city_id : null,
                'block' => $branch->block,
                'street' => $branch->street,
                'zipCode' => $branch->zip_code,
            ],
            'geoLocation' => [
                'latitude' => $branch->latitude ? (float) $branch->latitude : 0,
                'longitude' => $branch->longitude ? (float) $branch->longitude : 0,
            ],
            'services' => $serviceIds,
            'createdAt' => optional($branch->created_at)->toJSON(),
        ];
    }

    private function rules(bool $creating = true): array
    {
        return [
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:255'],
            'companyId' => [$creating ? 'required' : 'sometimes', 'integer', 'exists:companies,id'],
            'logo' => ['nullable', 'string'],
            'status' => ['nullable', 'string', 'max:50'],
            'address.countryId' => ['nullable', 'integer'],
            'address.stateId' => ['nullable', 'integer'],
            'address.cityId' => ['nullable', 'integer'],
            'address.block' => ['nullable', 'string', 'max:255'],
            'address.street' => ['nullable', 'string', 'max:255'],
            'address.zipCode' => ['nullable', 'string', 'max:255'],
            'geoLocation.latitude' => ['nullable', 'numeric'],
            'geoLocation.longitude' => ['nullable', 'numeric'],
            'services' => ['nullable', 'array'],
            'services.*' => ['integer'],
        ];
    }

    private function payload(array $data, ?Branch $branch = null): array
    {
        $address = $data['address'] ?? [];
        $location = $data['geoLocation'] ?? [];
        $name = $data['name'] ?? $branch?->name;

        return [
            'company_id' => $data['companyId'] ?? $branch?->company_id,
            'name' => $name,
            'phone' => $data['phone'] ?? $branch?->phone,
            'app_code' => $branch?->app_code ?: $this->generateAppCode($name),
            'logo' => $data['logo'] ?? $branch?->logo,
            'status' => $data['status'] ?? $branch?->status ?? 'active',
            'country_id' => $address['countryId'] ?? $branch?->country_id,
            'state_id' => $address['stateId'] ?? $branch?->state_id,
            'city_id' => $address['cityId'] ?? $branch?->city_id,
            'block' => $address['block'] ?? $branch?->block,
            'street' => $address['street'] ?? $branch?->street,
            'zip_code' => $address['zipCode'] ?? $branch?->zip_code,
            'latitude' => $location['latitude'] ?? $branch?->latitude,
            'longitude' => $location['longitude'] ?? $branch?->longitude,
        ];
    }

    private function syncServices(Branch $branch, array $serviceIds): void
    {
        DB::table('branch_service')->where('branch_id', $branch->id)->delete();

        foreach (array_unique(array_filter($serviceIds)) as $serviceId) {
            DB::table('branch_service')->insert([
                'branch_id' => $branch->id,
                'service_id' => $serviceId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function generateAppCode(?string $name): string
    {
        $prefix = strtoupper(substr(preg_replace('/\s+/', '', $name ?: 'BRA'), 0, 3));
        $prefix = $prefix ?: 'BRA';

        return 'BRA-'.$prefix.'-'.Str::upper(Str::random(5));
    }

    private function paginationMeta($paginator): array
    {
        return [
            'total' => $paginator->total(),
            'page' => $paginator->currentPage(),
            'limit' => $paginator->perPage(),
            'lastPage' => $paginator->lastPage(),
        ];
    }
}
