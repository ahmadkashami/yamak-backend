<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Api\Dashboard\AppSettingController as DashboardAppSettingController;
use App\Http\Controllers\Controller;
use App\Models\AppSetting;

class AppSettingController extends Controller
{
    public function index(DashboardAppSettingController $dashboardAppSettings): \Illuminate\Http\JsonResponse
    {
        $appSetting = AppSetting::query()->oldest('id')->first();

        if (! $appSetting) {
            return response()->error('setting not found', 400);
        }

        return response()->success($dashboardAppSettings->appSettingResource($appSetting));
    }
}
