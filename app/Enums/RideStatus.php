<?php

namespace App\Enums;

/**
 * Course transporteur (artisan vers le client, plus tard client vers une destination).
 *
 *   searching ─► accepted ─► in_progress ─► completed
 *        │           │
 *        └───────────┴─► cancelled
 */
enum RideStatus: string
{
    case Searching = 'searching';
    case Accepted = 'accepted';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function isFinal(): bool
    {
        return in_array($this, [self::Completed, self::Cancelled], true);
    }

    public static function active(): array
    {
        return [self::Searching, self::Accepted, self::InProgress];
    }
}
