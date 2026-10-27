<?php

namespace App\Http\Controllers\Api\V1\Merchant;

use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\ShopCourier;
use App\Models\User;
use App\Services\Shop\LivreurStats;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Livreurs habituels de la boutique (ceux avec qui elle a l'habitude de travailler). */
class CourierController extends Controller
{
    private const MAX_TRUSTED = 20;

    /** @var array<int, array{avg: float, count: int}> */
    private array $ratings = [];

    public function __construct(private readonly LivreurStats $stats) {}

    /** @param list<int> $ids */
    private function prime(array $ids): void
    {
        $this->ratings = $this->stats->ratings($ids);
    }

    /**
     * GET /merchant/couriers
     * `data` : mes livreurs habituels · `recent` : livreurs ayant deja livre pour moi, pas encore enregistres.
     */
    public function index(Request $request): JsonResponse
    {
        $shop = ShopController::shopOf($request);

        $saved = ShopCourier::with('livreur.profile')->where('shop_id', $shop->id)->latest('id')->get()
            ->filter(fn (ShopCourier $c) => $c->livreur !== null);

        $ids = $saved->pluck('livreur_id');
        $delivered = $this->deliveredCounts($shop->id, $ids->all());
        $busy = $this->activeCounts($ids->all());
        $this->prime($ids->all());

        $data = $saved->map(fn (ShopCourier $c) => $this->payload(
            $c->livreur, true, $delivered[$c->livreur_id] ?? 0, $busy[$c->livreur_id] ?? 0,
        ))->values();

        $recentIds = Order::where('shop_id', $shop->id)->where('status', OrderStatus::Delivered)
            ->whereNotNull('livreur_id')->whereNotIn('livreur_id', $ids)
            ->selectRaw('livreur_id, MAX(id) as last_id')->groupBy('livreur_id')
            ->orderByDesc('last_id')->limit(5)->pluck('livreur_id')->all();

        $recentDelivered = $this->deliveredCounts($shop->id, $recentIds);
        $recentBusy = $this->activeCounts($recentIds);
        $this->ratings += $this->stats->ratings($recentIds);
        $recent = User::with('profile')->whereIn('id', $recentIds)->where('status', UserStatus::Active)->get()
            ->sortBy(fn (User $u) => array_search($u->id, $recentIds, true))
            ->map(fn (User $u) => $this->payload($u, false, $recentDelivered[$u->id] ?? 0, $recentBusy[$u->id] ?? 0))
            ->values();

        return response()->json(['data' => $data, 'recent' => $recent]);
    }

    /**
     * GET /merchant/couriers/search?q=
     * Recherche un livreur de la plateforme par nom, telephone exact ou e-mail exact.
     * (Pas de liste complete : on evite d'exposer l'annuaire des livreurs.)
     */
    public function search(Request $request): JsonResponse
    {
        $shop = ShopController::shopOf($request);
        $q = trim((string) $request->validate(['q' => ['required', 'string', 'min:3', 'max:100']])['q']);
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $q).'%';
        $digits = preg_replace('/\D+/', '', $q) ?? '';

        $users = User::forRole(UserRole::Livreur)->where('status', UserStatus::Active)
            ->where(function ($w) use ($like, $q, $digits) {
                $w->where('name', 'like', $like)->orWhere('email', $q);
                if (strlen($digits) >= 6) {
                    $w->orWhere('phone', $q)->orWhere('phone', $digits)->orWhere('phone', '+'.$digits);
                }
            })
            ->with('profile')->orderBy('name')->limit(10)->get();
        $this->prime($users->pluck('id')->all());

        $savedIds = ShopCourier::where('shop_id', $shop->id)->pluck('livreur_id')->all();
        $delivered = $this->deliveredCounts($shop->id, $users->pluck('id')->all());
        $busy = $this->activeCounts($users->pluck('id')->all());

        return response()->json(['data' => $users->map(fn (User $u) => $this->payload(
            $u, in_array($u->id, $savedIds, true), $delivered[$u->id] ?? 0, $busy[$u->id] ?? 0, maskPhone: true,
        ))->values()]);
    }

    /** POST /merchant/couriers { livreur_id } — idempotent. */
    public function store(Request $request): JsonResponse
    {
        $shop = ShopController::shopOf($request);
        $data = $request->validate(['livreur_id' => ['required', 'integer']]);

        $livreur = User::forRole(UserRole::Livreur)->where('status', UserStatus::Active)->find($data['livreur_id']);
        if (! $livreur) {
            return response()->json([
                'message' => 'Livreur introuvable.',
                'errors' => ['livreur_id' => ['Livreur introuvable.']],
            ], 422);
        }

        if (ShopCourier::where('shop_id', $shop->id)->count() >= self::MAX_TRUSTED
            && ! ShopCourier::where('shop_id', $shop->id)->where('livreur_id', $livreur->id)->exists()) {
            return response()->json([
                'message' => 'Vous ne pouvez pas enregistrer plus de '.self::MAX_TRUSTED.' livreurs habituels.',
                'errors' => ['livreur_id' => ['Limite atteinte.']],
            ], 422);
        }

        ShopCourier::firstOrCreate(['shop_id' => $shop->id, 'livreur_id' => $livreur->id]);

        $delivered = $this->deliveredCounts($shop->id, [$livreur->id]);
        $busy = $this->activeCounts([$livreur->id]);
        $livreur->load('profile');
        $this->prime([$livreur->id]);

        return response()->json([
            'data' => $this->payload($livreur, true, $delivered[$livreur->id] ?? 0, $busy[$livreur->id] ?? 0),
        ], 201);
    }

    /** DELETE /merchant/couriers/{livreur} — idempotent. Les offres en cours restent valables. */
    public function destroy(Request $request, int $livreur): JsonResponse
    {
        $shop = ShopController::shopOf($request);
        ShopCourier::where('shop_id', $shop->id)->where('livreur_id', $livreur)->delete();

        return response()->json(null, 204);
    }

    /** @return array<string, mixed> */
    private function payload(User $u, bool $saved, int $delivered, int $busy, bool $maskPhone = false): array
    {
        return [
            'id' => $u->id,
            'name' => $u->name,
            'phone' => $maskPhone ? $this->mask($u->phone) : $u->phone,
            'vehicle' => $u->profileForRole(UserRole::Livreur)->first()?->vehicle_model,
            'is_available' => (bool) $u->is_available,
            'rating' => $this->ratings[$u->id] ?? ['avg' => 0.0, 'count' => 0],
            'saved' => $saved,
            'deliveries_for_shop' => $delivered,
            'active_deliveries' => $busy,
        ];
    }

    private function mask(?string $phone): ?string
    {
        if (! $phone) {
            return null;
        }
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        return strlen($digits) > 2 ? '••• •• '.substr($digits, -2) : null;
    }

    /** @param list<int> $ids  @return array<int, int> */
    private function deliveredCounts(int $shopId, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return Order::where('shop_id', $shopId)->where('status', OrderStatus::Delivered)->whereIn('livreur_id', $ids)
            ->selectRaw('livreur_id, COUNT(*) as n')->groupBy('livreur_id')->pluck('n', 'livreur_id')
            ->map(fn ($n) => (int) $n)->all();
    }

    /** Courses en cours par livreur (indique s'il est deja occupe). @param list<int> $ids @return array<int, int> */
    private function activeCounts(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return Order::whereIn('livreur_id', $ids)
            ->whereIn('status', [OrderStatus::Preparing, OrderStatus::Ready, OrderStatus::OutForDelivery])
            ->selectRaw('livreur_id, COUNT(*) as n')->groupBy('livreur_id')->pluck('n', 'livreur_id')
            ->map(fn ($n) => (int) $n)->all();
    }
}
