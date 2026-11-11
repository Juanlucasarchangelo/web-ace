<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DevAdminAudit extends Model
{
    public $timestamps = false;

    protected $fillable = ['actor_user_id', 'tenant_id', 'action', 'created_at'];

    protected function casts(): array { return ['created_at' => 'datetime']; }

    public function tenant(): BelongsTo { return $this->belongsTo(Tenant::class); }
    public function actor(): BelongsTo { return $this->belongsTo(User::class, 'actor_user_id'); }
}
