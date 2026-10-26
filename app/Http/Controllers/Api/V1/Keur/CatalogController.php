<?php

namespace App\Http\Controllers\Api\V1\Keur;

use App\Enums\LodgingKind;
use App\Enums\RentalBilling;
use App\Http\Controllers\Controller;
use App\Http\Resources\LodgingResource;
use App\Models\Lodging;
use App\Support\Geo;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/** Catalogue public Keur. */
class CatalogController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $f = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'kind' => ['nullable', Rule::enum(LodgingKind::class)],
            'billing' => ['nullable', Rule::enum(RentalBilling::class)],
            'guests' => ['nullable', 'integer', 'min:1', 'max:20'],
            'city' => ['nullable', 'string', 'max:80'],
            'lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:lng'],
            'lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:lat'],
            'per_page' => ['nullable', 'integer', 'between:1,50'],
        ]);

        $rows = Lodging::with(['photos', 'host'])
            ->where('is_active', true)
            ->whereHas('host', fn ($q) => $q->where('is_visible', true))
            ->whereHas('photos')
            ->when($f['city'] ?? null, fn ($q, $city) => $q->whereRaw(
                'LOWER(city) = ?',
                [mb_strtolower(trim($city))],
            ))
            ->when($f['kind'] ?? null, fn ($q, $k) => $q->where('kind', $k))
            ->when($f['billing'] ?? null, fn ($q, $b) => $q->where('billing', $b))
            ->when($f['guests'] ?? null, fn ($q, $g) => $q->where('guests', '>=', $g))
            ->when($f['q'] ?? null, fn ($q, $t) => $q->where(function ($q) use ($t) {
                $like = '%'.addcslashes($t, '%_\\').'%';
                $q->where('title', 'like', $like)
                    ->orWhere('neighborhood', 'like', $like)
                    ->orWhere('city', 'like', $like);
            }))
            ->latest('id')
            ->paginate($f['per_page'] ?? 20)
            ->withQueryString();

        if (isset($f['lat'], $f['lng'])) {
            $rows->getCollection()->transform(function (Lodging $lodging) use ($f) {
                $lodging->setAttribute(
                    'distance_km',
                    round(Geo::km($f['lat'], $f['lng'], $lodging->latitude, $lodging->longitude), 2),
                );

                return $lodging;
            });
        }

        return LodgingResource::collection($rows);
    }

    public function show(int $lodging): LodgingResource
    {
        $row = Lodging::with(['photos', 'host'])
            ->where('is_active', true)
            ->whereHas('photos')
            ->findOrFail($lodging);

        return new LodgingResource($row);
    }
}
