<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminUserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $lastSeenAt = $this->last_seen_at;

        return [
            ...(new UserResource($this->resource))->toArray($request),
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'last_seen_at' => $lastSeenAt?->toIso8601String(),
            'is_connected' => $lastSeenAt !== null && $lastSeenAt->greaterThanOrEqualTo(now()->subMinutes(2)),
            'last_service_at' => $this->last_service_at,
        ];
    }
}
