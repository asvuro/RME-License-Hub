<?php

namespace App\Http\Requests\Hub;

use Illuminate\Contracts\Validation\Rule;
use Illuminate\Foundation\Http\FormRequest;

class IssueLicenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, Rule|array|string>
     */
    public function rules(): array
    {
        return [
            'tenant_id' => ['required', 'uuid', 'exists:tenants,id'],
            'tier_id' => ['required', 'integer', 'exists:tiers,id'],
            'duration_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
        ];
    }
}
