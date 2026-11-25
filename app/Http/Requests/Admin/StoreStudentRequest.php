<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreStudentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+\-\s()]+$/'],
            'birthday' => ['nullable', 'date', 'before_or_equal:'.now()->subYears(5)->toDateString()],
            'country' => ['nullable', 'string', 'max:100'],
            'skill_level' => ['required', 'in:beginner,intermediate,advanced'],
            'status' => ['required', 'in:active,banned'],
            'is_first_time' => ['nullable', 'boolean'],
            'has_board' => ['nullable', 'boolean'],
            'height' => ['nullable', 'numeric', 'min:50', 'max:250'],
            'weight' => ['nullable', 'numeric', 'min:15', 'max:250'],
            'emergency_contact_name' => ['nullable', 'string', 'max:255'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:30'],
            'medical_info' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
