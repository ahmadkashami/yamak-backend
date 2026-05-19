<?php

namespace App\Services;

use App\Http\Requests\Dashboard\RegisterUserRequest;
use App\Models\Partner;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class UserProfileService
{
    public function register(RegisterUserRequest $request): User
    {
        $partner = $request->isPartnerRegistration()
            ? $this->createPartner($request)
            : null;

        $user = User::query()->create([
            'name' => $this->localizedName($request->input('name_en'), $request->input('name_ar'), $request->input('name')),
            'email' => $request->input('email'),
            'mobile' => $request->input('mobile') ?? $request->input('phone'),
            'password' => Hash::make($request->input('password')),
            'avatar' => $request->input('avatar'),
            'type' => $partner ? 'partner' : 'user',
            'partner_id' => $partner?->id,
            'role_id' => $partner ? Role::query()->where('name', 'Partner Admin')->value('id') : null,
        ]);

        return $user->fresh(['role', 'partner', 'city', 'country']);
    }

    public function update(User $user, array $data): User
    {
        $payload = [
            'name' => $this->localizedName($data['name_en'] ?? null, $data['name_ar'] ?? null, $data['name'] ?? null, $user->name),
            'email' => $data['email'],
            'mobile' => $data['mobile'] ?? $user->mobile,
            'type' => $data['user_type'] ?? $user->type,
        ];

        if (isset($data['password'])) {
            $payload['password'] = Hash::make($data['password']);
        }

        if (isset($data['avatar']) && is_string($data['avatar']) && $data['avatar'] !== '') {
            $payload['avatar'] = $data['avatar'];
        }

        $user->update($payload);

        if ($user->partner) {
            $this->updatePartner($user->partner, $data);
        }

        return $user->fresh(['role', 'partner', 'city', 'country']);
    }

    private function createPartner(RegisterUserRequest $request): Partner
    {
        $name = $this->localizedName(
            $request->input('partner_name_en') ?? $request->input('partner_name'),
            $request->input('partner_name_ar') ?? $request->input('partner_name'),
            $request->input('partner_name')
        );

        $englishName = $name['en'] ?? reset($name);

        return Partner::query()->create([
            'name' => $name,
            'slug' => Str::slug($englishName).'-'.Str::lower(Str::random(6)),
            'email' => $request->input('partner_email') ?? $request->input('email'),
            'phone' => $request->input('partner_phone') ?? $request->input('phone') ?? $request->input('mobile'),
            'logo' => $request->hasFile('logo')
                ? $this->storeImage($request->file('logo'), 'partners/logos')
                : $request->input('logo'),
            'status' => 'pending',
        ]);
    }

    private function updatePartner(Partner $partner, array $data): void
    {
        $payload = [];

        if (array_key_exists('partner_name_ar', $data) || array_key_exists('partner_name_en', $data)) {
            $payload['name'] = $this->localizedName(
                $data['partner_name_en'] ?? null,
                $data['partner_name_ar'] ?? null,
                null,
                $partner->name
            );
        }

        if (! empty($data['partner_email'])) {
            $payload['email'] = $data['partner_email'];
        }

        if (array_key_exists('partner_phone', $data)) {
            $payload['phone'] = $data['partner_phone'];
        }

        if (isset($data['partner_logo'])) {
            $payload['logo'] = $data['partner_logo'] instanceof UploadedFile
                ? $this->storeImage($data['partner_logo'], 'partners/logos')
                : $data['partner_logo'];
        }

        if ($payload) {
            $partner->update($payload);
        }
    }

    private function localizedName(?string $en, ?string $ar, ?string $fallback = null, array|string|null $existing = null): array
    {
        $existing = is_array($existing) ? $existing : [];
        $fallback ??= $en ?? $ar ?? $existing['en'] ?? $existing['ar'] ?? '';

        return [
            'en' => $en ?? $existing['en'] ?? $fallback,
            'ar' => $ar ?? $existing['ar'] ?? $fallback,
        ];
    }

    private function storeImage(UploadedFile $file, string $directory): string
    {
        return Storage::disk('public')->url($file->store($directory, 'public'));
    }

}
