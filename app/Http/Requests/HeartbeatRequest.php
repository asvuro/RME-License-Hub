<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class HeartbeatRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'instance_id' => ['nullable', 'string', 'max:64'],
            'client_code' => ['nullable', 'string', 'max:50'],
            'license_key' => ['nullable', 'string', 'max:128'],
            'hardware_id' => ['nullable', 'string', 'max:128'],
            'app_version' => ['nullable', 'string', 'max:30'],
            'php_version' => ['nullable', 'string', 'max:30'],
            'timestamp' => ['nullable', 'integer'],
        ];
    }
}
