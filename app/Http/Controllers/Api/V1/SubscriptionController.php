<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Subscriptions\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubscriptionController extends Controller
{
    public function __construct(private readonly SubscriptionService $subscriptions) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->subscriptions->overview($request->user())]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'plan' => ['required', 'string', 'max:40'],
        ]);

        $receipt = $this->subscriptions->request($request->user(), $data['plan']);

        return response()->json(['data' => $receipt], 201);
    }
}
