<?php

namespace App\Enums;

/**
 *   pending ─► accepted ─► completed
 *      │          │
 *      └────┬─────┴─► cancelled
 *           └────────► rejected
 */
enum BookingStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
    case Completed = 'completed';

    public function isFinal(): bool
    {
        return in_array($this, [self::Rejected, self::Cancelled, self::Completed], true);
    }

    public function isActive(): bool
    {
        return in_array($this, [self::Pending, self::Accepted], true);
    }

    public static function blocking(): array
    {
        return [self::Pending, self::Accepted];
    }
}
