<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReportRosterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'users' => ['required', 'array', 'max:100000'],
            'users.*.user_id' => ['required', 'string', 'max:128'],
            'users.*.is_admin' => ['required', 'boolean'],
            'users.*.is_active' => ['sometimes', 'boolean'],
            'users.*.registered_at' => ['nullable', 'date'],
        ];
    }
}
