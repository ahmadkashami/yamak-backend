<?php

namespace App\Http\Controllers\Api\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\WebsiteCarousel;
use Illuminate\Http\Request;

class WebsiteCarouselController extends Controller
{
    public function index(Request $request): \Illuminate\Http\JsonResponse
    {
        $carousels = WebsiteCarousel::query()
            ->when($request->query('companyId'), fn ($query, $companyId) => $query->where('company_id', $companyId))
            ->when($request->query('websiteId'), fn ($query, $websiteId) => $query->where('website_id', $websiteId))
            ->latest('id')
            ->paginate((int) $request->query('limit', 10));

        return response()->success([
            'list' => $carousels->getCollection()->map(fn (WebsiteCarousel $carousel) => $this->carouselResource($carousel))->values(),
            'metaData' => $this->paginationMeta($carousels),
        ]);
    }

    public function store(Request $request): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'companyId' => ['required', 'integer', 'exists:companies,id'],
            'websiteId' => ['required', 'integer', 'exists:websites,id'],
            'imageUrl' => ['required', 'string'],
        ]);

        $carousel = WebsiteCarousel::query()->create([
            'company_id' => $data['companyId'],
            'website_id' => $data['websiteId'],
            'image_url' => $data['imageUrl'],
        ]);

        return response()->success($this->carouselResource($carousel), 'Carousel created successfully');
    }

    public function destroy(WebsiteCarousel $websiteCarousel): \Illuminate\Http\JsonResponse
    {
        $websiteCarousel->delete();

        return response()->success(null, 'Carousel deleted successfully');
    }

    public function carouselResource(WebsiteCarousel $carousel): array
    {
        return [
            'id' => $carousel->id,
            '_id' => (string) $carousel->id,
            'companyId' => (string) $carousel->company_id,
            'websiteId' => (string) $carousel->website_id,
            'imageUrl' => $carousel->image_url,
            'createdAt' => optional($carousel->created_at)->toJSON(),
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
