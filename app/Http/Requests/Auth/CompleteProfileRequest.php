<?php

namespace App\Http\Requests\Auth;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Profil obligatoire avant de pouvoir demander l'activation du compte. */
class CompleteProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => is_string($this->name) ? trim(preg_replace('/\s+/', ' ', $this->name)) : $this->name,
            'shop_name' => is_string($this->shop_name) ? trim($this->shop_name) : $this->shop_name,
            'vehicle_plate' => is_string($this->vehicle_plate) ? Str::upper(trim($this->vehicle_plate)) : $this->vehicle_plate,
        ]);
    }

    public function rules(): array
    {
        $rules = [
            'role' => ['required', 'string', Rule::in(UserRole::selfRegistrableValues())],
            'name' => ['required', 'string', 'min:2', 'max:120'],
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
            if ($role === UserRole::Demarcheur) {
                $rules['housing_zones'] = ['required_without:specialties', 'array', 'min:1', 'max:30'];
                $rules['housing_zones.*.id'] = ['required', 'string', 'max:40', 'distinct'];
                $rules['housing_zones.*.name'] = ['required', 'string', 'max:150'];
                $rules['housing_zones.*.city'] = ['required', 'string', 'max:150'];
                $rules['housing_zones.*.latitude'] = ['required', 'numeric', 'between:-90,90'];
                $rules['housing_zones.*.longitude'] = ['required', 'numeric', 'between:-180,180'];
                $rules['specialties'] = ['required_without:housing_zones', 'array', 'min:1', 'max:'.count($allowed)];
                $rules['specialties.*'] = ['string', 'distinct', Rule::in($allowed)];
            } else {
                $rules['specialties'] = ['required', 'array', 'min:1', 'max:'.count($allowed)];
                $rules['specialties.*'] = ['string', 'distinct', Rule::in($allowed)];
            }
        }

        return $rules;
    }
}
