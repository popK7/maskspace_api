<?php

namespace App\Http\Controllers\Api\V1\Shop;

use App\Enums\FulfillmentType;
use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Shop\OrderRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Models\Shop;
use App\Services\Shop\OrderPricing;
use App\Services\Shop\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/** Commandes cote CLIENT (role `client`). */
class OrderController extends Controller
{
    public function __construct(private readonly OrderService $orders) {}

    /** POST /orders/quote — previsualise le total (promo, livraison) sans rien creer. */
    public function quote(OrderRequest $request, OrderPricing $pricing): JsonResponse
    {
        $d = $request->validated();
        $priced = $pricing->compute(
            Shop::findOrFail($d['shop_id']), $d['items'], FulfillmentType::from($d['fulfillment_type']),
            $d['coupon_code'] ?? null, $request->user(), $d['delivery_latitude'] ?? null, $d['delivery_longitude'] ?? null,
        );

        return response()->json(['data' => [
            'lines' => $priced['lines']->map(fn ($l) => [
                'product_id' => $l['product']->id,
                'name' => $l['product']->name,
                'unit_price_cents' => $l['product']->price_cents,
                'quantity' => $l['quantity'],
                'total_cents' => $l['total'],
            ])->values(),
            'subtotal_cents' => $priced['subtotal'],
            'discount_cents' => $priced['discount'],
            'delivery_fee_cents' => $priced['delivery_fee'],
            'total_cents' => $priced['total'],
            'coupon_code' => $priced['coupon']?->code,
        ]]);
    }

    /**
     * POST /orders — 201. En-tete optionnel `Idempotency-Key` : rejouer la meme requete renvoie
     * la commande existante (200) au lieu d'en creer une seconde (double appui, reseau instable).
     */
    public function store(OrderRequest $request): JsonResponse
    {
        $key = $request->header('Idempotency-Key');
        abort_if($key !== null && strlen($key) > 64, 422, 'Idempotency-Key trop long (64 caractères max).');

        ['order' => $order, 'created' => $created] = $this->orders->create($request->user(), $request->validated(), $key);

        return (new OrderResource($order->load(['items', 'shop', 'histories'])))
            ->response()->setStatusCode($created ? 201 : 200);
    }

    /** GET /orders?status= — mes commandes. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $f = $request->validate([
            'status' => ['nullable', Rule::enum(OrderStatus::class)],
            'active' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'between:1,50'],
        ]);

        $orders = Order::with(['items', 'shop'])->where('customer_id', $request->user()->id)
            ->when($f['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($f['active'] ?? null, fn ($q) => $q->whereIn('status', OrderStatus::active()))
            ->latest('id')->paginate($f['per_page'] ?? 20)->withQueryString();

        return OrderResource::collection($orders);
    }

    /** GET /orders/{order} */
    public function show(Request $request, int $order): OrderResource
    {
        return new OrderResource($this->mine($request, $order));
    }

    /** POST /orders/{order}/cancel — possible tant que la boutique n'a pas accepte. */
    public function cancel(Request $request, int $order): OrderResource
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:250']]);
        $mine = $this->mine($request, $order);

        return new OrderResource($this->orders->transition($mine, OrderStatus::Cancelled, $request->user(), $data));
    }

    /** POST /orders/{order}/review */
    public function review(Request $request, int $order): JsonResponse
    {
        $data = $request->validate([
            'rating' => ['required', 'integer', 'between:1,5'],
            'comment' => ['nullable', 'string', 'max:1000'],
            'courier_rating' => ['nullable', 'integer', 'between:1,5'],
        ]);

        $review = $this->orders->review(
            $this->mine($request, $order), $request->user(), $data['rating'], $data['comment'] ?? null, $data['courier_rating'] ?? null,
        );

        return response()->json(['data' => [
            'id' => $review->id, 'rating' => $review->rating, 'courier_rating' => $review->courier_rating, 'comment' => $review->comment,
        ]], 201);
    }

    /** Un client ne peut jamais voir la commande d'un autre : 404 (et non 403) pour ne rien reveler. */
    private function mine(Request $request, int $id): Order
    {
        return Order::with(['items', 'shop', 'histories', 'livreur', 'review'])
            ->where('customer_id', $request->user()->id)->findOrFail($id);
    }
}
