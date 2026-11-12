<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class AceSponsor extends Model
{
    use SoftDeletes;

    protected $fillable = ['name', 'logo_path', 'website_url', 'description', 'sort_order', 'is_active', 'created_by'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'sort_order' => 'integer'];
    }
}
