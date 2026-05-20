<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Api\Dashboard\AdController as DashboardAdController;
use App\Http\Controllers\Controller;
use App\Models\Ad;
use Illuminate\Http\Request;

class AdController extends Controller
{
    public function index(Request $request, DashboardAdController $dashboardAds): \Illuminate\Http\JsonResponse
    {
        $ads = Ad::query()
            ->where('started_at', '<=', now())
            ->where('expired_at', '>=', now())
            ->where('status', 'active')
            ->latest('id')
            ->paginate((int) $request->query('limit', 10));

        return response()->success([
            'list' => $ads->getCollection()->map(fn (Ad $ad) => $dashboardAds->adResource($ad))->values(),
            'metaData' => [
                'totalCount' => $ads->total(),
                'currentCount' => $ads->count(),
                'currentPage' => $ads->currentPage(),
                'itemsPerPage' => $ads->perPage(),
                'lastPage' => $ads->lastPage(),
            ],
        ]);
    }

    public function show(Ad $ad, DashboardAdController $dashboardAds): \Illuminate\Http\JsonResponse
    {
        return response()->success($dashboardAds->adResource($ad));
    }
}
