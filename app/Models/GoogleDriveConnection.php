<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoogleDriveConnection extends Model
{
    protected $fillable = ['tenant_id', 'connected_by', 'email', 'access_token', 'refresh_token', 'scope', 'expires_at', 'health_status', 'last_error_code', 'last_error_at', 'last_checked_at', 'last_success_at'];

    protected $hidden = ['access_token', 'refresh_token'];

    protected function casts(): array
    {
        return ['access_token' => 'encrypted', 'refresh_token' => 'encrypted', 'expires_at' => 'datetime', 'last_error_at' => 'datetime', 'last_checked_at' => 'datetime', 'last_success_at' => 'datetime'];
    }

    public function tenant(): BelongsTo { return $this->belongsTo(Tenant::class); }
}
