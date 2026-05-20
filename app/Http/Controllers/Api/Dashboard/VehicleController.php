<?php

namespace App\Http\Controllers\Api\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Driver;
use App\Models\Vehicle;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class VehicleController extends Controller
{
    public function index(Request $request): \Illuminate\Http\JsonResponse
    {
        $vehicles = Vehicle::query()
            ->with(['branch', 'company', 'services', 'drivers', 'currentDriver'])
            ->when($request->query('companyId'), fn ($query, $companyId) => $query->where('company_id', $companyId))
            ->when($request->query('branchId'), fn ($query, $branchId) => $query->where('branch_id', $branchId))
            ->when($request->query('query'), fn ($query, $search) => $query->where('app_code', 'like', "%{$search}%"))
            ->latest('id')
            ->paginate((int) $request->query('limit', 10));

        return response()->success([
            'list' => $vehicles->getCollection()->map(fn (Vehicle $vehicle) => $this->vehicleResource($vehicle))->values(),
            'metaData' => $this->paginationMeta($vehicles),
        ]);
    }

    public function store(Request $request): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate($this->rules());
        $branch = Branch::query()->with('company')->findOrFail($data['branchId']);
        $vehicle = Vehicle::query()->create($this->payload($data, null, $branch));
        $this->syncRelations($vehicle, $data);

        return response()->success($this->vehicleResource($vehicle->fresh(['branch', 'company', 'services', 'drivers', 'currentDriver'])), 'Vehicle created successfully');
    }

    public function show(Vehicle $vehicle): \Illuminate\Http\JsonResponse
    {
        return response()->success($this->vehicleResource($vehicle->load(['branch', 'company', 'services', 'drivers', 'currentDriver'])));
    }

    public function update(Request $request, Vehicle $vehicle): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate($this->rules(false));
        $branch = isset($data['branchId']) ? Branch::query()->with('company')->findOrFail($data['branchId']) : null;
        $vehicle->update($this->payload($data, $vehicle, $branch));
        $this->syncRelations($vehicle, $data);

        return response()->success($this->vehicleResource($vehicle->fresh(['branch', 'company', 'services', 'drivers', 'currentDriver'])), 'Vehicle updated successfully');
    }

    public function vehicleResource(Vehicle $vehicle): array
    {
        return [
            'id' => $vehicle->id,
            '_id' => (string) $vehicle->id,
            'status' => $vehicle->status,
            'companyId' => (string) $vehicle->company_id,
            'company' => $vehicle->company ? app(CompanyController::class)->companyResource($vehicle->company) : null,
            'branchId' => (string) $vehicle->branch_id,
            'branch' => $vehicle->branch ? app(BranchController::class)->branchResource($vehicle->branch) : null,
            'drivers' => $vehicle->drivers->map(fn (Driver $driver) => app(DriverController::class)->driverResource($driver))->values(),
            'appCode' => $vehicle->app_code,
            'services' => $vehicle->services->map(fn ($service) => app(ServiceController::class)->serviceResource($service))->values(),
            'createdAt' => optional($vehicle->created_at)->toJSON(),
            'workStatus' => $vehicle->work_status,
            'lastActiveDate' => optional($vehicle->last_active_date)->toJSON(),
            'currentDriver' => $vehicle->currentDriver ? app(DriverController::class)->driverResource($vehicle->currentDriver) : null,
            'iconMap' => $vehicle->icon_map,
            'labelNumber' => $vehicle->label_number,
            'modelName' => $vehicle->model_name,
            'geoLocation' => [
                'latitude' => $vehicle->latitude ? (float) $vehicle->latitude : 0,
                'longitude' => $vehicle->longitude ? (float) $vehicle->longitude : 0,
            ],
        ];
    }

    private function rules(bool $creating = true): array
    {
        return [
            'companyId' => ['nullable', 'integer', 'exists:companies,id'],
            'branchId' => [$creating ? 'required' : 'sometimes', 'integer', 'exists:branches,id'],
            'services' => ['nullable', 'array'],
            'services.*' => ['integer', 'exists:services,id'],
            'drivers' => ['nullable', 'array'],
            'drivers.*' => ['integer', 'exists:drivers,id'],
            'status' => ['nullable', 'string', 'max:50'],
            'workStatus' => ['nullable', 'string', 'max:50'],
            'geoLocation.latitude' => ['nullable', 'numeric'],
            'geoLocation.longitude' => ['nullable', 'numeric'],
            'iconMap' => ['nullable', 'string'],
            'labelNumber' => ['nullable', 'string', 'max:255'],
            'modelName' => ['nullable', 'string', 'max:255'],
        ];
    }

    private function payload(array $data, ?Vehicle $vehicle = null, ?Branch $branch = null): array
    {
        $location = $data['geoLocation'] ?? [];
        $companyName = $branch?->company?->name_en ?? $vehicle?->company?->name_en ?? 'COM';
        $branchName = $branch?->name ?? $vehicle?->branch?->name ?? 'BRA';

        return [
            'company_id' => $branch?->company_id ?? $data['companyId'] ?? $vehicle?->company_id,
            'branch_id' => $data['branchId'] ?? $vehicle?->branch_id,
            'app_code' => $vehicle?->app_code ?: $this->generateAppCode($companyName, $branchName),
            'status' => $data['status'] ?? $vehicle?->status ?? 'active',
            'work_status' => $data['workStatus'] ?? $vehicle?->work_status ?? 'offline',
            'last_active_date' => now(),
            'latitude' => $location['latitude'] ?? $vehicle?->latitude,
            'longitude' => $location['longitude'] ?? $vehicle?->longitude,
            'icon_map' => $data['iconMap'] ?? $vehicle?->icon_map,
            'label_number' => $data['labelNumber'] ?? $vehicle?->label_number,
            'model_name' => $data['modelName'] ?? $vehicle?->model_name,
        ];
    }

    private function syncRelations(Vehicle $vehicle, array $data): void
    {
        if (array_key_exists('services', $data)) {
            $vehicle->services()->sync($data['services'] ?? []);
        }

        if (array_key_exists('drivers', $data)) {
            $vehicle->drivers()->sync($data['drivers'] ?? []);
            Driver::query()->where('vehicle_id', $vehicle->id)->whereNotIn('id', $data['drivers'] ?? [])->update(['vehicle_id' => null]);
            Driver::query()->whereIn('id', $data['drivers'] ?? [])->update(['vehicle_id' => $vehicle->id]);
        }
    }

    private function generateAppCode(string $companyName, string $branchName): string
    {
        $prefix = 'VEH'.Str::upper(substr(preg_replace('/\s+/', '', $companyName), 0, 3)).'-'.Str::upper(substr(preg_replace('/\s+/', '', $branchName), 0, 3)).'-';

        return $prefix.Str::upper(Str::random(5));
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
