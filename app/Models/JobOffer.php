<?php

namespace App\Models;

use App\Enums\JobOfferStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['job_id', 'artisan_id', 'status', 'eta_minutes', 'distance_km'])]
class JobOffer extends Model
{
    protected function casts(): array
    {
        return [
            'status' => JobOfferStatus::class,
            'eta_minutes' => 'integer',
            'distance_km' => 'float',
        ];
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class);
    }

    public function artisan(): BelongsTo
    {
        return $this->belongsTo(User::class, 'artisan_id');
    }
}
