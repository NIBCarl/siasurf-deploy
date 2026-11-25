<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateStudentRequest extends FormRequest
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
        $student = $this->route('user');
        $studentId = $student instanceof User ? $student->id : $student;

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($studentId)],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
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

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $student = $this->route('user');
                if (! $student instanceof User) {
                    return;
                }

                $newBirthday = $this->input('birthday') ?: null;
                $currentBirthday = $student->studentProfile?->birthday?->format('Y-m-d');

                if ($newBirthday !== $currentBirthday && $student->activeSlotHoldingBookingsCount() > 0) {
                    $validator->errors()->add(
                        'birthday',
                        'Date of birth cannot be changed while the student has active or upcoming bookings.'
                    );
                }
            },
        ];
    }
}
