<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:180', Rule::unique('user', 'email')],
            'password' => ['required', 'string', 'min:8', 'max:72'],
            'role' => ['required', 'string', Rule::in(['admin', 'almacenero'])],
            'status' => ['sometimes', 'string', Rule::in(['active', 'inactive'])],
            'warehouse_ids' => ['required_if:role,almacenero', 'array'],
            'warehouse_ids.*' => ['integer', Rule::exists('warehouse', 'id')],
        ];
    }
}
