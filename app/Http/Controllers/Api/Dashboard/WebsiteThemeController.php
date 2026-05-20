<?php

namespace App\Http\Controllers\Api\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Website;
use App\Models\WebsiteTheme;
use Illuminate\Http\Request;

class WebsiteThemeController extends Controller
{
    public function index(Request $request): \Illuminate\Http\JsonResponse
    {
        $themes = WebsiteTheme::query()
            ->when($request->query('companyId'), fn ($query, $companyId) => $query->where('company_id', $companyId))
            ->latest('id')
            ->paginate((int) $request->query('limit', 10));

        return response()->success([
            'list' => $themes->getCollection()->map(fn (WebsiteTheme $theme) => $this->themeResource($theme))->values(),
            'metaData' => $this->paginationMeta($themes),
        ]);
    }

    public function store(Request $request): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate($this->rules());
        WebsiteTheme::query()->where('website_id', $data['websiteId'])->doesntExist() ?: abort(400, 'theme already exist for same website');
        $theme = WebsiteTheme::query()->create($this->payload($data));

        return response()->success($this->themeResource($theme), 'Theme created successfully');
    }

    public function byWebsite(Website $website): \Illuminate\Http\JsonResponse
    {
        $theme = WebsiteTheme::query()->where('website_id', $website->id)->firstOrFail();

        return response()->success($this->themeResource($theme));
    }

    public function update(Request $request, WebsiteTheme $websiteTheme): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate($this->rules(false));
        $websiteTheme->update($this->payload($data, $websiteTheme));

        return response()->success($this->themeResource($websiteTheme->fresh()), 'Theme updated successfully');
    }

    public function destroy(WebsiteTheme $websiteTheme): \Illuminate\Http\JsonResponse
    {
        $websiteTheme->delete();

        return response()->success(null, 'Theme deleted successfully');
    }

    public function themeResource(WebsiteTheme $theme): array
    {
        return [
            'id' => $theme->id,
            '_id' => (string) $theme->id,
            'companyId' => (string) $theme->company_id,
            'websiteId' => (string) $theme->website_id,
            'primaryColor' => $theme->primary_color,
            'secondaryColor' => $theme->secondary_color,
            'secondaryBackgroundColor' => $theme->secondary_background_color,
            'bannerImage' => $theme->banner_image,
            'status' => $theme->status,
            'createdAt' => optional($theme->created_at)->toJSON(),
        ];
    }

    private function rules(bool $creating = true): array
    {
        return [
            'companyId' => [$creating ? 'required' : 'sometimes', 'integer', 'exists:companies,id'],
            'websiteId' => [$creating ? 'required' : 'sometimes', 'integer', 'exists:websites,id'],
            'primaryColor' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'secondaryColor' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'secondaryBackgroundColor' => ['nullable', 'string', 'max:255'],
            'bannerImage' => ['nullable', 'string'],
            'status' => ['nullable', 'string', 'max:50'],
        ];
    }

    private function payload(array $data, ?WebsiteTheme $theme = null): array
    {
        return [
            'company_id' => $data['companyId'] ?? $theme?->company_id,
            'website_id' => $data['websiteId'] ?? $theme?->website_id,
            'primary_color' => $data['primaryColor'] ?? $theme?->primary_color,
            'secondary_color' => $data['secondaryColor'] ?? $theme?->secondary_color,
            'secondary_background_color' => $data['secondaryBackgroundColor'] ?? $theme?->secondary_background_color,
            'banner_image' => $data['bannerImage'] ?? $theme?->banner_image,
            'status' => $data['status'] ?? $theme?->status ?? 'active',
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
