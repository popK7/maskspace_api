<?php

namespace App\Http\Controllers\Api\V1\Merchant;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class StatsController extends Controller
{
    /**
     * GET /merchant/stats?from=2026-10-01&to=2026-10-31  (defaut : 30 derniers jours)
     * CA = valeur des produits livres apres remise (hors frais de livraison, reverses au livreur).
     */
    public function __invoke(Request $request): JsonResponse
    {
        $shop = ShopController::shopOf($request);
        $f = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $from = Carbon::parse($f['from'] ?? now()->subDays(29))->startOfDay();
        $to = Carbon::parse($f['to'] ?? now())->endOfDay();

        $delivered = Order::where('shop_id', $shop->id)->where('status', OrderStatus::Delivered)
            ->whereBetween('delivered_at', [$from, $to]);

        $totals = (clone $delivered)->selectRaw(
            'COUNT(*) as n, COALESCE(SUM(subtotal_cents - discount_cents),0) as revenue, COALESCE(SUM(commission_cents),0) as commission'
        )->first();

        $byStatus = Order::where('shop_id', $shop->id)->whereBetween('created_at', [$from, $to])
            ->selectRaw('status, COUNT(*) as n')->groupBy('status')->pluck('n', 'status');

        $daily = (clone $delivered)
            ->selectRaw('DATE(delivered_at) as day, COUNT(*) as n, SUM(subtotal_cents - discount_cents) as revenue')
            ->groupBy('day')->orderBy('day')->get()
            ->map(fn ($r) => ['date' => (string) $r->day, 'orders' => (int) $r->n, 'revenue_cents' => (int) $r->revenue]);

        $top = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.shop_id', $shop->id)->where('orders.status', OrderStatus::Delivered->value)
            ->whereBetween('orders.delivered_at', [$from, $to])
            ->selectRaw('order_items.product_id, order_items.name, SUM(order_items.quantity) as quantity, SUM(order_items.total_cents) as revenue')
            ->groupBy('order_items.product_id', 'order_items.name')
            ->orderByDesc('quantity')->limit(5)->get()
            ->map(fn ($r) => [
                'product_id' => $r->product_id, 'name' => $r->name,
                'quantity' => (int) $r->quantity, 'revenue_cents' => (int) $r->revenue,
            ]);

        $today = Order::where('shop_id', $shop->id)->where('status', OrderStatus::Delivered)
            ->whereBetween('delivered_at', [now()->startOfDay(), now()->endOfDay()])
            ->selectRaw('COUNT(*) as n, COALESCE(SUM(subtotal_cents - discount_cents),0) as revenue')->first();

        // Hier : sert a afficher la tendance « vs hier » sur le tableau de bord.
        $yesterday = Order::where('shop_id', $shop->id)->where('status', OrderStatus::Delivered)
            ->whereBetween('delivered_at', [now()->subDay()->startOfDay(), now()->subDay()->endOfDay()])
            ->selectRaw('COUNT(*) as n, COALESCE(SUM(subtotal_cents - discount_cents),0) as revenue')->first();

        $revenue = (int) $totals->revenue;
        $commission = (int) $totals->commission;

        return response()->json(['data' => [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'delivered_count' => (int) $totals->n,
            'revenue_cents' => $revenue,
            'commission_cents' => $commission,
            'net_cents' => $revenue - $commission,
            'average_order_cents' => $totals->n > 0 ? intdiv($revenue, (int) $totals->n) : 0,
            'orders_by_status' => $byStatus,
            'daily' => $daily->values(),
            'top_products' => $top->values(),
            'today' => ['orders' => (int) $today->n, 'revenue_cents' => (int) $today->revenue],
            'yesterday' => ['orders' => (int) $yesterday->n, 'revenue_cents' => (int) $yesterday->revenue],
            'pending_count' => Order::where('shop_id', $shop->id)->where('status', OrderStatus::Pending)->count(),
            'low_stock_count' => $shop->products()->where('is_active', true)->where('track_stock', true)
                ->whereColumn('stock_quantity', '<=', 'low_stock_threshold')->count(),
        ]]);
    }
}
