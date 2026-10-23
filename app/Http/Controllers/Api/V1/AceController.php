<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

abstract class AceController extends Controller
{
    protected function tenant(Request $request): Tenant
    {
        return $request->attributes->get('current_tenant');
    }

    protected function role(Request $request): string
    {
        return (string) $this->tenant($request)->pivot->role;
    }

    protected function canWrite(Request $request): void
    {
        abort_unless(in_array($this->role($request), ['owner', 'admin'], true), 403, 'Sem permissão para alterar registros.');
    }

    protected function ownerOnly(Request $request): void
    {
        abort_unless($this->role($request) === 'owner', 403, 'Apenas o proprietário pode executar esta operação.');
    }

    protected function logAction(Request $request, string $action): void
    {
        AuditEvent::query()->create([
            'tenant_id' => $this->tenant($request)->id,
            'user_id' => $request->user()->id,
            'action' => $action,
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 512, ''),
            'created_at' => now(),
        ]);
    }
}
