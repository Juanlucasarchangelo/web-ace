<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** The DEV edits public copy and future prices, never the plan entitlement key. */
class AcePlan extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';
    protected $fillable = ['title', 'description', 'features', 'monthly_cents', 'annual_cents', 'is_active', 'sort_order', 'updated_by'];

    protected function casts(): array
    {
        return ['features' => 'array', 'monthly_cents' => 'integer', 'annual_cents' => 'integer', 'is_active' => 'boolean', 'sort_order' => 'integer'];
    }
}
