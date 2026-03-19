<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Site extends Model
{
    use SoftDeletes, HasFactory;

    protected $fillable = [
        'user_id',
        'resumo',
        'dominio',
        'acesso_email',
        'email_profissional',
        'drive',
        'youtube',
        'facebook',
        'linkedin',
        'gmail',
        'instagram',
        'linktree',
        'info_adicionais',
        'registro_dominio',
        'usuario_dominio',
        'senha_dominio',
        'hospedagem',
        'usuario_hospedagem',
        'senha_hospedagem',
        'dns_primario',
        'dns_secundario',
        'ftp',
        'usuario_ftp',
        'senha_ftp',
        'link_site_adm',
        'usuario_site_adm',
        'senha_site_adm'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
