<?php

namespace App\Http\Controllers\Api\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\WebsiteSlider;
use Illuminate\Http\Request;

class WebsiteSliderController extends Controller
{
    public function index(Request $request): \Illuminate\Http\JsonResponse
    {
        $sliders = WebsiteSlider::query()
            ->when($request->query('companyId'), fn ($query, $companyId) => $query->where('company_id', $companyId))
            ->when($request->query('websiteId'), fn ($query, $websiteId) => $query->where('website_id', $websiteId))
            ->latest('id')
            ->paginate((int) $request->query('limit', 10));

        return response()->success([
            'list' => $sliders->getCollection()->map(fn (WebsiteSlider $slider) => $this->sliderResource($slider))->values(),
            'metaData' => $this->paginationMeta($sliders),
        ]);
    }

    public function store(Request $request): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate($this->rules());
        $slider = WebsiteSlider::query()->create($this->payload($data));

        return response()->success($this->sliderResource($slider), 'Slider created successfully');
    }

    public function update(Request $request, WebsiteSlider $websiteSlider): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate($this->rules(false));
        $websiteSlider->update($this->payload($data, $websiteSlider));

        return response()->success($this->sliderResource($websiteSlider->fresh()), 'Slider updated successfully');
    }

    public function destroy(WebsiteSlider $websiteSlider): \Illuminate\Http\JsonResponse
    {
        $websiteSlider->delete();

        return response()->success(null, 'Slider deleted successfully');
    }

    public function sliderResource(WebsiteSlider $slider): array
    {
        return [
            'id' => $slider->id,
            '_id' => (string) $slider->id,
            'companyId' => (string) $slider->company_id,
            'websiteId' => (string) $slider->website_id,
            'imageUrl' => $slider->image_url,
            'title' => ['en' => $slider->title_en, 'ar' => $slider->title_ar],
            'subTitle' => ['en' => $slider->sub_title_en, 'ar' => $slider->sub_title_ar],
            'status' => $slider->status,
            'createdAt' => optional($slider->created_at)->toJSON(),
        ];
    }

    private function rules(bool $creating = true): array
    {
        return [
            'companyId' => [$creating ? 'required' : 'sometimes', 'integer', 'exists:companies,id'],
            'websiteId' => [$creating ? 'required' : 'sometimes', 'integer', 'exists:websites,id'],
            'imageUrl' => [$creating ? 'required' : 'sometimes', 'string'],
            'title.en' => ['nullable', 'string'],
            'title.ar' => ['nullable', 'string'],
            'subTitle.en' => ['nullable', 'string'],
            'subTitle.ar' => ['nullable', 'string'],
            'status' => ['nullable', 'string', 'max:50'],
        ];
    }

    private function payload(array $data, ?WebsiteSlider $slider = null): array
    {
        $title = $data['title'] ?? [];
        $subTitle = $data['subTitle'] ?? [];

        return [
            'company_id' => $data['companyId'] ?? $slider?->company_id,
            'website_id' => $data['websiteId'] ?? $slider?->website_id,
            'image_url' => $data['imageUrl'] ?? $slider?->image_url,
            'title_en' => $title['en'] ?? $slider?->title_en,
            'title_ar' => $title['ar'] ?? $slider?->title_ar,
            'sub_title_en' => $subTitle['en'] ?? $slider?->sub_title_en,
            'sub_title_ar' => $subTitle['ar'] ?? $slider?->sub_title_ar,
            'status' => $data['status'] ?? $slider?->status ?? 'active',
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
