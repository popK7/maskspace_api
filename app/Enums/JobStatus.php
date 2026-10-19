<?php

namespace App\Enums;

/**
 * Cycle de vie d'une intervention artisanale.
 *
 *   searching ─► assigned ─► en_route ─► arrived ─► in_progress ─► completed
 *        │           │           │           │
 *        └───────────┴───────────┴───────────┴─► cancelled
 */
enum JobStatus: string
{
    case Searching = 'searching';
    case Assigned = 'assigned';
    case EnRoute = 'en_route';
    case Arrived = 'arrived';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function isFinal(): bool
    {
        return in_array($this, [self::Completed, self::Cancelled], true);
    }

    /** Interventions encore « en cours » (un artisan / un client n'en a qu'une). */
    public static function active(): array
    {
        return [self::Searching, self::Assigned, self::EnRoute, self::Arrived, self::InProgress];
    }

    /** L'artisan est cense se deplacer ou est deja chez le client. */
    public static function underway(): array
    {
        return [self::Assigned, self::EnRoute, self::Arrived, self::InProgress];
    }
}
