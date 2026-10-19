<?php

namespace App\Http\Controllers\Api\V1\Merchant;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Models\ProductImage;
use App\Services\Media\ImageStorage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProductController extends Controller
{
    /** GET /merchant/products?q=&category=&active=&stock=low|out */
    public function index(Request $request): AnonymousResourceCollection
    {
        $shop = ShopController::shopOf($request);
        $f = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', Rule::in($shop->kind->productCategories())],
            'active' => ['nullable', 'boolean'],
            'stock' => ['nullable', Rule::in(['low', 'out'])],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $products = $shop->products()->with('images')
            ->when($f['q'] ?? null, fn ($q, $t) => $q->where('name', 'like', '%'.addcslashes($t, '%_\\').'%'))
            ->when($f['category'] ?? null, fn ($q, $c) => $q->where('category', $c))
            ->when(isset($f['active']), fn ($q) => $q->where('is_active', (bool) $f['active']))
            ->when(($f['stock'] ?? null) === 'out', fn ($q) => $q->where('track_stock', true)->where('stock_quantity', 0))
            ->when(($f['stock'] ?? null) === 'low', fn ($q) => $q->where('track_stock', true)
                ->whereColumn('stock_quantity', '<=', 'low_stock_threshold'))
            ->orderBy('name')->paginate($f['per_page'] ?? 50)->withQueryString();

        return ProductResource::collection($products);
    }

    /** POST /merchant/products — 201 */
    public function store(Request $request): ProductResource
    {
        $shop = ShopController::shopOf($request);
        $data = $this->validated($request);

        // Un plat se prépare à la commande : pas de stock suivi, sauf demande explicite.
        if ($shop->isRestaurant() && ! array_key_exists('track_stock', $data)) {
            $data['track_stock'] = false;
        }

        $product = $shop->products()->create($data);

        // refresh() : recupere les valeurs par defaut posees par la base (is_active, seuil de stock...).
        return new ProductResource($product->refresh()->load('images'));
    }

    /** GET /merchant/products/{product} */
    public function show(Request $request, int $product): ProductResource
    {
        return new ProductResource($this->owned($request, $product)->load('images'));
    }

    /** PATCH /merchant/products/{product} */
    public function update(Request $request, int $product): ProductResource
    {
        $model = $this->owned($request, $product);
        $model->fill($this->validated($request, partial: true, current: $model))->save();

        return new ProductResource($model->load('images'));
    }

    /** DELETE /merchant/products/{product} — suppression logique (les commandes passees gardent leur snapshot). */
    public function destroy(Request $request, int $product): Response
    {
        $this->owned($request, $product)->delete();

        return response()->noContent();
    }

    /**
     * PATCH /merchant/products/{product}/availability  { "sold_out": true }
     * « Épuisé aujourd'hui » : le plat est grisé chez les clients et redevient disponible tout seul à minuit.
     */
    public function availability(Request $request, int $product): ProductResource
    {
        $data = $request->validate(['sold_out' => ['required', 'boolean']]);

        $model = $this->owned($request, $product);
        $model->forceFill(['sold_out_until' => $data['sold_out'] ? now()->endOfDay() : null])->save();

        return new ProductResource($model->load('images'));
    }

    /**
     * PATCH /merchant/products/{product}/stock  { "quantity": 40 }  ou  { "delta": -3 }
     * Ajustement atomique (verrou de ligne) : pas de perte si une commande arrive en meme temps.
     */
    public function stock(Request $request, int $product): ProductResource
    {
        $data = $request->validate([
            'quantity' => ['required_without:delta', 'prohibits:delta', 'integer', 'between:0,1000000'],
            'delta' => ['required_without:quantity', 'prohibits:quantity', 'integer', 'between:-1000000,1000000'],
        ]);

        $model = $this->owned($request, $product);

        DB::transaction(function () use ($model, $data) {
            $locked = Product::lockForUpdate()->findOrFail($model->id);
            $next = isset($data['quantity']) ? $data['quantity'] : $locked->stock_quantity + $data['delta'];

            if ($next < 0) {
                throw ValidationException::withMessages(['delta' => ['Le stock ne peut pas être négatif.']]);
            }

            $locked->forceFill(['stock_quantity' => $next, 'track_stock' => true])->save();
        });

        return new ProductResource($model->fresh('images'));
    }

    /** POST /merchant/products/{product}/images — multipart, champ `image` */
    public function addImage(Request $request, int $product, ImageStorage $storage): ProductResource
    {
        $request->validate([
            'image' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.config('shop.image_max_kb')],
        ]);

        $model = $this->owned($request, $product);

        if ($model->images()->count() >= (int) config('shop.product_max_images')) {
            throw ValidationException::withMessages(['image' => ['Maximum '.config('shop.product_max_images').' photos par produit.']]);
        }

        $path = $storage->store($request->file('image'), "shops/{$model->shop_id}/products");
        $model->images()->create(['path' => $path, 'position' => (int) $model->images()->max('position') + 1]);

        return new ProductResource($model->load('images'));
    }

    /** DELETE /merchant/products/{product}/images/{image} */
    public function deleteImage(Request $request, int $product, int $image, ImageStorage $storage): ProductResource
    {
        $model = $this->owned($request, $product);
        $img = $model->images()->findOrFail($image);

        $storage->delete($img->path);
        $img->delete();

        return new ProductResource($model->load('images'));
    }

    private function owned(Request $request, int $id): Product
    {
        return ShopController::shopOf($request)->products()->findOrFail($id);
    }

    private function validated(Request $request, bool $partial = false, ?Product $current = null): array
    {
        $sometimes = $partial ? ['sometimes'] : [];
        $price = $request->input('price_cents', $current?->price_cents);

        return $request->validate([
            'name' => [...$sometimes, 'required', 'string', 'min:2', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
            'origin' => ['nullable', 'string', 'max:100'],
            'unit' => ['nullable', 'string', 'max:100'],
            'category' => [...$sometimes, 'required', Rule::in(ShopController::shopOf($request)->kind->productCategories())],
            'price_cents' => [...$sometimes, 'required', 'integer', 'between:1,10000000'],
            // Prix barre : doit etre superieur au prix de vente pour afficher une promo.
            'compare_at_price_cents' => ['nullable', 'integer', 'gt:'.(int) $price, 'max:10000000'],
            'track_stock' => ['sometimes', 'boolean'],
            'stock_quantity' => ['sometimes', 'integer', 'between:0,1000000'],
            'low_stock_threshold' => ['sometimes', 'integer', 'between:0,1000000'],
            'is_active' => ['sometimes', 'boolean'],
            // Plats : plat du jour, piment (0 doux … 3 très fort), végétarien, temps de préparation propre.
            'is_featured' => ['sometimes', 'boolean'],
            'spice_level' => ['sometimes', 'integer', 'between:0,3'],
            'is_vegetarian' => ['sometimes', 'boolean'],
            'prep_minutes' => ['nullable', 'integer', 'between:1,240'],
        ]);
    }
}
