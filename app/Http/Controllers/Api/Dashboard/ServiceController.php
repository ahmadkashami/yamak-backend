<?php

namespace App\Http\Controllers\Api\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Service;
use Illuminate\Http\Request;

class ServiceController extends Controller
{
    public function index(Request $request): \Illuminate\Http\JsonResponse
    {
        $services = Service::query()
            ->when($request->query('query'), function ($query, $search) {
                $query->where(function ($query) use ($search) {
                    $query->where('name_en', 'like', "%{$search}%")
                        ->orWhere('name_ar', 'like', "%{$search}%");
                });
            })
            ->latest('id')
            ->paginate((int) $request->query('limit', 10));

        return response()->success([
            'list' => $services->getCollection()->map(fn (Service $service) => $this->serviceResource($service))->values(),
            'metaData' => $this->paginationMeta($services),
        ]);
    }

    public function store(Request $request): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate($this->rules());

        $exists = Service::query()
            ->where('name_en', 'like', $data['name']['en'])
            ->orWhere('name_ar', 'like', $data['name']['ar'])
            ->exists();

        if ($exists) {
            return response()->error('service with name already Exist', 400);
        }

        $service = Service::query()->create($this->payload($data));

        return response()->success($this->serviceResource($service), 'Service created successfully');
    }

    public function listIds(): \Illuminate\Http\JsonResponse
    {
        return response()->success(
            Service::query()
                ->latest('id')
                ->get()
                ->map(fn (Service $service) => [
                    'id' => $service->id,
                    '_id' => (string) $service->id,
                    'name' => ['en' => $service->name_en, 'ar' => $service->name_ar],
                ])
                ->values()
        );
    }

    public function show(Service $service): \Illuminate\Http\JsonResponse
    {
        return response()->success($this->serviceResource($service));
    }

    public function update(Request $request, Service $service): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate($this->rules(false));
        $service->update($this->payload($data, $service));

        return response()->success($this->serviceResource($service->fresh()), 'Service updated successfully');
    }

    public function destroy(Service $service): \Illuminate\Http\JsonResponse
    {
        $service->delete();

        return response()->success(null, 'Service deleted successfully');
    }

    private function rules(bool $creating = true): array
    {
        return [
            'name.en' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'name.ar' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'logo' => ['nullable', 'string'],
            'status' => ['nullable', 'string', 'max:50'],
        ];
    }

    private function payload(array $data, ?Service $service = null): array
    {
        $name = $data['name'] ?? [];
        $nameEn = $name['en'] ?? $service?->name_en;
        $nameAr = $name['ar'] ?? $service?->name_ar;

        return [
            'name_en' => $nameEn,
            'name_ar' => $nameAr,
            'logo' => $data['logo'] ?? $service?->logo,
            'status' => $data['status'] ?? $service?->status ?? 'active',
        ];
    }

    public function serviceResource(Service $service): array
    {
        return [
            'id' => $service->id,
            '_id' => (string) $service->id,
            'name' => ['en' => $service->name_en, 'ar' => $service->name_ar],
            'status' => $service->status,
            'createdAt' => optional($service->created_at)->toJSON(),
            'logo' => $service->logo,
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
}
