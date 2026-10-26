<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Geocoding\PlaceSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlaceSearchController extends Controller
{
    public function search(Request $request, PlaceSearchService $places): JsonResponse
    {
        $filters = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:150'],
            'lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:lng'],
            'lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:lat'],
            'type' => ['nullable', 'string', 'in:neighborhood'],
        ]);

        return response()->json([
            'data' => $places->search(
                $filters['q'],
                isset($filters['lat']) ? (float) $filters['lat'] : null,
                isset($filters['lng']) ? (float) $filters['lng'] : null,
                $filters['type'] ?? null,
            ),
        ]);
    }
}
