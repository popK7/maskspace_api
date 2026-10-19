<?php

namespace App\Models;

use App\Support\Media;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['lodging_id', 'path', 'position', 'caption'])]
class LodgingPhoto extends Model
{
    public function lodging(): BelongsTo
    {
        return $this->belongsTo(Lodging::class);
    }

    public function url(): ?string
    {
        return Media::url($this->path);
    }

    public function thumbUrl(): ?string
    {
        return Media::thumbUrl($this->path);
    }
}
