<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tenant extends Model
{
    use HasUuids;

    protected $fillable = ['name', 'slug', 'is_active', 'plan', 'seat_limit', 'billing_status', 'billing_checked_at', 'billing_provider', 'billing_cycle', 'billing_expires_at'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'seat_limit' => 'integer', 'billing_checked_at' => 'datetime', 'billing_expires_at' => 'datetime'];
    }

    public function driveConnections(): HasMany
    {
        return $this->hasMany(GoogleDriveConnection::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('role')->withTimestamps();
    }
}
