<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\SubscriptionReceipt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SubscriptionFinanceReportController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $from = $filters['from'].' 00:00:00';
        $to = $filters['to'].' 23:59:59.999999';
        $periodReceipts = SubscriptionReceipt::query()
            ->whereNotNull('activated_at')
            ->whereBetween('activated_at', [$from, $to]);
        $paidReceipts = (clone $periodReceipts)->whereNotNull('amount_paid_usd');
        $total = (clone $paidReceipts)->sum('amount_paid_usd');

        $daily = (clone $paidReceipts)
            ->selectRaw('DATE(activated_at) as date, COUNT(*) as count, SUM(amount_paid_usd) as total_usd')
            ->groupBy(DB::raw('DATE(activated_at)'))
            ->orderBy('date')
            ->get()
            ->map(fn ($row): array => [
                'date' => $row->date,
                'count' => (int) $row->count,
                'total_usd' => number_format((float) $row->total_usd, 2, '.', ''),
            ]);

        $receipts = (clone $paidReceipts)
            ->with('user:id,name,email')
            ->latest('activated_at')
            ->limit(100)
            ->get()
            ->map(fn (SubscriptionReceipt $receipt): array => [
                'receipt_number' => $receipt->receipt_number,
                'plan_name' => $receipt->plan_name,
                'amount_paid_usd' => $receipt->amount_paid_usd,
                'activated_at' => $receipt->activated_at?->toIso8601String(),
                'user' => $receipt->user?->only(['name', 'email']),
            ]);

        return response()->json(['data' => [
            'period' => ['from' => $filters['from'], 'to' => $filters['to']],
            'summary' => [
                'total_usd' => number_format((float) $total, 2, '.', ''),
                'subscription_count' => (clone $paidReceipts)->count(),
                'unrecorded_count' => (clone $periodReceipts)->whereNull('amount_paid_usd')->count(),
            ],
            'daily' => $daily,
            'receipts' => $receipts,
            'receipts_truncated' => (clone $paidReceipts)->count() > 100,
        ]]);
    }
}
