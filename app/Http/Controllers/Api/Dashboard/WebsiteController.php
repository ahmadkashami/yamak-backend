<?php

namespace App\Http\Controllers\Api\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Website;
use App\Models\WebsiteTheme;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class WebsiteController extends Controller
{
    public function index(Request $request): \Illuminate\Http\JsonResponse
    {
        $websites = Website::query()
            ->with('company')
            ->when($request->query('companyId'), fn ($query, $companyId) => $query->where('company_id', $companyId))
            ->when($request->query('query'), fn ($query, $search) => $query->where('domain_url', 'like', "%{$search}%"))
            ->latest('id')
            ->paginate((int) $request->query('limit', 10));

        return response()->success([
            'list' => $websites->getCollection()->map(fn (Website $website) => $this->websiteResource($website))->values(),
            'metaData' => $this->paginationMeta($websites),
        ]);
    }

    public function store(Request $request): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate($this->rules());
        $website = Website::query()->create($this->payload($data));
        $website->services()->sync($this->companyServiceIds((int) $data['companyId']));
        $this->createDefaultTheme($website);

        return response()->success($this->websiteResource($website->fresh(['company', 'services', 'theme'])), 'Website created successfully');
    }

    public function update(Request $request, Website $website): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate($this->rules(false, $website));
        $website->update($this->payload($data, $website));

        return response()->success($this->websiteResource($website->fresh(['company', 'services', 'theme'])), 'Website updated successfully');
    }

    public function byCompany(Company $company): \Illuminate\Http\JsonResponse
    {
        $website = Website::query()
            ->with(['company', 'features' => fn ($query) => $query->where('status', 'active'), 'sliders' => fn ($query) => $query->where('status', 'active')])
            ->where('company_id', $company->id)
            ->firstOrFail();

        return response()->success($this->websiteResource($website, true));
    }

    public function byDomain(Request $request): \Illuminate\Http\JsonResponse
    {
        $website = Website::query()
            ->with([
                'company',
                'services',
                'theme' => fn ($query) => $query->where('status', 'active'),
                'features' => fn ($query) => $query->where('status', 'active'),
                'sliders' => fn ($query) => $query->where('status', 'active'),
                'carousels',
            ])
            ->where('domain_url', $request->query('domainUrl'))
            ->firstOrFail();

        return response()->success($this->websiteResource($website, true));
    }

    public function websiteResource(Website $website, bool $includeContent = false): array
    {
        $resource = [
            'id' => $website->id,
            '_id' => (string) $website->id,
            'logo' => $website->logo,
            'domainUrl' => $website->domain_url,
            'aboutUs' => ['en' => $website->about_us_en, 'ar' => $website->about_us_ar],
            'contactUs' => [
                'address' => ['en' => $website->contact_address_en, 'ar' => $website->contact_address_ar],
                'phone' => $website->contact_phone,
                'email' => $website->contact_email,
            ],
            'companyId' => (string) $website->company_id,
            'company' => $website->company ? app(CompanyController::class)->companyResource($website->company) : null,
            'status' => $website->status,
            'createdAt' => optional($website->created_at)->toJSON(),
        ];

        if ($includeContent || $website->relationLoaded('services')) {
            $resource['services'] = $website->services->map(fn ($service) => app(ServiceController::class)->serviceResource($service))->values();
        }

        if ($includeContent) {
            $resource['features'] = $website->features->map(fn ($feature) => app(WebsiteFeatureController::class)->featureResource($feature))->values();
            $resource['sliders'] = $website->sliders->map(fn ($slider) => app(WebsiteSliderController::class)->sliderResource($slider))->values();
            $resource['carousel'] = $website->carousels->map(fn ($carousel) => app(WebsiteCarouselController::class)->carouselResource($carousel))->values();
            $resource['theme'] = $website->theme ? app(WebsiteThemeController::class)->themeResource($website->theme) : null;
        }

        return $resource;
    }

    private function rules(bool $creating = true, ?Website $website = null): array
    {
        return [
            'logo' => ['nullable', 'string'],
            'domainUrl' => [$creating ? 'required' : 'sometimes', 'string', 'max:255', Rule::unique('websites', 'domain_url')->ignore($website)],
            'aboutUs.en' => ['nullable', 'string'],
            'aboutUs.ar' => ['nullable', 'string'],
            'contactUs.address.en' => ['nullable', 'string'],
            'contactUs.address.ar' => ['nullable', 'string'],
            'contactUs.phone' => ['nullable', 'string', 'max:255'],
            'contactUs.email' => ['nullable', 'email', 'max:255'],
            'companyId' => [$creating ? 'required' : 'sometimes', 'integer', 'exists:companies,id'],
            'status' => ['nullable', 'string', 'max:50'],
        ];
    }

    private function payload(array $data, ?Website $website = null): array
    {
        $aboutUs = $data['aboutUs'] ?? [];
        $contactUs = $data['contactUs'] ?? [];
        $address = $contactUs['address'] ?? [];

        return [
            'logo' => $data['logo'] ?? $website?->logo,
            'domain_url' => $data['domainUrl'] ?? $website?->domain_url,
            'about_us_en' => $aboutUs['en'] ?? $website?->about_us_en,
            'about_us_ar' => $aboutUs['ar'] ?? $website?->about_us_ar,
            'contact_address_en' => $address['en'] ?? $website?->contact_address_en,
            'contact_address_ar' => $address['ar'] ?? $website?->contact_address_ar,
            'contact_phone' => $contactUs['phone'] ?? $website?->contact_phone,
            'contact_email' => $contactUs['email'] ?? $website?->contact_email,
            'company_id' => $data['companyId'] ?? $website?->company_id,
            'status' => $data['status'] ?? $website?->status ?? 'active',
        ];
    }

    private function companyServiceIds(int $companyId): array
    {
        return DB::table('branch_service')
            ->join('branches', 'branch_service.branch_id', '=', 'branches.id')
            ->where('branches.company_id', $companyId)
            ->distinct()
            ->pluck('branch_service.service_id')
            ->all();
    }

    private function createDefaultTheme(Website $website): void
    {
        WebsiteTheme::query()->create([
            'primary_color' => '#002F6C',
            'secondary_color' => '#F7B123',
            'secondary_background_color' => '#F7B123',
            'banner_image' => '',
            'company_id' => $website->company_id,
            'website_id' => $website->id,
            'status' => 'active',
        ]);
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
