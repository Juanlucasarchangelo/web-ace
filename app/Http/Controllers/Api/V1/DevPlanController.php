<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AcePlan;
use App\Models\DevAdminAudit;
use App\Support\BillingPlans;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Restricted to auth + dev.only. Never allows changes to entitlement slugs. */
final class DevPlanController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => BillingPlans::catalog(true)]);
    }

    public function history(string $plan): JsonResponse
    {
        abort_unless(in_array($plan, BillingPlans::KEYS, true), 404);
        $rows = DB::table('ace_plan_changes')->where('plan_id', $plan)->orderByDesc('id')->limit(20)
            ->get(['id', 'actor_user_id', 'before_state', 'after_state', 'created_at'])
            ->map(static function ($row): array {
                $before = json_decode($row->before_state, true) ?: [];
                $after = json_decode($row->after_state, true) ?: [];
                return [
                    'id' => $row->id, 'actor_id' => $row->actor_user_id,
                    'created_at' => $row->created_at,
                    'before' => ['title' => $before['title'] ?? '', 'monthly_cents' => $before['monthly_cents'] ?? 0, 'annual_cents' => $before['annual_cents'] ?? 0],
                    'after' => ['title' => $after['title'] ?? '', 'monthly_cents' => $after['monthly_cents'] ?? 0, 'annual_cents' => $after['annual_cents'] ?? 0],
                ];
            });
        return response()->json(['data' => $rows]);
    }

    public function update(Request $request, string $plan): JsonResponse
    {
        abort_unless(in_array($plan, BillingPlans::KEYS, true), 404);
        $data = $request->validate([
            'title' => ['required', 'string', 'min:2', 'max:80'],
            'description' => ['required', 'string', 'max:800'],
            'features' => ['required', 'array', 'min:1', 'max:12'],
            'features.*' => ['required', 'string', 'min:2', 'max:160'],
            'monthly_cents' => ['required', 'integer', 'min:0', 'max:100000000'],
            'annual_cents' => ['required', 'integer', 'min:0', 'max:1200000000'],
            'is_active' => ['required', 'boolean'],
            'confirm' => ['required', Rule::in(['ALTERAR PLANO'])],
        ]);
        if ($plan === 'free' && ((int) $data['monthly_cents'] !== 0 || (int) $data['annual_cents'] !== 0 || ! $data['is_active'])) {
            throw ValidationException::withMessages(['monthly_cents' => 'O Free permanece ativo e gratuito.']);
        }
        if ($plan !== 'free' && ((int) $data['monthly_cents'] < 100 || (int) $data['annual_cents'] < 100)) {
            throw ValidationException::withMessages(['monthly_cents' => 'Planos pagos precisam ter preços maiores ou iguais a R$ 1,00.']);
        }
        DB::transaction(function () use ($request, $plan, $data): void {
            $row = AcePlan::query()->whereKey($plan)->lockForUpdate()->firstOrFail();
            $before = $row->only(['title', 'description', 'features', 'monthly_cents', 'annual_cents', 'is_active']);
            $row->update([
                'title' => trim($data['title']),
                'description' => trim($data['description']),
                'features' => array_map('trim', $data['features']),
                'monthly_cents' => (int) $data['monthly_cents'],
                'annual_cents' => (int) $data['annual_cents'],
                'is_active' => (bool) $data['is_active'],
                'updated_by' => $request->user()->id,
            ]);
            DB::table('ace_plan_changes')->insert([
                'plan_id' => $plan, 'actor_user_id' => $request->user()->id,
                'before_state' => json_encode($before, JSON_THROW_ON_ERROR),
                'after_state' => json_encode($row->fresh()->only(['title', 'description', 'features', 'monthly_cents', 'annual_cents', 'is_active']), JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);
            DevAdminAudit::create([
                'actor_user_id' => $request->user()->id,
                'tenant_id' => null,
                'action' => 'dev.plan.updated.'.$plan,
                'created_at' => now(),
            ]);
        });
        return response()->json(['data' => BillingPlans::catalog(true), 'message' => 'Plano atualizado. Novos pedidos usarão os valores atuais; pedidos existentes preservam o preço emitido.']);
    }
}
