<?php

namespace App\Http\Controllers\Api\V1\Livreur;

use App\Http\Controllers\Controller;
use App\Services\Shop\CourierTracker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Position GPS du livreur, partagee avec le client tant qu'il a une course en cours. */
class LocationController extends Controller
{
    /**
     * POST /livreur/location { latitude, longitude, heading?, speed_kmh? }
     * `recorded: false` : aucune course en cours, la position est ignoree (rien n'est conserve).
     */
    public function __invoke(Request $request, CourierTracker $tracker): JsonResponse
    {
        $d = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'heading' => ['nullable', 'numeric', 'between:0,360'],
            'speed_kmh' => ['nullable', 'numeric', 'between:0,250'],
        ]);

        $recorded = $tracker->record(
            $request->user(), (float) $d['latitude'], (float) $d['longitude'],
            isset($d['heading']) ? (float) $d['heading'] : null,
            isset($d['speed_kmh']) ? (float) $d['speed_kmh'] : null,
        );

        return response()->json(['data' => ['recorded' => $recorded]]);
    }
}
