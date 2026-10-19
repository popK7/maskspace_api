<?php

namespace App\Http\Controllers\Api\V1\Merchant;

use App\Enums\FulfillmentType;
use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Services\Shop\OrderService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/** Commandes recues par la boutique. */
class OrderController extends Controller
{
    /** Action URL => statut cible. */
    private const ACTIONS = [
        'accept' => OrderStatus::Accepted,
        'reject' => OrderStatus::Rejected,
        'prepare' => OrderStatus::Preparing,
        'ready' => OrderStatus::Ready,
        'complete' => OrderStatus::Delivered,   // retrait en boutique (code de remise requis)
        'cancel' => OrderStatus::Cancelled,
    ];

    public function __construct(private readonly OrderService $orders) {}

    /** GET /merchant/orders?status=&fulfillment_type=&from=&to= */
    public function index(Request $request): AnonymousResourceCollection
    {
        $shop = ShopController::shopOf($request);
        $f = $request->validate([
            'status' => ['nullable', Rule::enum(OrderStatus::class)],
            'active' => ['nullable', 'boolean'],
            'fulfillment_type' => ['nullable', Rule::enum(FulfillmentType::class)],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $orders = Order::with(['items', 'shop', 'customer', 'livreur', 'offeredLivreur'])->where('shop_id', $shop->id)
            ->when($f['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($f['active'] ?? false, fn ($q) => $q->whereIn('status', OrderStatus::active()))
            ->when($f['fulfillment_type'] ?? null, fn ($q, $t) => $q->where('fulfillment_type', $t))
            ->when($f['from'] ?? null, fn ($q, $d) => $q->where('created_at', '>=', \Illuminate\Support\Carbon::parse($d)->startOfDay()))
            ->when($f['to'] ?? null, fn ($q, $d) => $q->where('created_at', '<=', \Illuminate\Support\Carbon::parse($d)->endOfDay()))
            ->latest('id')->paginate($f['per_page'] ?? 30)->withQueryString();

        return OrderResource::collection($orders);
    }

    /** GET /merchant/orders/{order} */
    public function show(Request $request, int $order): OrderResource
    {
        return new OrderResource($this->owned($request, $order));
    }

    /**
     * POST /merchant/orders/{order}/{accept|reject|prepare|ready|complete|cancel}
     * Corps selon l'action : accept { prep_minutes? } · reject/cancel { reason } · complete { pin }
     */
    public function act(Request $request, int $order, string $action): OrderResource
    {
        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:250'],
            'prep_minutes' => ['nullable', 'integer', 'between:1,240'],
            'pin' => ['nullable', 'string', 'digits:4'],
        ]);

        $model = $this->owned($request, $order);

        return new OrderResource($this->orders->transition($model, self::ACTIONS[$action], $request->user(), $data));
    }

    /**
     * POST /merchant/orders/{order}/dispatch
     * { mode: "open" }                        recherche ouverte a tous les livreurs de la plateforme
     * { mode: "courier", livreur_id: <id> }   proposition a l'un de mes livreurs habituels
     */
    public function dispatch(Request $request, int $order): OrderResource
    {
        $data = $request->validate([
            'mode' => ['required', Rule::in(['open', 'courier'])],
            'livreur_id' => ['required_if:mode,courier', 'nullable', 'integer'],
        ]);

        $model = $this->owned($request, $order);
        $updated = $data['mode'] === 'courier'
            ? $this->orders->offerToCourier($model, $request->user(), (int) $data['livreur_id'])
            : $this->orders->openToAll($model, $request->user());

        return new OrderResource($updated);
    }

    private function owned(Request $request, int $id): Order
    {
        $shop = ShopController::shopOf($request);

        return Order::with(['items', 'shop', 'customer', 'livreur', 'offeredLivreur', 'histories', 'review'])
            ->where('shop_id', $shop->id)->findOrFail($id);
    }
}
