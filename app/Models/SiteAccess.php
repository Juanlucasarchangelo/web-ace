<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteAccess extends Model
{
    protected $fillable = [
        'tenant_id', 'site_id', 'category', 'label', 'service', 'url', 'username', 'secret', 'notes',
    ];

    protected $casts = ['secret' => 'encrypted'];

    // Mesmo com eager loading, a senha não sai acidentalmente em JSON.
    protected $hidden = ['secret'];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
