<?php

namespace App\Http\Requests\User;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
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
        $user = $this->route('user');
        $userId = $user instanceof User ? $user->id : null;

        return [
            'name' => ['sometimes', 'string', 'max:120'],
            'email' => ['sometimes', 'email', 'max:180', Rule::unique('user', 'email')->ignore($userId)],
            'password' => ['sometimes', 'string', 'min:8', 'max:72'],
            'role' => ['sometimes', 'string', Rule::in(['admin', 'almacenero'])],
            'status' => ['sometimes', 'string', Rule::in(['active', 'inactive'])],
            'warehouse_ids' => ['sometimes', 'array'],
            'warehouse_ids.*' => ['integer', Rule::exists('warehouse', 'id')],
        ];
    }
}
