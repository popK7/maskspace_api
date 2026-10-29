<?php

namespace App\Services\Subscriptions;

use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Exceptions\ApiException;
use App\Models\Order;
use App\Models\Shop;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionReceipt;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SubscriptionService
{
    /**
     * @return array{
     *   plans: list<array{code: string, name: string, order_limit: ?int, price_label: string}>,
     *   receipt: array<string, mixed>|null,
     *   usage: array{completed: int, reserved: int, remaining: ?int}
     * }
     */
    public function overview(User $user): array
    {
        $this->assertEligible($user);

        $receipt = SubscriptionReceipt::query()
            ->where('user_id', $user->id)
            ->whereIn('status', ['pending', 'active'])
            ->latest('id')
            ->first();

        $usage = $receipt?->status === 'active'
            ? $this->usage($user, $receipt)
            : ['completed' => 0, 'reserved' => 0, 'remaining' => 0];

        if ($receipt?->status === 'active'
            && $receipt->order_limit !== null
            && $usage['remaining'] === 0
            && $usage['reserved'] === 0) {
            $receipt->forceFill(['status' => 'exhausted'])->save();
        }

        return [
            'plans' => SubscriptionPlan::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get(['code', 'name', 'order_limit', 'price_label', 'description'])
                ->toArray(),
            'receipt' => $receipt?->only([
                'receipt_number', 'plan', 'plan_name', 'order_limit', 'status', 'activated_at', 'created_at',
            ]),
            'usage' => $usage,
        ];
    }

    public function request(User $user, string $planCode): SubscriptionReceipt
    {
        $this->assertEligible($user);
        $plan = SubscriptionPlan::query()
            ->where('code', $planCode)
            ->where('is_active', true)
            ->first();
        if ($plan === null) {
            throw new ApiException('Le plan choisi est invalide.', 422, 'invalid_subscription_plan');
        }

        return DB::transaction(function () use ($user, $plan): SubscriptionReceipt {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();

            $pending = SubscriptionReceipt::query()
                ->where('user_id', $user->id)
                ->where('status', 'pending')
                ->lockForUpdate()
                ->first();
            if ($pending !== null) {
                throw new ApiException(
                    "Un reçu est déjà en attente d’activation : {$pending->receipt_number}.",
                    409,
                    'subscription_receipt_pending',
                );
            }

            $active = SubscriptionReceipt::query()
                ->where('user_id', $user->id)
                ->where('status', 'active')
                ->lockForUpdate()
                ->first();
            if ($active !== null) {
                $usage = $this->usage($user, $active);
                if ($active->order_limit === null || $usage['remaining'] > 0 || $usage['reserved'] > 0) {
                    throw new ApiException(
                        'Votre abonnement est encore actif. Utilisez vos commandes restantes avant de choisir un nouveau plan.',
                        409,
                        'subscription_already_active',
                    );
                }
                $active->forceFill(['status' => 'exhausted'])->save();
            }

            return SubscriptionReceipt::create([
                'user_id' => $user->id,
                'receipt_number' => $this->newReceiptNumber(),
                'plan' => $plan->code,
                'plan_name' => $plan->name,
                'order_limit' => $plan['order_limit'],
                'status' => 'pending',
            ]);
        });
    }

    public function activate(string $receiptNumber, User $admin): SubscriptionReceipt
    {
        return DB::transaction(function () use ($receiptNumber, $admin): SubscriptionReceipt {
            $receipt = SubscriptionReceipt::query()
                ->where('receipt_number', strtoupper(trim($receiptNumber)))
                ->lockForUpdate()
                ->first();
            if ($receipt === null) {
                throw new ApiException('Reçu introuvable.', 404, 'subscription_receipt_not_found');
            }
            if ($receipt->status !== 'pending') {
                throw new ApiException('Ce reçu a déjà été traité.', 409, 'subscription_receipt_processed');
            }

            $active = SubscriptionReceipt::query()
                ->where('user_id', $receipt->user_id)
                ->where('status', 'active')
                ->lockForUpdate()
                ->first();
            if ($active !== null) {
                $usage = $this->usage($active->user, $active);
                if ($active->order_limit === null || $usage['remaining'] > 0 || $usage['reserved'] > 0) {
                    throw new ApiException('Cet utilisateur possède encore un abonnement actif.', 409, 'subscription_already_active');
                }
                $active->forceFill(['status' => 'exhausted'])->save();
            }

            $receipt->forceFill([
                'status' => 'active',
                'activated_at' => now(),
                'activated_by' => $admin->id,
            ])->save();

            return $receipt->fresh();
        });
    }

    public function assertShopCanReceiveOrder(Shop $shop): void
    {
        $owner = $shop->owner;
        if ($owner === null) {
            throw new ApiException('La boutique ne peut pas recevoir de commande.', 409, 'shop_unavailable');
        }
        $this->assertEligible($owner);

        $receipt = SubscriptionReceipt::query()
            ->where('user_id', $owner->id)
            ->where('status', 'active')
            ->lockForUpdate()
            ->first();
        if ($receipt === null) {
            throw new ApiException(
                'Cette boutique n’accepte pas encore de commandes : son abonnement doit être activé au point de vente.',
                409,
                'subscription_required',
            );
        }
        if ($receipt->order_limit === null) {
            return;
        }

        $usage = $this->usage($owner, $receipt);
        if ($usage['remaining'] <= 0 || $receipt->order_limit <= $usage['reserved'] + $usage['completed']) {
            throw new ApiException(
                'Cette boutique a atteint le quota de commandes de son abonnement.',
                409,
                'subscription_quota_reached',
            );
        }
    }

    /**
     * @return array{completed: int, reserved: int, remaining: ?int}
     */
    private function usage(User $user, SubscriptionReceipt $receipt): array
    {
        $orders = Order::query()->whereHas('shop', fn ($query) => $query
            ->withTrashed()
            ->where('owner_id', $user->id));

        $completed = (clone $orders)
            ->where('status', OrderStatus::Delivered)
            ->where('delivered_at', '>=', $receipt->activated_at)
            ->count();

        $reserved = (clone $orders)
            ->whereIn('status', OrderStatus::active())
            ->count();

        return [
            'completed' => $completed,
            'reserved' => $reserved,
            'remaining' => $receipt->order_limit === null
                ? null
                : max(0, $receipt->order_limit - $completed - $reserved),
        ];
    }

    private function assertEligible(User $user): void
    {
        $hasProfessionalRole = $user->roles()
            ->whereNotIn('role', [UserRole::Client->value, UserRole::Admin->value])
            ->whereNotNull('profile_completed_at')
            ->exists();
        if (! $hasProfessionalRole) {
            throw new ApiException(
                'Les abonnements sont réservés aux comptes professionnels.',
                403,
                'subscription_not_available',
            );
        }
    }

    private function newReceiptNumber(): string
    {
        do {
            $number = 'MS-'.Str::upper(Str::random(10));
        } while (SubscriptionReceipt::where('receipt_number', $number)->exists());

        return $number;
    }
}
