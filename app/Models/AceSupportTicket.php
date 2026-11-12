<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class AceSupportTicket extends Model
{
    use HasUuids;

    protected $guarded = [];

    public function messages() { return $this->hasMany(AceSupportMessage::class, 'ticket_id')->orderBy('id'); }
    public function tenant() { return $this->belongsTo(Tenant::class); }
    public function creator() { return $this->belongsTo(User::class, 'created_by_user_id'); }
    protected function casts(): array
    {
        return ['last_reply_at' => 'datetime', 'closed_at' => 'datetime'];
    }
}
