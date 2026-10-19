<?php

namespace App\Models;

use App\Enums\BookingStatus;
use App\Enums\RentalBilling;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'client_id', 'host_id', 'lodging_id', 'check_in', 'check_out', 'guests',
    'nights', 'billing', 'total_cents', 'status', 'note', 'rating', 'review',
    'cancelled_by', 'cancel_reason', 'accepted_at', 'rejected_at',
    'completed_at', 'cancelled_at',
])]
class LodgingBooking extends Model
{
    protected function casts(): array
    {
        return [
            'status' => BookingStatus::class,
            'check_in' => 'date',
            'check_out' => 'date',
            'guests' => 'integer',
            'nights' => 'integer',
            'billing' => RentalBilling::class,
            'total_cents' => 'integer',
            'rating' => 'integer',
            'accepted_at' => 'datetime',
            'rejected_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    public function host(): BelongsTo
    {
        return $this->belongsTo(User::class, 'host_id');
    }

    public function lodging(): BelongsTo
    {
        return $this->belongsTo(Lodging::class);
    }
}
