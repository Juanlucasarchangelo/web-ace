<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class AcePopup extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'type', 'title', 'description', 'badge_text', 'image_path', 'cta_label', 'cta_url',
        'placements', 'trigger', 'delay_seconds', 'scroll_percent', 'frequency', 'priority',
        'is_active', 'starts_at', 'ends_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'placements' => 'array', 'is_active' => 'boolean',
            'delay_seconds' => 'integer', 'scroll_percent' => 'integer', 'priority' => 'integer',
            'starts_at' => 'datetime', 'ends_at' => 'datetime',
        ];
    }
}
