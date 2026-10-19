<?php

namespace App\Enums;

/**
 *   searching ─► assigned ─► meeting ─► completed
 *        │           │          │
 *        └───────────┴──────────┴─► cancelled
 */
enum TransferStatus: string
{
    case Searching = 'searching';
    case Assigned = 'assigned';
    case Meeting = 'meeting';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function isFinal(): bool
    {
        return in_array($this, [self::Completed, self::Cancelled], true);
    }

    public static function active(): array
    {
        return [self::Searching, self::Assigned, self::Meeting];
    }

    public static function underway(): array
    {
        return [self::Assigned, self::Meeting];
    }
}
