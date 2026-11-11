<?php
namespace App\Services\Billing;

use App\Models\AceAffiliateCoupon;
use App\Models\AceSignupApplication;
use App\Support\BillingPlans;
use Illuminate\Validation\ValidationException;

final class AffiliateCoupons
{
    public static function normalize(?string $value): string
    {
        return strtoupper(trim((string) $value));
    }

    /** Computes a quote from server-owned prices; never trust client amount or discount. */
    public function quote(string $plan, string $cycle, ?string $code = null, ?string $applicationId = null): array
    {
        $original = BillingPlans::price($plan, $cycle);
        $normalized = self::normalize($code);
        $coupon = null;
        if ($normalized !== '') {
            if ($plan === 'free' || ! preg_match('/^[A-Z0-9_-]{4,32}$/D', $normalized)) {
                throw ValidationException::withMessages(['coupon' => 'Cupom indisponível para o plano selecionado.']);
            }
            $coupon = AceAffiliateCoupon::query()->with('affiliate.user')->where('code', $normalized)->first();
            if (! $coupon || ! $coupon->is_active || ! $coupon->affiliate?->is_active || ! $coupon->affiliate?->user?->is_active
                || ($coupon->expires_at && $coupon->expires_at->isPast())
                || ($coupon->plan !== null && $coupon->plan !== $plan)
                || ($coupon->max_uses !== null && AceSignupApplication::where('affiliate_coupon_id', $coupon->id)
                    ->when($applicationId, fn ($query) => $query->where('id', '!=', $applicationId))
                    ->count() >= $coupon->max_uses && ! ($applicationId && AceSignupApplication::whereKey($applicationId)->where('affiliate_coupon_id', $coupon->id)->exists()))) {
                throw ValidationException::withMessages(['coupon' => 'Cupom expirado, esgotado ou indisponível para este plano.']);
            }
        }
        $discount = $coupon ? intdiv($original * $coupon->discount_percent, 100) : 0;
        return [
            'coupon' => $coupon,
            'original_amount_cents' => $original,
            'discount_cents' => $discount,
            'amount_cents' => $original - $discount,
            'discount_percent' => $coupon?->discount_percent ?? 0,
            'coupon_code' => $coupon?->code,
        ];
    }
}
