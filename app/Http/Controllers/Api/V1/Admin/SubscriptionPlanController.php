<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\SubscriptionPlan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SubscriptionPlanController extends Controller
{
    public function index(): JsonResponse
    {
        $plans = SubscriptionPlan::query()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return response()->json(['data' => $plans]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'order_limit' => ['present', 'nullable', 'integer', 'between:1,65535'],
            'description' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'between:0,65535'],
        ]);

        $plan = SubscriptionPlan::create([
            ...$data,
            'code' => $this->uniqueCode($data['name']),
            'price_label' => 'Tarif à confirmer au point de vente',
            'is_active' => $data['is_active'] ?? true,
            'sort_order' => $data['sort_order'] ?? 0,
        ]);

        return response()->json(['data' => $plan], 201);
    }

    public function update(Request $request, SubscriptionPlan $subscriptionPlan): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:80'],
            'order_limit' => ['sometimes', 'present', 'nullable', 'integer', 'between:1,65535'],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'between:0,65535'],
        ]);

        $subscriptionPlan->fill($data)->save();

        return response()->json(['data' => $subscriptionPlan->fresh()]);
    }

    private function uniqueCode(string $name): string
    {
        $base = Str::limit(Str::slug($name, '_'), 32, '');
        $base = $base !== '' ? $base : 'plan';
        $code = $base;
        $suffix = 2;
        while (SubscriptionPlan::query()->where('code', $code)->exists()) {
            $code = $base.'_'.($suffix++);
        }

        return $code;
    }
}
