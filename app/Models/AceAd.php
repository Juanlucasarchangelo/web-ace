<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class AceAd extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name', 'provider', 'placement', 'headline', 'description', 'image_path',
        'destination_url', 'google_client', 'google_slot', 'priority', 'is_active',
        'starts_at', 'ends_at', 'created_by', 'html_code',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean', 'priority' => 'integer',
            'starts_at' => 'datetime', 'ends_at' => 'datetime',
        ];
    }
}
