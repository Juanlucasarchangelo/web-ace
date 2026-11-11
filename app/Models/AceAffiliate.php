<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
final class AceAffiliate extends Model
{
    protected $guarded = [];
    protected $casts = ['is_active' => 'boolean'];
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function coupons(): HasMany { return $this->hasMany(AceAffiliateCoupon::class, 'affiliate_id'); }
}
