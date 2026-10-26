<?php

namespace App\Http\Requests\Auth;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * PATCH /me — mise a jour partielle. Le role, le statut et le mot de passe ne sont PAS modifiables ici.
 */
class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merge = [];
        foreach (['name', 'phone', 'shop_name'] as $field) {
            if (is_string($this->input($field))) {
                $merge[$field] = trim(preg_replace('/\s+/', ' ', $this->input($field)));
            }
        }
        if (is_string($this->vehicle_plate)) {
            $merge['vehicle_plate'] = Str::upper(trim($this->vehicle_plate));
        }
        $this->merge($merge);
    }

    public function rules(): array
    {
        /** @var User $user */
        $user = $this->user();
        $role = $user->role;

        $rules = [
            'name' => ['sometimes', 'required', 'string', 'min:2', 'max:120'],
            'phone' => ['sometimes', 'required', 'string', 'regex:/^\+?[0-9\s().-]{6,20}$/'],
        ];

        if ($role->requiresVehicle()) {
            $rules['vehicle_model'] = ['sometimes', 'required', 'string', 'max:120'];
            $rules['vehicle_plate'] = ['sometimes', 'required', 'string', 'max:32'];
        }

        if ($role->requiresShopName()) {
            $rules['shop_name'] = ['sometimes', 'required', 'string', 'max:150'];
        }

        if ($allowed = $role->allowedSpecialties()) {
            if ($role === UserRole::Demarcheur) {
                $rules['housing_zones'] = ['sometimes', 'required_without:specialties', 'array', 'min:1', 'max:30'];
                $rules['housing_zones.*.id'] = ['required', 'string', 'max:40', 'distinct'];
                $rules['housing_zones.*.name'] = ['required', 'string', 'max:150'];
                $rules['housing_zones.*.city'] = ['required', 'string', 'max:150'];
                $rules['housing_zones.*.latitude'] = ['required', 'numeric', 'between:-90,90'];
                $rules['housing_zones.*.longitude'] = ['required', 'numeric', 'between:-180,180'];
                $rules['specialties'] = ['sometimes', 'required_without:housing_zones', 'array', 'min:1', 'max:'.count($allowed)];
                $rules['specialties.*'] = ['string', 'distinct', Rule::in($allowed)];
            } else {
                $rules['specialties'] = ['sometimes', 'required', 'array', 'min:1', 'max:'.count($allowed)];
                $rules['specialties.*'] = ['string', 'distinct', Rule::in($allowed)];
            }
        }

        return $rules;
    }
}
