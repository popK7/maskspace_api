<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\SubscriptionReceipt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SubscriptionIndexController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['pending', 'active', 'exhausted'])],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $receipts = SubscriptionReceipt::query()
            ->with(['user:id,name,email,role,status'])
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->latest('id')
            ->paginate($filters['per_page'] ?? 50)
            ->through(fn (SubscriptionReceipt $receipt): array => [
                'id' => $receipt->id,
                'receipt_number' => $receipt->receipt_number,
                'plan' => $receipt->plan,
                'plan_name' => $receipt->plan_name,
                'order_limit' => $receipt->order_limit,
                'status' => $receipt->status,
                'created_at' => $receipt->created_at?->toIso8601String(),
                'activated_at' => $receipt->activated_at?->toIso8601String(),
                'user' => $receipt->user?->only(['id', 'name', 'email', 'role', 'status']),
            ]);

        return response()->json($receipts);
    }
}
