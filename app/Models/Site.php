<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Site extends Model
{
    protected $fillable = [
        'tenant_id', 'client_id', 'name', 'domain', 'status', 'online_at',
        'hosting_provider', 'dns_primary', 'dns_secondary', 'ftp_host',
        'control_panel_url', 'store_url', 'registro_br', 'social_links', 'notes',
    ];

    protected $casts = [
        'online_at' => 'date:Y-m-d',
        'social_links' => 'array',
        'registro_br' => 'encrypted',
    ];

    // O conteúdo completo do Registro.br pode conter dados sensíveis.
    protected $hidden = ['registro_br'];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function accesses(): HasMany
    {
        return $this->hasMany(SiteAccess::class);
    }
}
