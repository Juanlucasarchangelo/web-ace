<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Client extends Model
{
    protected $fillable = [
        'tenant_id', 'code', 'name', 'trade_name', 'person_type', 'document',
        'email', 'phone', 'mobile', 'contact_name', 'contact_email',
        'address_street', 'address_number', 'address_extra', 'address_district',
        'address_city', 'address_state', 'address_zip', 'address_country',
        'started_at', 'status', 'preferred_cloud', 'notes',
    ];

    protected $casts = ['started_at' => 'date:Y-m-d'];

    public function sites(): HasMany
    {
        return $this->hasMany(Site::class);
    }
}
