<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Onboarding;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OnboardingController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $onboarding = Onboarding::query()
            ->with(['slides.image'])
            ->where('status', 'published')
            ->where('is_active', true)
            ->first();

        if (! $onboarding) {
            return response()->success([
                'enabled' => false,
            ]);
        }

        $locale = strtolower($request->get('lang') ?? $request->header('Accept-Language') ?? 'en');
        if (strlen($locale) > 2) {
            $locale = substr($locale, 0, 2);
        }

        $slides = $onboarding->slides->map(function ($slide) use ($locale) {
            $imageUrl = $slide->image ? ($slide->image->path ?? null) : null;

            $title = $slide->title_en;
            $description = $slide->description_en;

            if ($locale === 'ar') {
                if (! empty($slide->title_ar)) {
                    $title = $slide->title_ar;
                }
                if (! empty($slide->description_ar)) {
                    $description = $slide->description_ar;
                }
            }

            return [
                'id' => $slide->id,
                'order' => $slide->sort_order,
                'title' => $title,
                'description' => $description,
                'imageUrl' => $imageUrl,
            ];
        });

        return response()->success([
            'enabled' => true,
            'version' => $onboarding->version,
            'skippable' => (bool) $onboarding->is_skippable,
            'slides' => $slides->values()->all(),
        ]);
    }
}
