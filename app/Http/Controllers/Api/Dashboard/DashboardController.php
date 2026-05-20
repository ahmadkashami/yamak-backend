<?php

namespace App\Http\Controllers\Api\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\AppUser;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Driver;
use App\Models\Service;
use App\Models\Vehicle;
use App\Models\Website;

class DashboardController extends Controller
{
    public function index(): \Illuminate\Http\JsonResponse
    {
        return response()->success([
            'websiteCount' => Website::query()->count(),
            'driverCount' => Driver::query()->count(),
            'companyCount' => Company::query()->count(),
            'serviceCount' => Service::query()->count(),
            'appUserCount' => AppUser::query()->count(),
            'vehicleCount' => Vehicle::query()->count(),
            'branchCount' => Branch::query()->count(),
        ]);
    }
}
