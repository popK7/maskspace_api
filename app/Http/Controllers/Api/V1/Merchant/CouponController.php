<?php

namespace App\Http\Controllers\Api\V1\Merchant;

use App\Enums\CouponType;
use App\Http\Controllers\Controller;
use App\Models\Coupon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Codes promo de la boutique. */
class CouponController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $coupons = ShopController::shopOf($request)->coupons()->latest('id')->get();

        return response()->json(['data' => $coupons->map(fn (Coupon $c) => $this->present($c))->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $shop = ShopController::shopOf($request);
        $coupon = $shop->coupons()->create($this->validated($request, $shop->id));

        return response()->json(['data' => $this->present($coupon->refresh())], 201);
    }

    public function update(Request $request, int $coupon): JsonResponse
    {
        $shop = ShopController::shopOf($request);
        $model = $shop->coupons()->findOrFail($coupon);
        $model->fill($this->validated($request, $shop->id, $model))->save();

        return response()->json(['data' => $this->present($model)]);
    }

    public function destroy(Request $request, int $coupon): Response
    {
        ShopController::shopOf($request)->coupons()->findOrFail($coupon)->delete();

        return response()->noContent();
    }

    private function validated(Request $request, int $shopId, ?Coupon $current = null): array
    {
        if (is_string($request->code)) {
            $request->merge(['code' => strtoupper(trim($request->code))]);
        }

        $partial = $current ? ['sometimes'] : [];
        $type = $request->input('type', $current?->type?->value);

        $data = $request->validate([
            'code' => [...$partial, 'required', 'string', 'alpha_dash:ascii', 'min:3', 'max:32',
                Rule::unique('coupons', 'code')->where('shop_id', $shopId)->ignore($current?->id)],
            'type' => [...$partial, 'required', Rule::enum(CouponType::class)],
            'value' => [...$partial, 'required', 'integer', $type === 'percent' ? 'between:1,100' : 'between:1,10000000'],
            'min_order_cents' => ['sometimes', 'integer', 'between:0,10000000'],
            'max_uses' => ['nullable', 'integer', 'min:1'],
            'starts_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after:starts_at'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if ($current && isset($data['max_uses']) && $data['max_uses'] < $current->uses_count) {
            throw ValidationException::withMessages(['max_uses' => ['Inférieur au nombre d\'utilisations déjà effectuées.']]);
        }

        return $data;
    }

    private function present(Coupon $c): array
    {
        return [
            'id' => $c->id,
            'code' => $c->code,
            'type' => $c->type->value,
            'value' => $c->value,
            'min_order_cents' => $c->min_order_cents,
            'max_uses' => $c->max_uses,
            'uses_count' => $c->uses_count,
            'starts_at' => $c->starts_at?->toIso8601String(),
            'expires_at' => $c->expires_at?->toIso8601String(),
            'is_active' => $c->is_active,
        ];
    }
}
