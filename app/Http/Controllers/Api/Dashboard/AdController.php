<?php

namespace App\Http\Controllers\Api\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Ad;
use Illuminate\Http\Request;

class AdController extends Controller
{
    public function index(Request $request): \Illuminate\Http\JsonResponse
    {
        $ads = Ad::query()
            ->when($request->query('query'), function ($query, $search) {
                $query->where(function ($query) use ($search) {
                    $query->where('title', 'like', "%{$search}%")
                        ->orWhere('link', 'like', "%{$search}%");
                });
            })
            ->latest('id')
            ->paginate((int) $request->query('limit', 10));

        return response()->success([
            'list' => $ads->getCollection()->map(fn (Ad $ad) => $this->adResource($ad))->values(),
            'metaData' => $this->paginationMeta($ads),
        ]);
    }

    public function store(Request $request): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate($this->rules());
        $ad = Ad::query()->create($this->payload($data));

        return response()->success($this->adResource($ad), 'Add created successfully');
    }

    public function show(Ad $ad): \Illuminate\Http\JsonResponse
    {
        return response()->success($this->adResource($ad));
    }

    public function update(Request $request, Ad $ad): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate($this->rules(false));
        $ad->update($this->payload($data, $ad));

        return response()->success($this->adResource($ad->fresh()), 'Add updated successfully');
    }

    public function destroy(Ad $ad): \Illuminate\Http\JsonResponse
    {
        $ad->delete();

        return response()->success(null, 'Add deleted successfully');
    }

    private function rules(bool $creating = true): array
    {
        return [
            'title' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'link' => ['nullable', 'string', 'max:2048'],
            'photo' => [$creating ? 'required' : 'sometimes', 'string'],
            'status' => ['nullable', 'string', 'max:50'],
            'startedAt' => ['nullable', 'date'],
            'expiredAt' => ['nullable', 'date'],
        ];
    }

    private function payload(array $data, ?Ad $ad = null): array
    {
        return [
            'title' => $data['title'] ?? $ad?->title,
            'link' => $data['link'] ?? $ad?->link,
            'photo' => $data['photo'] ?? $ad?->photo,
            'started_at' => $data['startedAt'] ?? $ad?->started_at ?? now(),
            'expired_at' => $data['expiredAt'] ?? $ad?->expired_at ?? now()->addMonth(),
            'status' => $data['status'] ?? $ad?->status ?? 'active',
        ];
    }

    public function adResource(Ad $ad): array
    {
        return [
            'id' => $ad->id,
            '_id' => (string) $ad->id,
            'title' => $ad->title,
            'link' => $ad->link,
            'photo' => $ad->photo,
            'startedAt' => optional($ad->started_at)->toJSON(),
            'expiredAt' => optional($ad->expired_at)->toJSON(),
            'status' => $ad->status,
            'createdAt' => optional($ad->created_at)->toJSON(),
            'updatedAt' => optional($ad->updated_at)->toJSON(),
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
