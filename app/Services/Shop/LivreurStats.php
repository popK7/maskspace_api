<?php

namespace App\Services\Shop;

use App\Enums\FulfillmentType;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Review;
use App\Models\User;
use Illuminate\Support\Carbon;

/** Chiffres de l'espace livreur : gains, notes, activite. */
class LivreurStats
{
    /**
     * Note moyenne donnee par les clients, par livreur.
     *
     * @param  list<int>  $ids
     * @return array<int, array{avg: float, count: int}>
     */
    public function ratings(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return Review::whereIn('livreur_id', $ids)->whereNotNull('courier_rating')
            ->selectRaw('livreur_id, AVG(courier_rating) as avg, COUNT(*) as n')->groupBy('livreur_id')->get()
            ->mapWithKeys(fn ($r) => [(int) $r->livreur_id => ['avg' => round((float) $r->avg, 2), 'count' => (int) $r->n]])
            ->all();
    }

    /** @return array{avg: float, count: int} */
    public function rating(User $livreur): array
    {
        return $this->ratings([$livreur->id])[$livreur->id] ?? ['avg' => 0.0, 'count' => 0];
    }

    /** @return array{deliveries: int, earnings_cents: int, cash_cents: int} */
    public function totals(User $livreur, ?Carbon $from = null, ?Carbon $to = null): array
    {
        $r = Order::where('livreur_id', $livreur->id)->where('status', OrderStatus::Delivered)
            ->when($from, fn ($q) => $q->where('delivered_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('delivered_at', '<=', $to))
            ->selectRaw('COUNT(*) as n, COALESCE(SUM(delivery_fee_cents),0) as fees, COALESCE(SUM(total_cents),0) as cash')->first();

        return ['deliveries' => (int) $r->n, 'earnings_cents' => (int) $r->fees, 'cash_cents' => (int) $r->cash];
    }

    /**
     * Une ligne par jour (zeros inclus) entre $from et $to.
     *
     * @return list<array{date: string, deliveries: int, earnings_cents: int}>
     */
    public function daily(User $livreur, Carbon $from, Carbon $to): array
    {
        $byDay = Order::where('livreur_id', $livreur->id)->where('status', OrderStatus::Delivered)
            ->whereBetween('delivered_at', [$from, $to])->get(['delivered_at', 'delivery_fee_cents'])
            ->groupBy(fn (Order $o) => $o->delivered_at->toDateString());

        $rows = [];
        for ($d = $from->copy()->startOfDay(); $d->lte($to); $d->addDay()) {
            $orders = $byDay->get($d->toDateString(), collect());
            $rows[] = [
                'date' => $d->toDateString(),
                'deliveries' => $orders->count(),
                'earnings_cents' => (int) $orders->sum('delivery_fee_cents'),
            ];
        }

        return $rows;
    }

    /** Nombre de courses libres que ce livreur peut prendre, dont celles qui lui sont reservees. */
    public function availableCounts(User $livreur): array
    {
        $rows = Order::visibleToLivreur($livreur->id)
            ->where('fulfillment_type', FulfillmentType::Delivery)
            ->whereIn('status', [OrderStatus::Preparing, OrderStatus::Ready])
            ->get(['id', 'offered_livreur_id', 'offer_expires_at', 'livreur_id']);

        return [
            'available' => $rows->count(),
            'reserved' => $rows->filter(fn (Order $o) => $o->hasActiveOffer() && $o->offered_livreur_id === $livreur->id)->count(),
        ];
    }
}
