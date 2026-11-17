<?php

namespace App\Http\Requests\Auth;

use App\Services\Auth\PhoneIdentity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->email)) {
            $this->merge(['email' => Str::lower(trim($this->email))]);
        }
        if (is_string($this->phone)) {
            $this->merge(['phone' => PhoneIdentity::normalize($this->phone)]);
        }
    }

    public function rules(): array
    {
        // Pas de politique de complexite ici : on ne fait que verifier les identifiants.
        return [
            'phone' => ['required_without:email', 'nullable', 'string', 'regex:/^\+[1-9]\d{6,14}$/'],
            'email' => ['required_without:phone', 'nullable', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ];
    }
}
