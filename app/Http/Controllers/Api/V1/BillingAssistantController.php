<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AcePlan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Read-only help available when tenant.member intentionally blocks expired workspaces. */
final class BillingAssistantController extends Controller
{
    private function tenant(Request $request): \App\Models\Tenant
    {
        $user = $request->user();
        abort_unless($user && $user->is_active && ! $user->is_dev, 403);
        $q = $user->tenants()->where('tenants.is_active', true);
        $id = $request->header('X-Tenant-Id');
        $tenant = $q->orderBy('tenants.created_at')->firstOrFail();
        abort_if($id && $id !== $tenant->id, 403);
        return $tenant;
    }

    public function status(Request $request): JsonResponse
    {
        $this->tenant($request);
        return response()->json(['data' => [
            'engine' => 'local', 'model' => null, 'can_write' => false,
            'pages' => ['assinatura_bloqueada' => ['label' => 'Assinatura pendente', 'description' => config('ace_pages.workspace.assinatura_bloqueada.1')]],
        ]])->header('Cache-Control', 'no-store, private');
    }

    public function chat(Request $request): JsonResponse
    {
        $tenant = $this->tenant($request);
        $request->validate([
            'message' => ['required', 'string', 'min:2', 'max:1000'],
            'context.page' => ['required', 'in:assinatura_bloqueada'],
        ]);
        $name = AcePlan::whereKey($tenant->plan)->value('title') ?: ucfirst($tenant->plan);
        $due = $tenant->billing_expires_at?->format('d/m/Y') ?? 'não informado';
        $text = 'Plano: '.$name.'. Situação: '.($tenant->billing_status === 'paid' ? 'pagamento anterior registrado' : 'pagamento pendente ou não confirmado').
            '. Vencimento registrado: '.$due.'. A conta está temporariamente restrita. Somente o Master pode contratar ou renovar na tela atual. ' .
            'O ACE reativa o acesso apenas depois que o servidor verificar a aprovação no Mercado Pago. Pix e boleto podem levar algum tempo para compensar.';
        return response()->json(['data' => ['text' => $text, 'intent' => 'billing_status', 'engine' => 'ace', 'results' => [], 'clients' => [], 'confirmation' => null]])
            ->header('Cache-Control', 'no-store, private');
    }
}
