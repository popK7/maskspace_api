<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\Subscriptions\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubscriptionController extends Controller
{
    public function __construct(private readonly SubscriptionService $subscriptions) {}

    public function activate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'receipt_number' => ['required', 'string', 'max:20'],
            'amount_paid_usd' => ['required', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'],
        ]);

        $receipt = $this->subscriptions->activate(
            $data['receipt_number'],
            $request->user(),
            (string) $data['amount_paid_usd'],
        );

        return response()->json(['data' => $receipt]);
    }
}
