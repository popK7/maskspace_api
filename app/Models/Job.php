<?php

namespace App\Models;

use App\Enums\JobStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'client_id', 'artisan_id', 'ride_id', 'trade', 'status', 'note',
    'address', 'neighborhood', 'city', 'latitude', 'longitude', 'eta_minutes',
    'rating', 'review', 'cancelled_by', 'cancel_reason',
    'assigned_at', 'en_route_at', 'arrived_at', 'started_at', 'completed_at', 'cancelled_at',
])]
class Job extends Model
{
    protected $table = 'artisan_jobs';

    protected function casts(): array
    {
        return [
            'status' => JobStatus::class,
            'latitude' => 'float',
            'longitude' => 'float',
            'eta_minutes' => 'integer',
            'rating' => 'integer',
            'assigned_at' => 'datetime',
            'en_route_at' => 'datetime',
            'arrived_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    public function artisan(): BelongsTo
    {
        return $this->belongsTo(User::class, 'artisan_id');
    }

    public function ride(): BelongsTo
    {
        return $this->belongsTo(Ride::class);
    }

    public function offers(): HasMany
    {
        return $this->hasMany(JobOffer::class);
    }

    public function rides(): HasMany
    {
        return $this->hasMany(Ride::class);
    }
}
