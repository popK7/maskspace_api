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
            'name' => is_string($this->name) ? trim(preg_replace('/\s+/', ' ', $this->name)) : $this->name,
            'email' => is_string($this->email) ? Str::lower(trim($this->email)) : $this->email,
            'phone' => is_string($this->phone) ? trim(preg_replace('/\s+/', ' ', $this->phone)) : $this->phone,
            'shop_name' => is_string($this->shop_name) ? trim($this->shop_name) : $this->shop_name,
            'vehicle_plate' => is_string($this->vehicle_plate) ? Str::upper(trim($this->vehicle_plate)) : $this->vehicle_plate,
        ]);
    }

    /**
     * Les regles dependent du role choisi : seuls les champs pertinents pour ce role sont valides
     * (et donc seuls ceux-la ressortent de `validated()`).
     */
    public function rules(): array
    {
        $rules = [
            'role' => ['required', 'string', Rule::in(UserRole::selfRegistrableValues())],
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            'phone' => ['required', 'string', 'regex:/^\+?[0-9\s().-]{6,20}$/'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
            'device_name' => ['nullable', 'string', 'max:100'],
        ];

        $role = UserRole::tryFrom((string) $this->input('role'));

        if ($role?->requiresVehicle()) {
            $rules['vehicle_model'] = ['required', 'string', 'max:120'];
            $rules['vehicle_plate'] = ['required', 'string', 'max:32'];
        }

        if ($role?->requiresShopName()) {
            $rules['shop_name'] = ['required', 'string', 'max:150'];
        }

        if ($allowed = $role?->allowedSpecialties()) {
            $rules['specialties'] = ['required', 'array', 'min:1', 'max:'.count($allowed)];
            $rules['specialties.*'] = ['string', 'distinct', Rule::in($allowed)];
        }

        return $rules;
    }
}
