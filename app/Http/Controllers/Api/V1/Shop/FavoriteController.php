<?php

namespace App\Http\Controllers\Api\V1\Shop;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Http\Resources\ShopResource;
use App\Models\Favorite;
use App\Models\Product;
use App\Models\Shop;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Favoris du client connecte : boutiques et produits. */
class FavoriteController extends Controller
{
    /** GET /favorites/ids — identifiants seuls (l'app les garde en memoire pour afficher les coeurs). */
    public function ids(Request $request): JsonResponse
    {
        $rows = Favorite::where('user_id', $request->user()->id)->get(['kind', 'target_id']);

        return response()->json([
            'shops' => $rows->where('kind', 'shop')->pluck('target_id')->values(),
            'products' => $rows->where('kind', 'product')->pluck('target_id')->values(),
        ]);
    }

    /** GET /favorites — boutiques et produits favoris (uniquement ceux encore en ligne). */
    public function index(Request $request): JsonResponse
    {
        $favorites = Favorite::where('user_id', $request->user()->id)->latest('id')->get();

        $shopIds = $favorites->where('kind', 'shop')->pluck('target_id');
        $productIds = $favorites->where('kind', 'product')->pluck('target_id');

        $shops = Shop::where('is_active', true)->whereIn('id', $shopIds)->get()
            ->sortBy(fn (Shop $s) => $shopIds->search($s->id))->values();

        $products = Product::with(['images', 'shop'])->where('is_active', true)
            ->whereIn('id', $productIds)->whereHas('shop', fn ($q) => $q->where('is_active', true))->get()
            ->sortBy(fn (Product $p) => $productIds->search($p->id))->values();

        return response()->json([
            'shops' => ShopResource::collection($shops)->resolve($request),
            'products' => $products->map(fn (Product $p) => array_merge(
                (new ProductResource($p))->resolve($request),
                ['shop' => (new ShopResource($p->shop))->resolve($request)],
            ))->values(),
        ]);
    }

    /** PUT /favorites/{kind}/{id} — idempotent. */
    public function add(Request $request, string $kind, int $id): Response
    {
        $exists = $kind === 'shop'
            ? Shop::where('is_active', true)->whereKey($id)->exists()
            : Product::where('is_active', true)->whereKey($id)->whereHas('shop', fn ($q) => $q->where('is_active', true))->exists();

        abort_unless($exists, 404, 'Élément introuvable.');

        Favorite::firstOrCreate(['user_id' => $request->user()->id, 'kind' => $kind, 'target_id' => $id]);

        return response()->noContent();
    }

    /** DELETE /favorites/{kind}/{id} — idempotent. */
    public function remove(Request $request, string $kind, int $id): Response
    {
        Favorite::where(['user_id' => $request->user()->id, 'kind' => $kind, 'target_id' => $id])->delete();

        return response()->noContent();
    }
}
