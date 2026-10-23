<?php

namespace App\Enums;

/**
 * Cycle de vie d'une commande.
 *
 *   pending ─► accepted ─► preparing ─► ready ─► out_for_delivery ─► delivered
 *      │          │            │          │
 *      ├─► rejected (boutique)  └──────────┴─► cancelled (boutique / client)
 *      └─► cancelled (client)
 *
 * Retrait en boutique : ready ─► delivered (confirme par la boutique avec le code de remise).
 */
enum OrderStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Preparing = 'preparing';
    case Ready = 'ready';
    case OutForDelivery = 'out_for_delivery';
    case Delivered = 'delivered';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    public function isFinal(): bool
    {
        return in_array($this, [self::Delivered, self::Rejected, self::Cancelled], true);
    }

    /** Statuts pour lesquels le stock a ete reserve et doit etre restitue si la commande est annulee. */
    public static function active(): array
    {
        return [self::Pending, self::Accepted, self::Preparing, self::Ready, self::OutForDelivery];
    }
}
