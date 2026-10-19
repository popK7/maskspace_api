<?php

namespace App\Http\Controllers\Api\V1\Driver;

use App\Http\Controllers\Controller;
use App\Services\Dispatch\LocationPing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PresenceController extends Controller
{
    public function availability(Request $request): JsonResponse
    {
        $data = $request->validate(['is_available' => ['required', 'boolean']]);
        $user = $request->user();
        $user->forceFill(['is_available' => (bool) $data['is_available']])->save();

        return response()->json(['data' => ['is_available' => (bool) $user->is_available]]);
    }

    public function location(Request $request, LocationPing $ping): JsonResponse
    {
        $d = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'heading' => ['nullable', 'numeric', 'between:0,360'],
            'speed_kmh' => ['nullable', 'numeric', 'between:0,250'],
        ]);

        $ping->record(
            $request->user(),
            (float) $d['latitude'],
            (float) $d['longitude'],
            isset($d['heading']) ? (float) $d['heading'] : null,
            isset($d['speed_kmh']) ? (float) $d['speed_kmh'] : null,
        );

        return response()->json(['data' => ['recorded' => true]]);
    }
}
