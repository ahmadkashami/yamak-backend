<?php

namespace App\Http\Controllers\Api\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Company;
use Illuminate\Http\Request;

class CompanyController extends Controller
{
    public function index(Request $request): \Illuminate\Http\JsonResponse
    {
        $companies = Company::query()
            ->when($request->query('query'), function ($query, $search) {
                $query->where(function ($query) use ($search) {
                    $query->where('name_en', 'like', "%{$search}%")
                        ->orWhere('name_ar', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%");
                });
            })
            ->latest('id')
            ->paginate((int) $request->query('limit', 10));

        return response()->success([
            'list' => $companies->getCollection()->map(fn (Company $company) => $this->companyResource($company))->values(),
            'metaData' => $this->paginationMeta($companies),
        ]);
    }

    public function store(Request $request): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate($this->rules());
        $company = Company::query()->create($this->payload($data));

        return response()->success($this->companyResource($company), 'Company created successfully');
    }

    public function show(Company $company): \Illuminate\Http\JsonResponse
    {
        return response()->success($this->companyResource($company));
    }

    public function update(Request $request, Company $company): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate($this->rules(false));
        $company->update($this->payload($data, $company));

        return response()->success($this->companyResource($company->fresh()), 'Company updated successfully');
    }

    public function branches(Company $company, Request $request): \Illuminate\Http\JsonResponse
    {
        $branches = $company->branches()
            ->when($request->query('query'), fn ($query, $search) => $query->where('name', 'like', "%{$search}%"))
            ->latest('id')
            ->paginate((int) $request->query('limit', 10));

        return response()->success([
            'list' => $branches->getCollection()->map(fn ($branch) => app(BranchController::class)->branchResource($branch))->values(),
            'metaData' => $this->paginationMeta($branches),
        ]);
    }

    public function drivers(Company $company, Request $request): \Illuminate\Http\JsonResponse
    {
        $request->merge(['companyId' => $company->id]);

        return app(DriverController::class)->index($request);
    }

    public function freeDrivers(Company $company, Request $request): \Illuminate\Http\JsonResponse
    {
        $request->merge(['companyId' => $company->id, 'filterType' => 'freeDrivers']);

        return app(DriverController::class)->index($request);
    }

    public function vehicles(Company $company, Request $request): \Illuminate\Http\JsonResponse
    {
        $request->merge(['companyId' => $company->id]);

        return app(VehicleController::class)->index($request);
    }

    private function rules(bool $creating = true): array
    {
        return [
            'name.en' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'name.ar' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'logo' => ['nullable', 'string'],
            'status' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string'],
            'description' => ['nullable', 'string'],
        ];
    }

    private function payload(array $data, ?Company $company = null): array
    {
        $name = $data['name'] ?? [];
        $nameEn = $name['en'] ?? $company?->name_en;
        $nameAr = $name['ar'] ?? $company?->name_ar;

        return [
            'name_en' => $nameEn,
            'name_ar' => $nameAr,
            'phone' => $data['phone'] ?? $company?->phone,
            'email' => $data['email'] ?? $company?->email,
            'logo' => $data['logo'] ?? $company?->logo,
            'status' => $data['status'] ?? $company?->status ?? 'active',
            'address' => $data['address'] ?? $company?->address,
            'description' => $data['description'] ?? $company?->description,
        ];
    }

    public function companyResource(Company $company): array
    {
        return [
            'id' => $company->id,
            '_id' => (string) $company->id,
            'name' => ['en' => $company->name_en, 'ar' => $company->name_ar],
            'phone' => $company->phone,
            'email' => $company->email,
            'logo' => $company->logo,
            'status' => $company->status,
            'address' => $company->address,
            'description' => $company->description,
            'createdAt' => optional($company->created_at)->toJSON(),
        ];
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

    private function emptyMeta(): array
    {
        return ['total' => 0, 'page' => 1, 'limit' => 10, 'lastPage' => 1];
    }
}
