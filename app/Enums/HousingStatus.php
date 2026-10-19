<?php

namespace App\Enums;

enum HousingStatus: string
{
    case Searching = 'searching';
    case Assigned = 'assigned';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function isFinal(): bool
    {
        return in_array($this, [self::Completed, self::Cancelled], true);
    }

    public function isActive(): bool
    {
        return ! $this->isFinal();
    }
}
