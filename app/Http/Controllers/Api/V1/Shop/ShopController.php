<?php

namespace App\Http\Controllers\Api\V1\Shop;

use App\Enums\ShopKind;
use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Http\Resources\ShopResource;
use App\Models\Review;
use App\Models\Shop;
use App\Services\Shop\ShopCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/** Catalogue public : boutiques, produits, avis. */
class ShopController extends Controller
{
    /** GET /shops?kind=boutique|restaurant&q=&category=&open_now=&lat=&lng=&radius_km=&sort=distance|rating|popular|newest */
    public function index(Request $request, ShopCatalog $catalog): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'kind' => ['nullable', Rule::enum(ShopKind::class)],
            // Rayon (boutique) ou cuisine (restaurant).
            'category' => ['nullable', Rule::in(ShopKind::allShopCategories())],
            'open_now' => ['nullable', 'boolean'],
            'lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:lng,radius_km'],
            'lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:lat'],
            'radius_km' => ['nullable', 'numeric', 'between:0.1,200'],
            'sort' => ['nullable', Rule::in(['distance', 'rating', 'popular', 'newest'])],
            'per_page' => ['nullable', 'integer', 'between:1,50'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        if (($filters['sort'] ?? null) === 'distance' && ! isset($filters['lat'])) {
            unset($filters['sort']);
        }

        return ShopResource::collection($catalog->search($filters));
    }

    /** GET /shops/{shop} */
    public function show(int $shop): ShopResource
    {
        return new ShopResource(Shop::where('is_active', true)->with('owner')->findOrFail($shop));
    }

    /** GET /shops/{shop}/products?q=&category=&in_stock= */
    public function products(Request $request, int $shop): AnonymousResourceCollection
    {
        $shop = Shop::where('is_active', true)->findOrFail($shop);
        $f = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', Rule::in($shop->kind->productCategories())],
            'in_stock' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $products = $shop->products()->with('images')->where('is_active', true)
            ->when($f['q'] ?? null, fn ($q, $t) => $q->where('name', 'like', '%'.addcslashes($t, '%_\\').'%'))
            ->when($f['category'] ?? null, fn ($q, $c) => $q->where('category', $c))
            ->when($f['in_stock'] ?? false, fn ($q) => $q->where(fn ($q) => $q->where('track_stock', false)->orWhere('stock_quantity', '>', 0)))
            ->orderByDesc('is_featured')->orderBy('name')
            ->paginate($f['per_page'] ?? 50)->withQueryString();

        return ProductResource::collection($products);
    }

    /** GET /shops/{shop}/reviews */
    public function reviews(int $shop): JsonResponse
    {
        $shop = Shop::where('is_active', true)->findOrFail($shop);

        $reviews = Review::with('customer:id,name')->where('shop_id', $shop->id)->latest('id')->paginate(20);

        return response()->json([
            'summary' => ['rating_avg' => $shop->rating_avg, 'rating_count' => $shop->rating_count],
            'data' => $reviews->getCollection()->map(fn (Review $r) => [
                'id' => $r->id,
                'rating' => $r->rating,
                'comment' => $r->comment,
                // Nom abrege : "Amina K."
                'author' => $this->shortName($r->customer?->name),
                'reply' => $r->reply,
                'created_at' => $r->created_at?->toIso8601String(),
            ])->values(),
            'meta' => ['current_page' => $reviews->currentPage(), 'last_page' => $reviews->lastPage(), 'total' => $reviews->total()],
        ]);
    }

    private function shortName(?string $name): string
    {
        $parts = preg_split('/\s+/', trim((string) $name)) ?: [];

        return count($parts) > 1 ? $parts[0].' '.mb_substr($parts[1], 0, 1).'.' : ($parts[0] ?: 'Client');
    }
}
