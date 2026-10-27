<?php

namespace App\Models;

use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'role', 'is_visible', 'profile_completed_at'])]
class UserRoleMembership extends Model
{
    protected $table = 'user_roles';

    protected $primaryKey = null;

    public $incrementing = false;

    protected function casts(): array
    {
        return [
            'role' => UserRole::class,
            'is_visible' => 'boolean',
            'profile_completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
