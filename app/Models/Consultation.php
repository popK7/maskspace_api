<?php

namespace App\Models;

use App\Enums\ConsultationMode;
use App\Enums\ConsultationStatus;
use App\Enums\MedicalSpecialty;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'client_id', 'medecin_id', 'specialty', 'mode', 'note', 'status',
    'assigned_at', 'completed_at', 'cancelled_at', 'cancel_reason',
])]
class Consultation extends Model
{
    protected function casts(): array
    {
        return [
            'specialty' => MedicalSpecialty::class,
            'mode' => ConsultationMode::class,
            'status' => ConsultationStatus::class,
            'assigned_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    public function medecin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'medecin_id');
    }

    public function offers(): HasMany
    {
        return $this->hasMany(ConsultationOffer::class);
    }
}
