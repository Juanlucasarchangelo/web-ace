<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
final class AceAffiliateCoupon extends Model
{
    protected $guarded = [];
    protected function casts(): array { return ['is_active' => 'boolean', 'expires_at' => 'datetime']; }
    public function affiliate(): BelongsTo { return $this->belongsTo(AceAffiliate::class, 'affiliate_id'); }
}
