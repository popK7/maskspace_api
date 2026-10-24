<?php

namespace App\Http\Requests\Auth;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => is_string($this->email) ? Str::lower(trim($this->email)) : $this->email,
            'phone' => is_string($this->phone) ? trim(preg_replace('/\s+/', ' ', $this->phone)) : $this->phone,
        ]);
    }

    /** Le rôle peut être attribué au moment de la complétion du profil après l'inscription. */
    public function rules(): array
    {
        return [
            'role' => ['sometimes', 'string', Rule::in(UserRole::selfRegistrableValues())],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            'phone' => ['required', 'string', 'regex:/^\+?[0-9\s().-]{6,20}$/'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
            'device_name' => ['nullable', 'string', 'max:100'],
        ];
    }
}
