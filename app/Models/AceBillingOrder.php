<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class AceBillingOrder extends Model
{
    use HasUuids;
    protected $table = 'ace_billing_orders';
    protected $guarded = [];

    protected function casts(): array
    {
        return ['paid_at' => 'datetime', 'period_end' => 'datetime', 'amount_cents' => 'integer'];
    }
}
