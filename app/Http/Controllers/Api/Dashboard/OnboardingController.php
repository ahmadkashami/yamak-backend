<?php

namespace App\Http\Controllers\Api\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Onboarding;
use App\Models\OnboardingSlide;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OnboardingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $onboardings = Onboarding::query()
            ->withCount('slides')
            ->latest('id')
            ->paginate((int) $request->query('limit', 10));

        $list = $onboardings->getCollection()->map(function (Onboarding $onboarding) {
            return [
                'id' => $onboarding->id,
                'version' => $onboarding->version,
                'status' => $onboarding->status,
                'isActive' => (bool) $onboarding->is_active,
                'isSkippable' => (bool) $onboarding->is_skippable,
                'slideCount' => $onboarding->slides_count,
                'createdAt' => optional($onboarding->created_at)->toJSON(),
                'updatedAt' => optional($onboarding->updated_at)->toJSON(),
            ];
        });

        return response()->success([
            'list' => $list->values(),
            'metaData' => $this->paginationMeta($onboardings),
        ]);
    }

    public function show(Onboarding $onboarding): JsonResponse
    {
        $onboarding->load('slides.image');

        $slides = $onboarding->slides->map(function (OnboardingSlide $slide) {
            return [
                'id' => $slide->id,
                'order' => $slide->sort_order,
                'titleEn' => $slide->title_en,
                'titleAr' => $slide->title_ar,
                'descriptionEn' => $slide->description_en,
                'descriptionAr' => $slide->description_ar,
                'imageUrl' => $slide->image ? ($slide->image->path ?? null) : null,
            ];
        });

        return response()->success([
            'id' => $onboarding->id,
            'version' => $onboarding->version,
            'status' => $onboarding->status,
            'isActive' => (bool) $onboarding->is_active,
            'isSkippable' => (bool) $onboarding->is_skippable,
            'slides' => $slides->values(),
            'createdAt' => optional($onboarding->created_at)->toJSON(),
            'updatedAt' => optional($onboarding->updated_at)->toJSON(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->slideRules());

        $this->validateUniqueSortOrders($data['slides']);

        $onboarding = null;
        DB::transaction(function () use ($data, &$onboarding) {
            $nextVersion = $this->getNextVersion();

            $onboarding = Onboarding::query()->create([
                'version' => $nextVersion,
                'status' => 'draft',
                'is_active' => false,
                'is_skippable' => $data['is_skippable'] ?? true,
            ]);

            foreach ($data['slides'] as $slideData) {
                $slide = $onboarding->slides()->create([
                    'sort_order' => $slideData['sort_order'],
                    'title_en' => $slideData['title_en'],
                    'title_ar' => $slideData['title_ar'] ?? null,
                    'description_en' => $slideData['description_en'] ?? null,
                    'description_ar' => $slideData['description_ar'] ?? null,
                ]);

                if (isset($slideData['image']) && ! empty($slideData['image'])) {
                    $this->attachImage($slide, $slideData['image']);
                }
            }
        });

        return response()->success($this->show($onboarding)->getData(true)['data'] ?? [], 'Onboarding created successfully');
    }

    public function update(Request $request, Onboarding $onboarding): JsonResponse
    {
        if ($onboarding->status !== 'draft') {
            return response()->json([
                'success' => false,
                'message' => 'Published onboarding cannot be edited',
                'data' => null,
                'errors' => null,
            ], 422);
        }

        $data = $request->validate($this->slideRules(false));

        if (isset($data['slides'])) {
            $this->validateUniqueSortOrders($data['slides']);
        }

        DB::transaction(function () use ($data, $onboarding) {
            if (array_key_exists('is_skippable', $data)) {
                $onboarding->update(['is_skippable' => (bool) $data['is_skippable']]);
            }

            if (isset($data['slides'])) {
                $existingSlideIds = $onboarding->slides()->pluck('id')->toArray();
                $incomingIds = collect($data['slides'])->pluck('id')->filter()->toArray();

                $toDelete = array_diff($existingSlideIds, $incomingIds);
                if (! empty($toDelete)) {
                    OnboardingSlide::query()->whereIn('id', $toDelete)->delete();
                }

                $this->stageSlidesForReorder($onboarding, $data['slides']);

                foreach ($data['slides'] as $slideData) {
                    if (! empty($slideData['id'])) {
                        $slide = OnboardingSlide::query()->find($slideData['id']);
                        if ($slide && $slide->onboarding_id === $onboarding->id) {
                            $slide->update([
                                'sort_order' => $slideData['sort_order'],
                                'title_en' => $slideData['title_en'],
                                'title_ar' => $slideData['title_ar'] ?? null,
                                'description_en' => $slideData['description_en'] ?? null,
                                'description_ar' => $slideData['description_ar'] ?? null,
                            ]);
                            if (array_key_exists('image', $slideData)) {
                                $this->syncImage($slide, $slideData['image']);
                            }
                        }
                    } else {
                        $slide = $onboarding->slides()->create([
                            'sort_order' => $slideData['sort_order'],
                            'title_en' => $slideData['title_en'],
                            'title_ar' => $slideData['title_ar'] ?? null,
                            'description_en' => $slideData['description_en'] ?? null,
                            'description_ar' => $slideData['description_ar'] ?? null,
                        ]);
                        if (isset($slideData['image']) && ! empty($slideData['image'])) {
                            $this->attachImage($slide, $slideData['image']);
                        }
                    }
                }
            }
        });

        return response()->success($this->show($onboarding->fresh())->getData(true)['data'] ?? [], 'Onboarding updated successfully');
    }

    public function publish(Onboarding $onboarding): JsonResponse
    {
        if ($onboarding->status !== 'draft') {
            return response()->json([
                'success' => false,
                'message' => 'Only draft onboarding can be published',
                'data' => null,
                'errors' => null,
            ], 422);
        }

        $slideCount = $onboarding->slides()->count();
        if ($slideCount < 1) {
            return response()->json([
                'success' => false,
                'message' => 'At least one slide is required to publish',
                'data' => null,
                'errors' => null,
            ], 422);
        }

        $onboarding->update([
            'status' => 'published',
            'is_active' => false,
        ]);

        return response()->success($this->show($onboarding->fresh())->getData(true)['data'] ?? [], 'Onboarding published successfully');
    }

    public function activate(Onboarding $onboarding): JsonResponse
    {
        if ($onboarding->status !== 'published') {
            return response()->json([
                'success' => false,
                'message' => 'Only published onboarding can be activated',
                'data' => null,
                'errors' => null,
            ], 422);
        }

        DB::transaction(function () use ($onboarding) {
            Onboarding::query()->where('is_active', true)->update(['is_active' => false]);
            $onboarding->update(['is_active' => true]);
        });

        return response()->success($this->show($onboarding->fresh())->getData(true)['data'] ?? [], 'Onboarding activated successfully');
    }

    public function disable(Onboarding $onboarding): JsonResponse
    {
        $onboarding->update(['is_active' => false]);

        return response()->success($this->show($onboarding->fresh())->getData(true)['data'] ?? [], 'Onboarding disabled successfully');
    }

    public function destroy(Onboarding $onboarding): JsonResponse
    {
        if ($onboarding->status !== 'draft') {
            return response()->json([
                'success' => false,
                'message' => 'Only draft onboarding can be deleted',
                'data' => null,
                'errors' => null,
            ], 422);
        }

        $onboarding->delete();

        return response()->success(null, 'Onboarding deleted successfully');
    }

    private function getNextVersion(): int
    {
        // Called inside the create transaction. lockForUpdate serialises concurrent
        // draft creation; the unique index on onboardings.version is the final guard.
        $max = Onboarding::query()->withTrashed()->lockForUpdate()->max('version');

        return $max ? ((int) $max + 1) : 1;
    }

    private function validateUniqueSortOrders(array $slides): void
    {
        $orders = collect($slides)->pluck('sort_order')->toArray();
        if (count($orders) !== count(array_unique($orders))) {
            abort(422, 'Duplicate sort_order values found');
        }
    }

    /**
     * UNIQUE(onboarding_id, sort_order) rejects an in-place reorder: writing a slide's
     * new sort_order while another slide still occupies that value raises a 23000
     * violation (HTTP 500) on both MySQL and SQLite.
     *
     * Phase 1 of a two-phase write: move every surviving slide to a temporary order
     * that is strictly greater than any requested order, so the final writes performed
     * by the caller can never collide with an order that is still occupied. The loop
     * is phase 2.
     *
     * Temporary values stay positive because sort_order is an unsigned column, and they
     * are derived from the payload rather than a magic constant. All surviving slides
     * are staged, not only the ones that appear to move, because an unchanged slide can
     * still block a slide that moves onto its order.
     */
    private function stageSlidesForReorder(Onboarding $onboarding, array $slides): void
    {
        if (empty($slides)) {
            return;
        }

        $survivingIds = $onboarding->slides()->pluck('id')->all();

        if (empty($survivingIds)) {
            return;
        }

        $highestRequestedOrder = max(array_map(
            fn (array $slide): int => (int) $slide['sort_order'],
            $slides
        ));

        // Query builder update: it must not touch updated_at, which phase 2 sets.
        foreach (array_values($survivingIds) as $offset => $slideId) {
            OnboardingSlide::query()
                ->whereKey($slideId)
                ->update(['sort_order' => $highestRequestedOrder + 1 + $offset]);
        }
    }

    private function slideRules(bool $creating = true): array
    {
        return [
            'is_skippable' => ['nullable', 'boolean'],
            'slides' => [$creating ? 'required' : 'nullable', 'array', 'min:1'],
            'slides.*.id' => ['nullable', 'integer'],
            'slides.*.sort_order' => ['required', 'integer', 'min:1'],
            'slides.*.title_en' => ['required', 'string', 'max:255'],
            'slides.*.title_ar' => ['nullable', 'string', 'max:255'],
            'slides.*.description_en' => ['nullable', 'string'],
            'slides.*.description_ar' => ['nullable', 'string'],
            // The dashboard submits the Cloudinary secure_url returned by files-upload.
            'slides.*.image' => ['nullable', 'string', 'url', 'max:2048'],
        ];
    }

    private function attachImage(OnboardingSlide $slide, string $imageUrl): void
    {
        $slide->image()->create([
            'path' => $imageUrl,
            'description' => null,
        ]);
    }

    private function syncImage(OnboardingSlide $slide, ?string $imageUrl): void
    {
        if (empty($imageUrl)) {
            $slide->image()->delete();

            return;
        }

        if ($slide->image) {
            $slide->image()->update(['path' => $imageUrl]);
        } else {
            $this->attachImage($slide, $imageUrl);
        }
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
