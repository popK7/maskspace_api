<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $profile = $this->relationLoaded('profile') ? $this->profile : null;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'role' => $this->role->value,
            'status' => $this->status->value,
            'email_verified' => $this->hasVerifiedEmail(),
            'profile_completed' => $this->hasCompletedProfile(),
            'is_visible' => (bool) $this->is_visible,
            'activation_requested' => $this->activation_requested_at !== null,
            'profile' => $profile ? [
                'shop_name' => $profile->shop_name,
                'vehicle_model' => $profile->vehicle_model,
                'vehicle_plate' => $profile->vehicle_plate,
                'specialties' => $profile->specialties ?? [],
            ] : null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
