<?php

namespace App\Http\Controllers\Api\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use Illuminate\Http\Request;

class AppSettingController extends Controller
{
    public function index(): \Illuminate\Http\JsonResponse
    {
        return response()->success(
            AppSetting::query()
                ->latest('id')
                ->get()
                ->map(fn (AppSetting $appSetting) => $this->appSettingResource($appSetting))
                ->values()
        );
    }

    public function store(Request $request): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate($this->rules());
        $appSetting = AppSetting::query()->create($this->payload($data));

        return response()->success($this->appSettingResource($appSetting), 'App setting created successfully');
    }

    public function show(AppSetting $appSetting): \Illuminate\Http\JsonResponse
    {
        return response()->success($this->appSettingResource($appSetting));
    }

    public function update(Request $request, AppSetting $appSetting): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate($this->rules(false));
        $appSetting->update($this->payload($data, $appSetting));

        return response()->success($this->appSettingResource($appSetting->fresh()), 'App setting updated successfully');
    }

    private function rules(bool $creating = true): array
    {
        return [
            'androidVersionCode' => [$creating ? 'required' : 'sometimes', 'nullable', 'string', 'max:255'],
            'androidRecommendedVersion' => ['nullable', 'string', 'max:255'],
            'iosVersion' => ['nullable', 'string', 'max:255'],
            'iosRecommendedVersion' => ['nullable', 'string', 'max:255'],
            'test' => ['nullable', 'string', 'max:255'],
        ];
    }

    private function payload(array $data, ?AppSetting $appSetting = null): array
    {
        return [
            'android_version_code' => $data['androidVersionCode'] ?? $appSetting?->android_version_code,
            'android_recommended_version' => $data['androidRecommendedVersion'] ?? $appSetting?->android_recommended_version,
            'ios_version' => $data['iosVersion'] ?? $appSetting?->ios_version,
            'ios_recommended_version' => $data['iosRecommendedVersion'] ?? $appSetting?->ios_recommended_version,
            'test' => $data['test'] ?? $appSetting?->test,
        ];
    }

    public function appSettingResource(AppSetting $appSetting): array
    {
        return [
            'id' => $appSetting->id,
            '_id' => (string) $appSetting->id,
            'androidVersionCode' => $appSetting->android_version_code,
            'androidRecommendedVersion' => $appSetting->android_recommended_version,
            'iosVersion' => $appSetting->ios_version,
            'iosRecommendedVersion' => $appSetting->ios_recommended_version,
            'test' => $appSetting->test,
            'createdAt' => optional($appSetting->created_at)->toJSON(),
            'updatedAt' => optional($appSetting->updated_at)->toJSON(),
        ];
    }
}
