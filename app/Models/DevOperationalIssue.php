<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DevOperationalIssue extends Model
{
    public $timestamps = false;

    protected $fillable = ['tenant_id', 'connection_id', 'source', 'code', 'summary', 'severity', 'created_at', 'resolved_at'];

    protected function casts(): array { return ['created_at' => 'datetime', 'resolved_at' => 'datetime']; }

    public function tenant(): BelongsTo { return $this->belongsTo(Tenant::class); }
}
