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
        ]);

        $receipt = $this->subscriptions->activate($data['receipt_number'], $request->user());

        return response()->json(['data' => $receipt]);
    }
}
