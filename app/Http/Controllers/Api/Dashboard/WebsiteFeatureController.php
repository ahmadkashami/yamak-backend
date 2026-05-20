<?php

namespace App\Http\Controllers\Api\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\WebsiteFeature;
use Illuminate\Http\Request;

class WebsiteFeatureController extends Controller
{
    public function index(Request $request): \Illuminate\Http\JsonResponse
    {
        $features = WebsiteFeature::query()
            ->when($request->query('companyId'), fn ($query, $companyId) => $query->where('company_id', $companyId))
            ->when($request->query('websiteId'), fn ($query, $websiteId) => $query->where('website_id', $websiteId))
            ->latest('id')
            ->paginate((int) $request->query('limit', 10));

        return response()->success([
            'list' => $features->getCollection()->map(fn (WebsiteFeature $feature) => $this->featureResource($feature))->values(),
            'metaData' => $this->paginationMeta($features),
        ]);
    }

    public function store(Request $request): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate($this->rules());
        $feature = WebsiteFeature::query()->create($this->payload($data));

        return response()->success($this->featureResource($feature), 'Feature created successfully');
    }

    public function update(Request $request, WebsiteFeature $websiteFeature): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate($this->rules(false));
        $websiteFeature->update($this->payload($data, $websiteFeature));

        return response()->success($this->featureResource($websiteFeature->fresh()), 'Feature updated successfully');
    }

    public function destroy(WebsiteFeature $websiteFeature): \Illuminate\Http\JsonResponse
    {
        $websiteFeature->delete();

        return response()->success(null, 'Feature deleted successfully');
    }

    public function featureResource(WebsiteFeature $feature): array
    {
        return [
            'id' => $feature->id,
            '_id' => (string) $feature->id,
            'companyId' => (string) $feature->company_id,
            'websiteId' => (string) $feature->website_id,
            'icon' => $feature->icon,
            'title' => ['en' => $feature->title_en, 'ar' => $feature->title_ar],
            'description' => ['en' => $feature->description_en, 'ar' => $feature->description_ar],
            'status' => $feature->status,
            'createdAt' => optional($feature->created_at)->toJSON(),
        ];
    }

    private function rules(bool $creating = true): array
    {
        return [
            'companyId' => [$creating ? 'required' : 'sometimes', 'integer', 'exists:companies,id'],
            'websiteId' => [$creating ? 'required' : 'sometimes', 'integer', 'exists:websites,id'],
            'icon' => [$creating ? 'required' : 'sometimes', 'string'],
            'title.en' => ['nullable', 'string'],
            'title.ar' => ['nullable', 'string'],
            'description.en' => ['nullable', 'string'],
            'description.ar' => ['nullable', 'string'],
            'status' => ['nullable', 'string', 'max:50'],
        ];
    }

    private function payload(array $data, ?WebsiteFeature $feature = null): array
    {
        $title = $data['title'] ?? [];
        $description = $data['description'] ?? [];

        return [
            'company_id' => $data['companyId'] ?? $feature?->company_id,
            'website_id' => $data['websiteId'] ?? $feature?->website_id,
            'icon' => $data['icon'] ?? $feature?->icon,
            'title_en' => $title['en'] ?? $feature?->title_en,
            'title_ar' => $title['ar'] ?? $feature?->title_ar,
            'description_en' => $description['en'] ?? $feature?->description_en,
            'description_ar' => $description['ar'] ?? $feature?->description_ar,
            'status' => $data['status'] ?? $feature?->status ?? 'active',
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
