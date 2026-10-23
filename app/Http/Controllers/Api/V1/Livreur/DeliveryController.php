<?php

namespace App\Http\Controllers\Api\V1\Livreur;

use App\Enums\FulfillmentType;
use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Models\Shop;
use App\Services\Shop\LivreurStats;
use App\Services\Shop\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;

/** Courses du livreur (role `livreur`). */
class DeliveryController extends Controller
{
    public function __construct(private readonly OrderService $orders, private readonly LivreurStats $stats) {}

    /**
     * GET /livreur/deliveries/available?lat=&lng=
     * Courses a prendre. Les donnees du client (nom, telephone, adresse) ne sont revelees
     * qu'apres la prise en charge ; avant, seule la boutique et le gain sont visibles.
     */
    public function available(Request $request): JsonResponse
    {
        $f = $request->validate([
            'lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:lng'],
            'lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:lat'],
        ]);

        $me = $request->user()->id;

        // Hors service : aucune course proposee.
        if (! $request->user()->hasCompletedProfile()
            || ! $request->user()->is_available
            || ! $request->user()->is_visible) {
            return response()->json(['data' => [], 'meta' => ['is_available' => false]]);
        }

        $rows = Order::with(['shop', 'items'])->visibleToLivreur($me)
            ->where('fulfillment_type', FulfillmentType::Delivery)
            ->whereIn('status', [OrderStatus::Preparing, OrderStatus::Ready])
            ->oldest('id')->limit(100)->get()
            ->map(function (Order $o) use ($f, $me) {
                $shop = $o->shop;
                $distance = isset($f['lat'], $f['lng']) && $shop->hasLocation()
                    ? round(Shop::haversineKm($f['lat'], $f['lng'], $shop->latitude, $shop->longitude), 2) : null;

                return [
                    'id' => $o->id,
                    'number' => $o->number,
                    'status' => $o->status->value,
                    'shop' => [
                        'id' => $shop->id, 'name' => $shop->name, 'address' => $shop->address,
                        'latitude' => $shop->latitude, 'longitude' => $shop->longitude,
                    ],
                    'items_count' => $o->items->sum('quantity'),
                    'earning_cents' => $o->delivery_fee_cents,        // gain du livreur
                    'cash_to_collect_cents' => $o->total_cents,        // paiement a la livraison
                    'distance_to_shop_km' => $distance,
                    // Longueur approximative du trajet boutique -> client (a vol d'oiseau), sans reveler l'adresse.
                    'trip_km' => $shop->hasLocation() && $o->delivery_latitude !== null && $o->delivery_longitude !== null
                        ? round(Shop::haversineKm($shop->latitude, $shop->longitude, $o->delivery_latitude, $o->delivery_longitude), 2) : null,
                    'prep_minutes' => $o->prep_minutes,
                    // Course proposee en priorite par la boutique a CE livreur (delai de reponse limite).
                    'reserved_for_me' => $o->hasActiveOffer() && $o->offered_livreur_id === $me,
                    'offer_expires_at' => $o->hasActiveOffer() && $o->offered_livreur_id === $me
                        ? $o->offer_expires_at->toIso8601String() : null,
                    'created_at' => $o->created_at?->toIso8601String(),
                ];
            });

        // Les courses qui me sont reservees d'abord, puis par proximite si la position est connue.
        $rows = $rows->sortBy([
            fn ($a, $b) => (int) $b['reserved_for_me'] <=> (int) $a['reserved_for_me'],
            fn ($a, $b) => ($a['distance_to_shop_km'] ?? INF) <=> ($b['distance_to_shop_km'] ?? INF),
        ]);

        return response()->json(['data' => $rows->values(), 'meta' => ['is_available' => true]]);
    }

    /**
     * GET /livreur/dashboard — tout l'accueil livreur en un appel.
     */
    public function dashboard(Request $request): JsonResponse
    {
        $user = $request->user();
        $today = $this->stats->totals($user, now()->startOfDay(), now()->endOfDay());
        $lifetime = $this->stats->totals($user);
        $counts = $user->is_available ? $this->stats->availableCounts($user) : ['available' => 0, 'reserved' => 0];
        $rating = $this->stats->rating($user);

        $active = Order::where('livreur_id', $user->id)
            ->whereIn('status', [OrderStatus::Preparing, OrderStatus::Ready, OrderStatus::OutForDelivery])->count();

        return response()->json(['data' => [
            'is_available' => (bool) $user->is_available,
            'today' => $today,
            'lifetime' => $lifetime,
            'rating' => $rating,
            'active_count' => $active,
            'available_count' => $counts['available'],
            'reserved_count' => $counts['reserved'],
            'member_since' => $user->created_at?->toDateString(),
        ]]);
    }

    /** PATCH /livreur/availability { is_available } — se mettre en service / hors service. */
    public function availability(Request $request): JsonResponse
    {
        $data = $request->validate(['is_available' => ['required', 'boolean']]);

        $user = $request->user();
        if ($data['is_available']) {
            $user->ensureVisible();
        }
        $user->forceFill(['is_available' => (bool) $data['is_available']])->save();

        return response()->json(['data' => ['is_available' => (bool) $user->is_available]]);
    }

    /** GET /livreur/deliveries?active=1&status= — mes courses. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $f = $request->validate([
            'active' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'between:1,50'],
        ]);

        $orders = Order::with(['items', 'shop', 'customer'])->where('livreur_id', $request->user()->id)
            ->when($f['active'] ?? null, fn ($q) => $q->whereIn('status', [OrderStatus::Preparing, OrderStatus::Ready, OrderStatus::OutForDelivery]))
            ->latest('id')->paginate($f['per_page'] ?? 20)->withQueryString();

        return OrderResource::collection($orders);
    }

    /** GET /livreur/deliveries/{order} — uniquement mes courses. */
    public function show(Request $request, int $order): OrderResource
    {
        return new OrderResource($this->mine($request, $order));
    }

    /** POST /livreur/deliveries/{order}/accept — prise en charge atomique (409 si deja prise). */
    public function accept(Request $request, int $order): OrderResource
    {
        $model = Order::findOrFail($order);

        $claimed = $this->orders->claim($model, $request->user());

        return new OrderResource($claimed->load(['customer', 'livreur']));
    }

    /** POST /livreur/deliveries/{order}/decline — decline une course reservee : elle repasse en recherche ouverte. */
    public function decline(Request $request, int $order): JsonResponse
    {
        $this->orders->declineOffer(Order::findOrFail($order), $request->user());

        return response()->json(['message' => 'Proposition déclinée.']);
    }

    /** POST /livreur/deliveries/{order}/release */
    public function release(Request $request, int $order): OrderResource
    {
        return new OrderResource($this->orders->release($this->mine($request, $order), $request->user()));
    }

    /** POST /livreur/deliveries/{order}/pickup — recuperee a la boutique, en route. */
    public function pickup(Request $request, int $order): OrderResource
    {
        $updated = $this->orders->transition($this->mine($request, $order), OrderStatus::OutForDelivery, $request->user());

        return new OrderResource($updated->load(['customer', 'livreur']));
    }

    /** POST /livreur/deliveries/{order}/deliver { pin } — le client donne son code de remise. */
    public function deliver(Request $request, int $order): OrderResource
    {
        $data = $request->validate(['pin' => ['required', 'string', 'digits:4']]);

        $updated = $this->orders->transition($this->mine($request, $order), OrderStatus::Delivered, $request->user(), $data);

        return new OrderResource($updated->load(['customer', 'livreur']));
    }

    /** GET /livreur/earnings?from=&to= */
    public function earnings(Request $request): JsonResponse
    {
        $f = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);
        $from = Carbon::parse($f['from'] ?? now()->subDays(29))->startOfDay();
        $to = Carbon::parse($f['to'] ?? now())->endOfDay();

        if ($from->diffInDays($to) > 92) {
            $from = $to->copy()->subDays(92)->startOfDay();
        }

        $user = $request->user();
        $r = $this->stats->totals($user, $from, $to);
        $daily = $this->stats->daily($user, $from, $to);
        $best = collect($daily)->sortByDesc('earnings_cents')->first();

        return response()->json(['data' => [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'deliveries_count' => $r['deliveries'],
            'earnings_cents' => $r['earnings_cents'],
            'cash_collected_cents' => $r['cash_cents'],
            'average_cents' => $r['deliveries'] > 0 ? intdiv($r['earnings_cents'], $r['deliveries']) : 0,
            'best_day' => $best && $best['earnings_cents'] > 0 ? $best : null,
            'daily' => $daily,
            'lifetime_deliveries' => $this->stats->totals($user)['deliveries'],
            'rating' => $this->stats->rating($user),
        ]]);
    }

    private function mine(Request $request, int $id): Order
    {
        return Order::with(['items', 'shop', 'customer', 'livreur', 'histories'])
            ->where('livreur_id', $request->user()->id)->findOrFail($id);
    }
}
