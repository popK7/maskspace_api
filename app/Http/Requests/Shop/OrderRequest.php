<?php

namespace App\Http\Requests\Shop;

use App\Enums\FulfillmentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Validation commune a POST /orders/quote et POST /orders. */
class OrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->coupon_code)) {
            $this->merge(['coupon_code' => strtoupper(trim($this->coupon_code))]);
        }
    }

    public function rules(): array
    {
        $delivery = $this->input('fulfillment_type') === FulfillmentType::Delivery->value;

        return [
            'shop_id' => ['required', 'integer', Rule::exists('shops', 'id')->where('is_active', true)->whereNull('deleted_at')],
            'fulfillment_type' => ['required', Rule::enum(FulfillmentType::class)],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'between:1,99'],
            'coupon_code' => ['nullable', 'string', 'max:32'],
            'delivery_address' => [Rule::requiredIf($delivery), 'nullable', 'string', 'max:255'],
            'delivery_latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:delivery_longitude'],
            'delivery_longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:delivery_latitude'],
            'customer_phone' => ['nullable', 'string', 'regex:/^\+?[0-9\s().-]{6,20}$/'],
            'customer_note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
