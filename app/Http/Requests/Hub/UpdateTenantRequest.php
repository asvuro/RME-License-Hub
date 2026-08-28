<?php

namespace App\Http\Requests\Hub;

use Illuminate\Contracts\Validation\Rule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateTenantRequest extends FormRequest
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
        $tenantId = $this->route('tenant')?->id;

        return [
            'group_id' => ['nullable', 'uuid', 'exists:groups,id'],
            'client_code' => ['required', 'string', 'max:50', 'unique:tenants,client_code,'.$tenantId],
            'client_name' => ['required', 'string', 'max:255'],
            'legal_entity_name' => ['nullable', 'string', 'max:255'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string'],
            'status' => ['required', 'in:active,suspended,terminated'],
        ];
    }
}
