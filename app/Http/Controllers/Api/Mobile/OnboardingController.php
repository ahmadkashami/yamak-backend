<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Onboarding;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Str;

class OnboardingController extends Controller
{
    public function show(): JsonResponse
    {
        // The activation transaction guarantees a single active row. orderByDesc('version')
        // only makes the read deterministic if corrupted data ever holds more than one.
        $onboarding = Onboarding::query()
            ->with(['slides.image'])
            ->where('status', 'published')
            ->where('is_active', true)
            ->orderByDesc('version')
            ->first();

        if (! $onboarding) {
            return response()->success([
                'enabled' => false,
            ]);
        }

        // App\Http\Middleware\SetLocale owns ?lang / Accept-Language resolution for the api group.
        $isArabic = Str::startsWith(App::getLocale(), 'ar');

        $slides = $onboarding->slides->map(function ($slide) use ($isArabic) {
            $imageUrl = $slide->image ? ($slide->image->path ?? null) : null;

            $title = $slide->title_en;
            $description = $slide->description_en;

            if ($isArabic) {
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
