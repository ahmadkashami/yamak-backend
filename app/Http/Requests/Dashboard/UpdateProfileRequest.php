<?php

namespace App\Http\Requests\Dashboard;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes'],
            'mobile' => ['sometimes', 'string'],
            'avatar' => ['sometimes', 'nullable', 'string'],
            'lang' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
