<?php

namespace App\Http\Controllers\Api\V1\Merchant;

use App\Http\Controllers\Controller;
use App\Http\Resources\ShopResource;
use App\Models\Shop;
use App\Services\Media\ImageStorage;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Gestion de SA boutique (role `boutique`). La boutique est toujours celle de l'utilisateur connecte. */
class ShopController extends Controller
{
    /** GET /merchant/shop */
    public function show(Request $request): ShopResource
    {
        return new ShopResource(self::shopOf($request));
    }

    /** PATCH /merchant/shop — mise a jour partielle (infos, horaires, livraison, ouvert/ferme). */
    public function update(Request $request): ShopResource
    {
        $shop = self::shopOf($request);

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'min:2', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
            // Rayons pour une boutique, cuisines pour un restaurant.
            'categories' => ['sometimes', 'required', 'array', 'min:1', 'max:'.count($shop->kind->categories())],
            'categories.*' => ['string', 'distinct', Rule::in($shop->kind->categories())],
            // Mode rush (cuisine débordée) : minutes ajoutées au délai annoncé, 0 pour l'arrêter.
            'rush_minutes' => ['sometimes', 'integer', 'between:0,90'],
            'phone' => ['nullable', 'string', 'regex:/^\+?[0-9\s().-]{6,20}$/'],
            'address' => ['nullable', 'string', 'max:255'],
            'neighborhood' => ['nullable', 'string', 'max:150'],
            'city' => ['sometimes', 'required', 'string', 'max:100'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
            'is_active' => ['sometimes', 'boolean'],
            'is_open' => ['sometimes', 'boolean'],
            'opening_hours' => ['nullable', 'array', function ($attr, $value, $fail) {
                foreach (array_keys($value) as $day) {
                    if (! in_array($day, Shop::DAYS, true)) {
                        $fail('Jour invalide : '.$day.' (attendu : '.implode(', ', Shop::DAYS).').');
                    }
                }
            }],
            'opening_hours.*' => ['array', 'max:3'],
            'opening_hours.*.*.open' => ['required', 'date_format:H:i'],
            'opening_hours.*.*.close' => ['required', 'date_format:H:i', 'after:opening_hours.*.*.open'],
            'supports_delivery' => ['sometimes', 'boolean'],
            'supports_pickup' => ['sometimes', 'boolean'],
            'delivery_fee_cents' => ['sometimes', 'integer', 'between:0,100000'],
            'free_delivery_threshold_cents' => ['nullable', 'integer', 'between:0,10000000'],
            'min_order_cents' => ['sometimes', 'integer', 'between:0,10000000'],
            'delivery_radius_km' => ['nullable', 'integer', 'between:1,100'],
            'prep_minutes' => ['sometimes', 'integer', 'between:1,240'],
        ]);

        $delivery = $data['supports_delivery'] ?? $shop->supports_delivery;
        $pickup = $data['supports_pickup'] ?? $shop->supports_pickup;
        if (! $delivery && ! $pickup) {
            throw ValidationException::withMessages([
                'supports_delivery' => ['Activez au moins la livraison ou le retrait en boutique.'],
            ]);
        }

        $shop->fill($data);

        // Le rush s'arrête tout seul après quelques heures pour ne pas rester actif par oubli.
        if (array_key_exists('rush_minutes', $data)) {
            $minutes = (int) $data['rush_minutes'];
            $shop->rush_extra_minutes = $minutes;
            $shop->rush_until = $minutes > 0 ? now()->addHours((int) config('shop.rush_hours')) : null;
        }

        // Interrupteur "ouvert" : s'il est active hors des horaires, c'est une ouverture exceptionnelle
        // (limitee dans le temps pour ne pas rester ouvert par oubli) ; s'il est coupe, on l'annule.
        if (array_key_exists('is_open', $data)) {
            $shop->force_open_until = null;
            if ($shop->is_open && ! $shop->isOpenNow()) {
                $shop->force_open_until = now()->addHours((int) config('shop.force_open_hours'));
            }
        }

        $shop->save();

        return new ShopResource($shop->fresh());
    }

    /** POST /merchant/shop/images/{type} (logo|cover) — multipart, champ `image`. */
    public function uploadImage(Request $request, string $type, ImageStorage $storage): ShopResource
    {
        $request->validate([
            'image' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.config('shop.image_max_kb')],
        ]);

        $shop = self::shopOf($request);
        $column = $type.'_path';
        $old = $shop->{$column};

        $shop->forceFill([$column => $storage->store($request->file('image'), "shops/{$shop->id}")])->save();
        $storage->delete($old);

        return new ShopResource($shop->fresh());
    }

    /** DELETE /merchant/shop/images/{type} */
    public function deleteImage(Request $request, string $type, ImageStorage $storage): ShopResource
    {
        $shop = self::shopOf($request);
        $column = $type.'_path';
        $storage->delete($shop->{$column});
        $shop->forceFill([$column => null])->save();

        return new ShopResource($shop->fresh());
    }

    public static function shopOf(Request $request): Shop
    {
        return $request->user()->shop ?? abort(404, 'Aucune boutique associée à ce compte.');
    }
}
