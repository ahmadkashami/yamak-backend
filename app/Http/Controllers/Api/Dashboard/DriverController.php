<?php

namespace App\Http\Controllers\Api\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Driver;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DriverController extends Controller
{
    public function index(Request $request): \Illuminate\Http\JsonResponse
    {
        $drivers = Driver::query()
            ->with(['branch', 'company'])
            ->when($request->query('companyId'), fn ($query, $companyId) => $query->where('company_id', $companyId))
            ->when($request->query('branchId'), fn ($query, $branchId) => $query->where('branch_id', $branchId))
            ->when($request->query('filterType') === 'freeDrivers', fn ($query) => $query->whereNull('vehicle_id'))
            ->when($request->query('query'), function ($query, $search) {
                $query->where(function ($query) use ($search) {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('phone_num', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->latest('id')
            ->paginate((int) $request->query('limit', 10));

        return response()->success([
            'list' => $drivers->getCollection()->map(fn (Driver $driver) => $this->driverResource($driver))->values(),
            'metaData' => $this->paginationMeta($drivers),
        ]);
    }

    public function store(Request $request): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate($this->rules());
        $branch = Branch::query()->findOrFail($data['branchId']);
        $driver = Driver::query()->create($this->payload($data, null, $branch));

        return response()->success($this->driverResource($driver->fresh(['branch', 'company'])), 'Driver created successfully');
    }

    public function listIds(): \Illuminate\Http\JsonResponse
    {
        $drivers = Driver::query()
            ->latest('id')
            ->get(['id', 'name', 'phone_num']);

        return response()->success($drivers->map(fn (Driver $driver) => [
            'id' => $driver->id,
            '_id' => (string) $driver->id,
            'name' => $driver->name,
            'phoneNum' => $driver->phone_num,
        ])->values());
    }

    public function show(Driver $driver): \Illuminate\Http\JsonResponse
    {
        return response()->success($this->driverResource($driver->load(['branch', 'company', 'vehicle'])));
    }

    public function update(Request $request, Driver $driver): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate($this->rules(false));
        $branch = isset($data['branchId']) ? Branch::query()->findOrFail($data['branchId']) : null;
        $driver->update($this->payload($data, $driver, $branch));

        return response()->success($this->driverResource($driver->fresh(['branch', 'company', 'vehicle'])), 'Driver updated successfully');
    }

    public function destroy(Driver $driver): \Illuminate\Http\JsonResponse
    {
        $driver->delete();

        return response()->success(null, 'Driver deleted successfully');
    }

    public function driverResource(Driver $driver): array
    {
        return [
            'id' => $driver->id,
            '_id' => (string) $driver->id,
            'status' => $driver->status,
            'name' => $driver->name,
            'email' => $driver->email,
            'photo' => $driver->photo,
            'phoneNum' => $driver->phone_num,
            'licenseExpiredAt' => optional($driver->license_expired_at)->toDateString(),
            'createdAt' => optional($driver->created_at)->toJSON(),
            'branchId' => (string) $driver->branch_id,
            'branch' => $driver->branch ? app(BranchController::class)->branchResource($driver->branch) : null,
            'companyId' => (string) $driver->company_id,
            'company' => $driver->company ? app(CompanyController::class)->companyResource($driver->company) : null,
            'vehicleId' => $driver->vehicle_id ? (string) $driver->vehicle_id : null,
        ];
    }

    private function rules(bool $creating = true): array
    {
        return [
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'max:50'],
            'photo' => ['nullable', 'string'],
            'licenseExpiredAt' => ['nullable', 'date'],
            'email' => [$creating ? 'required' : 'sometimes', 'email', 'max:255', Rule::unique('drivers', 'email')->ignore(request()->route('driver'))],
            'phoneNum' => ['nullable', 'string', 'max:255'],
            'password' => [$creating ? 'required' : 'sometimes', 'string', 'min:6'],
            'branchId' => [$creating ? 'required' : 'sometimes', 'integer', 'exists:branches,id'],
            'companyId' => ['nullable', 'integer', 'exists:companies,id'],
        ];
    }

    private function payload(array $data, ?Driver $driver = null, ?Branch $branch = null): array
    {
        $payload = [
            'company_id' => $branch?->company_id ?? $data['companyId'] ?? $driver?->company_id,
            'branch_id' => $data['branchId'] ?? $driver?->branch_id,
            'name' => $data['name'] ?? $driver?->name,
            'photo' => $data['photo'] ?? $driver?->photo,
            'phone_num' => $data['phoneNum'] ?? $driver?->phone_num,
            'email' => $data['email'] ?? $driver?->email,
            'driver_license' => $data['driverLicense'] ?? $driver?->driver_license,
            'license_expired_at' => $data['licenseExpiredAt'] ?? $driver?->license_expired_at,
            'status' => $data['status'] ?? $driver?->status ?? 'active',
        ];

        if (array_key_exists('password', $data)) {
            $payload['password'] = $data['password'];
            $payload['last_login'] = now();
        }

        return $payload;
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
