<?php

namespace App\Http\Requests\Hub;

use Illuminate\Contracts\Validation\Rule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateTierRequest extends FormRequest
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
        $tierId = $this->route('tier')?->id;

        return [
            'slug' => ['required', 'string', 'max:50', 'unique:tiers,slug,'.$tierId],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'base_max_users' => ['required', 'integer', 'min:0'],
            'default_duration_days' => ['required', 'integer', 'min:1'],
            'included_modules' => ['nullable', 'array'],
            'included_modules.*' => ['string', 'max:100'],
            'metadata' => ['nullable', 'array'],
            'is_active' => ['boolean'],
        ];
    }
}
