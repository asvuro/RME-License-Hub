<?php

namespace App\Http\Requests\Hub;

use Illuminate\Contracts\Validation\Rule;
use Illuminate\Foundation\Http\FormRequest;

class AssignAddonRequest extends FormRequest
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
            'entitlement_id' => ['required', 'uuid', 'exists:license_entitlements,id'],
            'addon_type' => ['required', 'in:module,user_quota,branch_quota,time_extension'],
            'target_module_slug' => ['required_if:addon_type,module', 'nullable', 'string', 'max:100'],
            'quantity' => ['required', 'integer', 'min:1'],
            'label' => ['nullable', 'string', 'max:255'],
            'effective_from' => ['nullable', 'date'],
            'effective_until' => ['nullable', 'date', 'after_or_equal:effective_from'],
        ];
    }
}
