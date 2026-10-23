<?php

namespace App\Services\Shop;

use App\Enums\FulfillmentType;
use App\Enums\OrderStatus;
use App\Exceptions\ApiException;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\Product;
use App\Models\Review;
use App\Models\Shop;
use App\Models\ShopCourier;
use App\Models\User;
use App\Enums\UserRole;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OrderService
{
    /**
     * Transitions autorisees par type d'acteur : [statut courant => [statuts cibles]].
     * Les regles d'appartenance (proprietaire de la boutique, livreur assigne...) sont verifiees a part.
     */
    private const TRANSITIONS = [
        'customer' => [
            'pending' => ['cancelled'],
            'accepted' => ['cancelled'],
            'preparing' => ['cancelled'],
            'ready' => ['cancelled'],
            'out_for_delivery' => ['cancelled'],
        ],
        'shop' => [
            'pending' => ['accepted', 'rejected'],
            'accepted' => ['preparing', 'cancelled'],
            'preparing' => ['ready', 'cancelled'],
            'ready' => ['delivered', 'cancelled'],      // delivered = retrait en boutique uniquement
        ],
        'livreur' => [
            'ready' => ['out_for_delivery'],
            'out_for_delivery' => ['delivered'],
        ],
    ];

    public function __construct(private readonly OrderPricing $pricing) {}

    // ------------------------------------------------------------------
    // Creation
    // ------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $data  donnees validees (voir CreateOrderRequest)
     * @return array{order: Order, created: bool}  `created=false` si la requete est un rejeu (meme Idempotency-Key)
     */
    public function create(User $customer, array $data, ?string $idempotencyKey = null): array
    {
        if ($idempotencyKey && ($existing = $this->findByKey($customer, $idempotencyKey))) {
            return ['order' => $existing, 'created' => false];
        }

        try {
            $order = DB::transaction(fn () => $this->createInTransaction($customer, $data, $idempotencyKey));
        } catch (QueryException $e) {
            // Deux requetes identiques simultanees : la contrainte unique (customer_id, idempotency_key) tranche.
            if ($idempotencyKey && ($existing = $this->findByKey($customer, $idempotencyKey))) {
                return ['order' => $existing, 'created' => false];
            }
            throw $e;
        }

        return ['order' => $order, 'created' => true];
    }

    private function createInTransaction(User $customer, array $data, ?string $idempotencyKey): Order
    {
        $shop = Shop::where('is_active', true)->findOrFail($data['shop_id']);
        $type = FulfillmentType::from($data['fulfillment_type']);

        $priced = $this->pricing->compute(
            $shop, $data['items'], $type, $data['coupon_code'] ?? null, $customer,
            $data['delivery_latitude'] ?? null, $data['delivery_longitude'] ?? null, lock: true,
        );

        $commissionRate = (float) config('shop.commission_rate');
        $commission = (int) round(($priced['subtotal'] - $priced['discount']) * $commissionRate / 100);

        $order = Order::create([
            'number' => $this->generateNumber(),
            'shop_id' => $shop->id,
            'customer_id' => $customer->id,
            'status' => OrderStatus::Pending,
            'fulfillment_type' => $type,
            'payment_method' => 'cash',
            'payment_status' => 'pending',
            'subtotal_cents' => $priced['subtotal'],
            'discount_cents' => $priced['discount'],
            'delivery_fee_cents' => $priced['delivery_fee'],
            'total_cents' => $priced['total'],
            'commission_rate' => $commissionRate,
            'commission_cents' => $commission,
            'coupon_id' => $priced['coupon']?->id,
            'coupon_code' => $priced['coupon']?->code,
            'delivery_address' => $type === FulfillmentType::Delivery ? $data['delivery_address'] : null,
            'delivery_latitude' => $type === FulfillmentType::Delivery ? ($data['delivery_latitude'] ?? null) : null,
            'delivery_longitude' => $type === FulfillmentType::Delivery ? ($data['delivery_longitude'] ?? null) : null,
            'customer_phone' => $data['customer_phone'] ?? $customer->phone,
            'customer_note' => $data['customer_note'] ?? null,
            'delivery_pin' => str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT),
            'idempotency_key' => $idempotencyKey,
        ]);

        foreach ($priced['lines'] as $line) {
            /** @var Product $product */
            $product = $line['product'];

            // Snapshot : un changement de prix/nom ulterieur ne modifie pas la commande.
            OrderItem::create([
                'order_id' => $order->id,
                'product_id' => $product->id,
                'name' => $product->name,
                'unit' => $product->unit,
                'unit_price_cents' => $product->price_cents,
                'quantity' => $line['quantity'],
                'total_cents' => $line['total'],
            ]);

            if ($product->track_stock) {
                $product->decrement('stock_quantity', $line['quantity']);
            }
        }

        $priced['coupon']?->increment('uses_count');

        $this->record($order, null, OrderStatus::Pending, $customer);

        return $order->load(['items', 'shop']);
    }

    // ------------------------------------------------------------------
    // Machine d'etats
    // ------------------------------------------------------------------

    /**
     * @param  array{reason?: string|null, prep_minutes?: int|null, pin?: string|null}  $context
     */
    public function transition(Order $order, OrderStatus $to, User $actor, array $context = []): Order
    {
        return DB::transaction(function () use ($order, $to, $actor, $context) {
            // Relecture avec verrou : deux acteurs ne peuvent pas faire avancer la meme commande en meme temps.
            $order = Order::with('shop')->lockForUpdate()->findOrFail($order->id);
            $from = $order->status;
            $kind = $this->actorKind($order, $actor);

            $allowed = self::TRANSITIONS[$kind][$from->value] ?? [];
            if (! in_array($to->value, $allowed, true)) {
                throw new ApiException(
                    "Action impossible : la commande est « {$from->value} ».",
                    409,
                    'invalid_transition',
                );
            }

            $this->guardBusinessRules($order, $from, $to, $kind, $actor, $context);

            $changes = ['status' => $to];
            switch ($to) {
                case OrderStatus::Accepted:
                    $changes['accepted_at'] = now();
                    // Délai annoncé : celui saisi par le commerçant, sinon le plus long entre le délai
                    // habituel et le plat le plus long à préparer, majoré du mode rush éventuel.
                    $slowestDish = $order->items()->join('products', 'products.id', '=', 'order_items.product_id')
                        ->max('products.prep_minutes');
                    $changes['prep_minutes'] = $context['prep_minutes']
                        ?? $order->shop->effectivePrepMinutes($slowestDish ? (int) $slowestDish : null);
                    break;
                case OrderStatus::Ready:
                    $changes['ready_at'] = now();
                    break;
                case OrderStatus::OutForDelivery:
                    $changes['picked_up_at'] = now();
                    break;
                case OrderStatus::Delivered:
                    $changes['delivered_at'] = now();
                    $changes['payment_status'] = 'paid';   // paiement a la livraison / au retrait
                    break;
                case OrderStatus::Rejected:
                    $changes['rejection_reason'] = $context['reason'];
                    break;
                case OrderStatus::Cancelled:
                    $changes['cancellation_reason'] = $context['reason'] ?? null;
                    $changes['cancelled_at'] = now();
                    break;
                default:
                    break;
            }

            $order->update($changes);

            if (in_array($to, [OrderStatus::Rejected, OrderStatus::Cancelled], true)) {
                $this->restock($order);
            }
            if ($to === OrderStatus::Delivered) {
                $order->shop->increment('orders_count');
            }

            $this->record($order, $from, $to, $actor, $context['reason'] ?? null);

            return $order->load(['items', 'shop', 'histories']);
        });
    }

    private function guardBusinessRules(Order $order, OrderStatus $from, OrderStatus $to, string $kind, User $actor, array $ctx): void
    {
        if ($to === OrderStatus::Rejected && empty($ctx['reason'])) {
            throw ValidationException::withMessages(['reason' => ['Un motif de refus est obligatoire.']]);
        }

        if ($kind === 'shop' && $from === OrderStatus::Ready && $to === OrderStatus::Delivered) {
            if ($order->isDelivery()) {
                throw new ApiException('Une commande en livraison est clôturée par le livreur.', 409, 'invalid_transition');
            }
        }

        if ($kind === 'shop' && $to === OrderStatus::Cancelled && empty($ctx['reason'])) {
            throw ValidationException::withMessages(['reason' => ['Un motif d\'annulation est obligatoire.']]);
        }

        if ($to === OrderStatus::OutForDelivery && $order->livreur_id !== $actor->id) {
            throw new ApiException('Cette livraison ne vous est pas assignée.', 403, 'forbidden');
        }

        // Code de remise : confirme que le bon client recoit (ou retire) la commande.
        if ($to === OrderStatus::Delivered) {
            $pin = (string) ($ctx['pin'] ?? '');
            if (! hash_equals((string) $order->delivery_pin, $pin)) {
                throw ValidationException::withMessages(['pin' => ['Code de remise incorrect.']]);
            }
        }
    }

    /** @return 'customer'|'shop'|'livreur' */
    private function actorKind(Order $order, User $actor): string
    {
        return match (true) {
            $order->customer_id === $actor->id => 'customer',
            $order->shop->owner_id === $actor->id => 'shop',
            $order->livreur_id !== null && $order->livreur_id === $actor->id => 'livreur',
            default => throw new ApiException('Vous n\'avez pas accès à cette commande.', 403, 'forbidden'),
        };
    }

    private function restock(Order $order): void
    {
        foreach ($order->items as $item) {
            if ($item->product_id === null) {
                continue;
            }
            Product::withTrashed()->where('id', $item->product_id)->where('track_stock', true)
                ->increment('stock_quantity', $item->quantity);
        }

        if ($order->coupon_id) {
            DB::table('coupons')->where('id', $order->coupon_id)->where('uses_count', '>', 0)->decrement('uses_count');
        }
    }

    private function record(Order $order, ?OrderStatus $from, OrderStatus $to, ?User $actor, ?string $note = null): void
    {
        OrderStatusHistory::create([
            'order_id' => $order->id,
            'from_status' => $from,
            'to_status' => $to,
            'actor_id' => $actor?->id,
            'note' => $note ? Str::limit($note, 250, '') : null,
        ]);
    }

    // ------------------------------------------------------------------
    // Livreur
    // ------------------------------------------------------------------

    /** Prise en charge atomique : un seul livreur peut reserver une commande. */
    public function claim(Order $order, User $livreur): Order
    {
        $livreur->ensureVisible();
        if (! $livreur->is_available) {
            throw new ApiException('Vous êtes hors service. Passez en service pour accepter des courses.', 409, 'livreur_unavailable');
        }

        if (! $livreur->is_available) {
            throw new ApiException('Vous êtes hors service : passez en service pour prendre une course.', 409, 'livreur_offline');
        }

        $active = Order::where('livreur_id', $livreur->id)
            ->whereIn('status', [OrderStatus::Preparing, OrderStatus::Ready, OrderStatus::OutForDelivery])->count();

        if ($active >= (int) config('shop.livreur_max_active')) {
            throw new ApiException('Vous avez atteint le nombre maximum de livraisons en cours.', 409, 'too_many_active_deliveries');
        }

        $claimed = Order::where('id', $order->id)
            ->visibleToLivreur($livreur->id)
            ->where('fulfillment_type', FulfillmentType::Delivery)
            ->whereIn('status', [OrderStatus::Preparing, OrderStatus::Ready])
            ->update(['livreur_id' => $livreur->id, 'offered_livreur_id' => null, 'offer_expires_at' => null]);

        if ($claimed === 0) {
            throw new ApiException('Cette course n\'est plus disponible.', 409, 'delivery_unavailable');
        }

        return $order->refresh()->load(['items', 'shop', 'histories']);
    }

    /** Statuts pendant lesquels la boutique peut (re)choisir son mode de recherche de livreur. */
    private const DISPATCHABLE = [OrderStatus::Preparing, OrderStatus::Ready];

    /**
     * La boutique propose la course a l'un de SES livreurs habituels : il en a l'exclusivite
     * pendant `shop.direct_offer_minutes`, puis la course redevient visible de tous.
     */
    public function offerToCourier(Order $order, User $owner, int $livreurId): Order
    {
        return DB::transaction(function () use ($order, $owner, $livreurId) {
            $order = $this->lockForDispatch($order, $owner);

            $trusted = ShopCourier::where('shop_id', $order->shop_id)->where('livreur_id', $livreurId)->exists();
            $livreur = User::find($livreurId);
            if (! $trusted || ! $livreur || ! $livreur->hasRole(UserRole::Livreur) || ! $livreur->isActive()) {
                throw ValidationException::withMessages([
                    'livreur_id' => ['Ce livreur ne fait pas partie de vos livreurs habituels (ou n\'est plus disponible).'],
                ]);
            }

            if (! $livreur->is_available) {
                throw ValidationException::withMessages(['livreur_id' => ["{$livreur->name} est actuellement hors service."]]);
            }

            $minutes = max(1, (int) config('shop.direct_offer_minutes'));
            $order->update([
                'offered_livreur_id' => $livreur->id,
                'offer_expires_at' => now()->addMinutes($minutes),
            ]);
            $this->record($order, $order->status, $order->status, $owner, "Course proposée à {$livreur->name}");

            return $order->load(['items', 'shop', 'histories', 'customer', 'livreur', 'offeredLivreur']);
        });
    }

    /** La boutique lance la recherche ouverte : tous les livreurs de la plateforme voient la course. */
    public function openToAll(Order $order, User $owner): Order
    {
        return DB::transaction(function () use ($order, $owner) {
            $order = $this->lockForDispatch($order, $owner);

            if ($order->offered_livreur_id !== null) {
                $order->update(['offered_livreur_id' => null, 'offer_expires_at' => null]);
                $this->record($order, $order->status, $order->status, $owner, 'Recherche ouverte à tous les livreurs');
            }

            return $order->load(['items', 'shop', 'histories', 'customer', 'livreur', 'offeredLivreur']);
        });
    }

    /** Le livreur sollicite decline l'offre : la course repasse en recherche ouverte. */
    public function declineOffer(Order $order, User $livreur): Order
    {
        return DB::transaction(function () use ($order, $livreur) {
            $order = Order::lockForUpdate()->findOrFail($order->id);

            if ($order->livreur_id !== null || $order->offered_livreur_id !== $livreur->id) {
                throw new ApiException('Cette proposition n\'existe plus.', 409, 'delivery_unavailable');
            }

            $order->update(['offered_livreur_id' => null, 'offer_expires_at' => null]);
            $this->record($order, $order->status, $order->status, $livreur, "Proposition déclinée par {$livreur->name}");

            return $order->load(['items', 'shop']);
        });
    }

    private function lockForDispatch(Order $order, User $owner): Order
    {
        $order = Order::with('shop')->lockForUpdate()->findOrFail($order->id);

        if ($order->shop->owner_id !== $owner->id) {
            throw new ApiException('Vous n\'avez pas accès à cette commande.', 403, 'forbidden');
        }
        if (! $order->isDelivery()) {
            throw new ApiException('Cette commande est en retrait : aucun livreur nécessaire.', 409, 'invalid_transition');
        }
        if (! in_array($order->status, self::DISPATCHABLE, true)) {
            throw new ApiException('Le choix du livreur est possible pendant la préparation ou quand la commande est prête.', 409, 'invalid_transition');
        }
        if ($order->livreur_id !== null) {
            throw new ApiException('Un livreur a déjà pris cette course.', 409, 'delivery_taken');
        }

        return $order;
    }

    /** Le livreur se desiste tant qu'il n'a pas recupere la commande. */
    public function release(Order $order, User $livreur): Order
    {
        $released = Order::where('id', $order->id)
            ->where('livreur_id', $livreur->id)
            ->whereIn('status', [OrderStatus::Preparing, OrderStatus::Ready])
            ->update(['livreur_id' => null]);

        if ($released === 0) {
            throw new ApiException('Impossible de libérer cette course.', 409, 'invalid_transition');
        }

        return $order->refresh()->load(['items', 'shop']);
    }

    // ------------------------------------------------------------------
    // Avis
    // ------------------------------------------------------------------

    public function review(Order $order, User $customer, int $rating, ?string $comment, ?int $courierRating = null): Review
    {
        if ($order->customer_id !== $customer->id) {
            throw new ApiException('Vous n\'avez pas accès à cette commande.', 403, 'forbidden');
        }
        if ($order->status !== OrderStatus::Delivered) {
            throw new ApiException('Vous pourrez noter la commande une fois livrée.', 409, 'order_not_delivered');
        }

        return DB::transaction(function () use ($order, $customer, $rating, $comment, $courierRating) {
            if (Review::where('order_id', $order->id)->lockForUpdate()->exists()) {
                throw new ApiException('Cette commande a déjà été notée.', 409, 'already_reviewed');
            }

            $review = Review::create([
                'order_id' => $order->id,
                'shop_id' => $order->shop_id,
                'customer_id' => $customer->id,
                'rating' => $rating,
                'comment' => $comment,
                // La note du livreur n'a de sens que si un livreur a livre la commande.
                'livreur_id' => $order->livreur_id,
                'courier_rating' => $order->livreur_id ? $courierRating : null,
            ]);

            $stats = Review::where('shop_id', $order->shop_id)->selectRaw('AVG(rating) as avg, COUNT(*) as n')->first();
            Shop::where('id', $order->shop_id)->update([
                'rating_avg' => round((float) $stats->avg, 2),
                'rating_count' => (int) $stats->n,
            ]);

            return $review;
        });
    }

    // ------------------------------------------------------------------

    private function findByKey(User $customer, string $key): ?Order
    {
        return Order::with(['items', 'shop'])
            ->where('customer_id', $customer->id)->where('idempotency_key', $key)->first();
    }

    private function generateNumber(): string
    {
        do {
            $number = 'MS'.now()->format('ymd').'-'.strtoupper(Str::random(5));
        } while (Order::where('number', $number)->exists());

        return $number;
    }
}
