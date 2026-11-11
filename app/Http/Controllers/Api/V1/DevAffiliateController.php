<?php
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AceAffiliate;
use App\Models\AceAffiliateCoupon;
use App\Models\AceBillingOrder;
use App\Models\AceSignupApplication;
use App\Models\DevAdminAudit;
use App\Models\User;
use App\Services\Billing\AffiliateCoupons;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class DevAffiliateController extends Controller
{
    public function index(): JsonResponse
    {
        $affiliates = AceAffiliate::with('user:id,name,email,is_active')->orderByDesc('id')->get();
        $coupons = AceAffiliateCoupon::with('affiliate.user:id,name,email')->orderByDesc('id')->get();
        $signupCounts = AceSignupApplication::whereNotNull('affiliate_coupon_id')->select('affiliate_coupon_id')
            ->selectRaw('count(*) as total')->groupBy('affiliate_coupon_id')->pluck('total', 'affiliate_coupon_id');
        $orderCounts = AceBillingOrder::whereNotNull('affiliate_coupon_id')->select('affiliate_coupon_id')
            ->selectRaw('count(*) as total')->groupBy('affiliate_coupon_id')->pluck('total', 'affiliate_coupon_id');
        $sales = AceBillingOrder::whereNotNull('affiliate_coupon_id')->where('status', 'approved')
            ->select('affiliate_coupon_id')->selectRaw('count(*) as total, sum(amount_cents) as revenue')
            ->groupBy('affiliate_coupon_id')->get()->keyBy('affiliate_coupon_id');
        $refunds = AceBillingOrder::whereNotNull('affiliate_coupon_id')->whereIn('status', ['refunded','charged_back'])
            ->select('affiliate_coupon_id')->selectRaw('count(*) as total')->groupBy('affiliate_coupon_id')->pluck('total', 'affiliate_coupon_id');
        $couponData = $coupons->map(function (AceAffiliateCoupon $coupon) use ($signupCounts, $orderCounts, $sales, $refunds) {
            $sale = $sales->get($coupon->id);
            return [
                'id' => $coupon->id, 'code' => $coupon->code, 'affiliate_id' => $coupon->affiliate_id,
                'affiliate_name' => $coupon->affiliate?->user?->name,
                'discount_percent' => $coupon->discount_percent, 'plan' => $coupon->plan,
                'max_uses' => $coupon->max_uses, 'expires_at' => $coupon->expires_at?->toIso8601String(),
                'is_active' => $coupon->is_active, 'created_at' => $coupon->created_at?->toIso8601String(),
                'uses' => (int) ($signupCounts[$coupon->id] ?? 0),
                'orders' => (int) ($orderCounts[$coupon->id] ?? 0),
                'paid_sales' => (int) ($sale?->total ?? 0),
                'paid_revenue_cents' => (int) ($sale?->revenue ?? 0),
                'refunded' => (int) ($refunds[$coupon->id] ?? 0),
            ];
        });
        return response()->json(['data' => [
            'affiliates' => $affiliates->map(function ($a) use ($couponData) {
                $owned = $couponData->where('affiliate_id', $a->id);
                return [
                    'id' => $a->id, 'user_id' => $a->user_id, 'name' => $a->user?->name,
                    'email' => $a->user?->email, 'is_active' => $a->is_active, 'user_active' => (bool) $a->user?->is_active,
                    'uses' => $owned->sum('uses'), 'orders' => $owned->sum('orders'),
                    'paid_sales' => $owned->sum('paid_sales'),
                    'paid_revenue_cents' => $owned->sum('paid_revenue_cents'),
                ];
            }),
            'coupons' => $couponData,
            'summary' => [
                'affiliates' => $affiliates->count(), 'coupons' => $coupons->count(),
                'uses' => $couponData->sum('uses'), 'orders' => $couponData->sum('orders'),
                'paid_sales' => $couponData->sum('paid_sales'), 'paid_revenue_cents' => $couponData->sum('paid_revenue_cents'),
            ],
        ]])->header('Cache-Control', 'no-store, private');
    }

    public function candidates(Request $request): JsonResponse
    {
        $data = $request->validate(['search' => ['required','string','min:2','max:100']]);
        $search = trim($data['search']);
        $users = User::query()->where('is_dev', false)->where('is_active', true)
            ->where(function ($q) use ($search) {
                $q->where('name', 'like', '%'.$search.'%')->orWhere('email', 'like', '%'.$search.'%');
            })->orderBy('name')->limit(20)->get(['id','name','email']);
        return response()->json(['data' => $users])->header('Cache-Control','no-store, private');
    }

    public function storeAffiliate(Request $request): JsonResponse
    {
        $data = $request->validate(['user_id' => ['required','integer',Rule::exists('users', 'id')]]);
        $affiliate = DB::transaction(function () use ($data, $request) {
            $user = User::whereKey($data['user_id'])->lockForUpdate()->firstOrFail();
            if ($user->is_dev || ! $user->is_active) throw ValidationException::withMessages(['user_id' => 'Usuário indisponível para afiliação.']);
            if (AceAffiliate::where('user_id', $user->id)->exists()) throw ValidationException::withMessages(['user_id' => 'Este usuário já é afiliado.']);
            $affiliate = AceAffiliate::create(['user_id' => $user->id]);
            $this->audit($request, 'affiliate.created.'.$affiliate->id);
            return $affiliate;
        });
        return response()->json(['data' => ['id' => $affiliate->id], 'message' => 'Afiliado cadastrado.'], 201);
    }

    public function updateAffiliate(Request $request, AceAffiliate $affiliate): JsonResponse
    {
        $data = $request->validate(['is_active' => ['required','boolean']]);
        $affiliate->update(['is_active' => $data['is_active']]);
        $this->audit($request, 'affiliate.status.'.$affiliate->id.'.'.($data['is_active'] ? 'enabled' : 'disabled'));
        return response()->json(['message' => 'Status do afiliado atualizado.']);
    }

    public function storeCoupon(Request $request): JsonResponse
    {
        $data = $request->validate([
            'affiliate_id' => ['required','integer',Rule::exists('ace_affiliates','id')],
            'code' => ['required','string','regex:/^[A-Za-z0-9_-]{4,32}$/D','unique:ace_affiliate_coupons,code'],
            'discount_percent' => ['required','integer','between:1,90'],
            'plan' => ['nullable',Rule::in(['cloud','pro'])],
            'max_uses' => ['nullable','integer','between:1,1000000'],
            'expires_at' => ['nullable','date','after:now'],
        ]);
        $affiliate = AceAffiliate::findOrFail($data['affiliate_id']);
        abort_unless($affiliate->is_active && $affiliate->user?->is_active, 422, 'Afiliado inativo.');
        $code = AffiliateCoupons::normalize($data['code']);
        if (AceAffiliateCoupon::where('code', $code)->exists()) throw ValidationException::withMessages(['code' => 'Cupom já existente.']);
        $coupon = AceAffiliateCoupon::create([
            'affiliate_id' => $affiliate->id, 'code' => $code,
            'discount_percent' => $data['discount_percent'], 'plan' => $data['plan'] ?? null,
            'max_uses' => $data['max_uses'] ?? null, 'expires_at' => $data['expires_at'] ?? null,
        ]);
        $this->audit($request, 'affiliate.coupon.created.'.$coupon->id);
        return response()->json(['data' => ['id' => $coupon->id], 'message' => 'Cupom criado.'], 201);
    }

    public function updateCoupon(Request $request, AceAffiliateCoupon $coupon): JsonResponse
    {
        $data = $request->validate(['is_active' => ['required','boolean']]);
        $coupon->update(['is_active' => $data['is_active']]);
        $this->audit($request, 'affiliate.coupon.status.'.$coupon->id.'.'.($data['is_active'] ? 'enabled' : 'disabled'));
        return response()->json(['message' => 'Status do cupom atualizado.']);
    }

    public function sales(Request $request): JsonResponse
    {
        $input = $request->validate(['coupon_id' => ['nullable','integer',Rule::exists('ace_affiliate_coupons','id')]]);
        $query = AceBillingOrder::query()->whereNotNull('affiliate_coupon_id')
            ->select(['id','affiliate_coupon_id','plan','cycle','status','amount_cents','discount_cents','original_amount_cents','created_at','paid_at']);
        if (! empty($input['coupon_id'])) $query->where('affiliate_coupon_id', $input['coupon_id']);
        return response()->json(['data' => $query->orderByDesc('created_at')->paginate(25)])
            ->header('Cache-Control', 'no-store, private');
    }

    private function audit(Request $request, string $action): void
    {
        DevAdminAudit::create(['actor_user_id' => $request->user()->id, 'action' => $action, 'created_at' => now()]);
    }
}
