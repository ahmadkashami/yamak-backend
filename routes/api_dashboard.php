<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\Dashboard\AdController;
use App\Http\Controllers\Api\Dashboard\AppSettingController;
use App\Http\Controllers\Api\Dashboard\AuthController;
use App\Http\Controllers\Api\Dashboard\AppUserController;
use App\Http\Controllers\Api\Dashboard\BranchController;
use App\Http\Controllers\Api\Dashboard\CompanyController;
use App\Http\Controllers\Api\Dashboard\CityController;
use App\Http\Controllers\Api\Dashboard\CountryController;
use App\Http\Controllers\Api\Dashboard\DashboardController;
use App\Http\Controllers\Api\Dashboard\DriverController;
use App\Http\Controllers\Api\Dashboard\FilesUploadController;
use App\Http\Controllers\Api\Dashboard\NotificationController;
use App\Http\Controllers\Api\Dashboard\RequestController;
use App\Http\Controllers\Api\Dashboard\RoleController;
use App\Http\Controllers\Api\Dashboard\ServiceController;
use App\Http\Controllers\Api\Dashboard\StateController;
use App\Http\Controllers\Api\Dashboard\UserController;
use App\Http\Controllers\Api\Dashboard\VehicleController;
use App\Http\Controllers\Api\Dashboard\WebsiteCarouselController;
use App\Http\Controllers\Api\Dashboard\WebsiteController;
use App\Http\Controllers\Api\Dashboard\WebsiteFeatureController;
use App\Http\Controllers\Api\Dashboard\WebsiteSliderController;
use App\Http\Controllers\Api\Dashboard\WebsiteThemeController;

Route::prefix('dashboard/v1')->name('api.dashboard.')->group(function () {
    Route::get('/', function () {
        return response()->json([
            'serverDate' => now()->format('n/j/Y'),
            'statusCode' => 200,
            'status' => 'success',
            'path' => '/' . request()->path() . '/',
            'duration' => '0ms',
            'method' => request()->method(),
            'data' => [
                'url' => '/' . request()->path() . '/',
                'status' => 'success',
                'message' => 'server is running successfully',
                'date' => now()->toJSON(),
            ],
        ]);
    })->name('root');

    Route::post('auth/login', [AuthController::class, 'login'])->name('auth.login');
    Route::get('websites/domains', [WebsiteController::class, 'byDomain'])->name('websites.domains');
    Route::post('files-upload', [FilesUploadController::class, 'store'])->name('files-upload.store');

    Route::middleware(['auth:sanctum', 'api.area:dashboard'])->group(function () {
        Route::get('sys-users/profile', [AuthController::class, 'profile'])->name('sys_users.profile');
        Route::put('sys-users/profile', [AuthController::class, 'updateProfile'])->name('sys_users.profile.update');
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard.index');
        Route::apiResource('sys-users', UserController::class)
            ->parameters(['sys-users' => 'user'])
            ->except(['destroy']);
        Route::apiResource('roles', RoleController::class)->except(['destroy']);
        Route::get('companies/{company}/branches', [CompanyController::class, 'branches'])->name('companies.branches');
        Route::get('companies/{company}/drivers', [CompanyController::class, 'drivers'])->name('companies.drivers');
        Route::get('companies/{company}/free-drivers', [CompanyController::class, 'freeDrivers'])->name('companies.free_drivers');
        Route::get('companies/{company}/vehicles', [CompanyController::class, 'vehicles'])->name('companies.vehicles');
        Route::apiResource('companies', CompanyController::class)->except(['destroy']);
        Route::get('branches/{branch}/services', [BranchController::class, 'services'])->name('branches.services');
        Route::get('branches/{branch}/drivers', [BranchController::class, 'drivers'])->name('branches.drivers');
        Route::get('branches/{branch}/free-drivers', [BranchController::class, 'freeDrivers'])->name('branches.free_drivers');
        Route::apiResource('branches', BranchController::class)->except(['destroy']);
        Route::get('services/list-ids', [ServiceController::class, 'listIds'])->name('services.list_ids');
        Route::apiResource('services', ServiceController::class);
        Route::get('countries/{country}/states', [CountryController::class, 'states'])->name('countries.states');
        Route::apiResource('countries', CountryController::class);
        Route::get('states/{state}/cities', [StateController::class, 'cities'])->name('states.cities');
        Route::post('states/bulk', [StateController::class, 'storeBulk'])->name('states.bulk');
        Route::apiResource('states', StateController::class)->only(['index', 'show']);
        Route::post('cities/bulk', [CityController::class, 'storeBulk'])->name('cities.bulk');
        Route::apiResource('cities', CityController::class)->only(['index', 'show']);
        Route::get('driver/list-ids', [DriverController::class, 'listIds'])->name('driver.list_ids');
        Route::apiResource('driver', DriverController::class)->parameters(['driver' => 'driver']);
        Route::apiResource('vehicles', VehicleController::class)->only(['index', 'store', 'show', 'update']);
        Route::apiResource('app-users', AppUserController::class)->only(['index', 'show']);
        Route::apiResource('requests', RequestController::class)->only(['index', 'store']);
        Route::apiResource('adds', AdController::class)->parameters(['adds' => 'ad']);
        Route::apiResource('app-setting', AppSettingController::class)
            ->parameters(['app-setting' => 'appSetting'])
            ->only(['index', 'store', 'show', 'update']);
        Route::post('notifications/users', [NotificationController::class, 'sendToUser'])->name('notifications.users');
        Route::post('notifications/topics', [NotificationController::class, 'sendToTopic'])->name('notifications.topics');
        Route::get('websites/companies/{company}', [WebsiteController::class, 'byCompany'])->name('websites.companies');
        Route::apiResource('websites', WebsiteController::class)->only(['index', 'store', 'update']);
        Route::get('website-themes/websites/{website}', [WebsiteThemeController::class, 'byWebsite'])->name('website-themes.websites');
        Route::apiResource('website-themes', WebsiteThemeController::class)
            ->parameters(['website-themes' => 'websiteTheme'])
            ->except(['show']);
        Route::apiResource('website-features', WebsiteFeatureController::class)
            ->parameters(['website-features' => 'websiteFeature'])
            ->except(['show']);
        Route::apiResource('website-sliders', WebsiteSliderController::class)
            ->parameters(['website-sliders' => 'websiteSlider'])
            ->except(['show']);
        Route::apiResource('website-carousels', WebsiteCarouselController::class)
            ->parameters(['website-carousels' => 'websiteCarousel'])
            ->only(['index', 'store', 'destroy']);
    });
});

Route::post('onboardings/{onboarding}/publish', [App\\Http\\Controllers\\Api\\Dashboard\\OnboardingController::class, 'publish'])->name('onboardings.publish');
Route::post('onboardings/{onboarding}/activate', [App\\Http\\Controllers\\Api\\Dashboard\\OnboardingController::class, 'activate'])->name('onboardings.activate');
Route::post('onboardings/{onboarding}/disable', [App\\Http\\Controllers\\Api\\Dashboard\\OnboardingController::class, 'disable'])->name('onboardings.disable');
Route::apiResource('onboardings', App\\Http\\Controllers\\Api\\Dashboard\\OnboardingController::class)->except(['destroy']);
Route::delete('onboardings/{onboarding}', [App\\Http\\Controllers\\Api\\Dashboard\\OnboardingController::class, 'destroy'])->name('onboardings.destroy');

