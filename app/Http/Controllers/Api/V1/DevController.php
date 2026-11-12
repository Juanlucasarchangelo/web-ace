<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DevAdminAudit;
use App\Models\DevOperationalIssue;
use App\Models\GoogleDriveConnection;
use App\Models\Tenant;
use App\Services\Messaging\MailEvents;
use App\Models\User;
use App\Services\GoogleDriveHealth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Intentionally metadata-only. Never query clients, sites, accesses, file content or OAuth token fields. */
class DevController extends Controller
{
    private function log(Request $request, string $action, ?Tenant $tenant = null): void
    {
        DevAdminAudit::create([
            'actor_user_id' => $request->user()->id,
            'tenant_id' => $tenant?->id, 'action' => $action, 'created_at' => now(),
        ]);
    }

    public function overview(): JsonResponse
    {
        $tenantCount = Tenant::count();
        return response()->json(['data' => [
            'tenants' => $tenantCount,
            'active_tenants' => Tenant::where('is_active', true)->count(),
            'users' => User::count(),
            'drive_connections' => GoogleDriveConnection::count(),
            'tenants_without_drive' => Tenant::where('is_active', true)->whereDoesntHave('driveConnections')->count(),
            'drive_reconnect_required' => GoogleDriveConnection::where('health_status', 'reconnect_required')->count(),
            'drive_errors' => GoogleDriveConnection::where('health_status', 'error')->count(),
            'open_issues' => DevOperationalIssue::whereNull('resolved_at')->count(),
            'billing_attention' => Tenant::where('is_active', true)->where(function ($q): void {
                $q->whereIn('billing_status', ['overdue', 'pending'])
                  ->orWhere(function ($paid): void {
                      $paid->where('billing_provider', 'mercadopago')
                           ->where(function ($expired): void {
                               $expired->whereNull('billing_expires_at')->orWhere('billing_expires_at', '<=', now());
                           });
                  });
            })->count(),
            'billing_unverified' => Tenant::where('is_active', true)->where('billing_status', 'unverified')->count(),
        ]]);
    }

    public function tenants(Request $request): JsonResponse
    {
        $filter = $request->validate(['search' => ['sometimes', 'nullable', 'string', 'max:100']]);
        $query = Tenant::query()->select(['id', 'name', 'slug', 'is_active', 'plan', 'seat_limit', 'billing_status', 'billing_checked_at', 'billing_provider', 'billing_cycle', 'billing_expires_at'])
            ->withCount(['users', 'driveConnections']);
        if ($search = trim((string) ($filter['search'] ?? ''))) {
            $query->where(fn ($q) => $q->where('name', 'like', '%'.$search.'%')->orWhere('slug', 'like', '%'.$search.'%'));
        }
        $page = $query->orderBy('name')->paginate(25);
        $connections = GoogleDriveConnection::query()->whereIn('tenant_id', $page->getCollection()->pluck('id'))
            ->get(['id', 'tenant_id', 'health_status'])->groupBy('tenant_id');
        $page->getCollection()->transform(function (Tenant $tenant) use ($connections): array {
            $items = $connections->get($tenant->id, collect());
            return [
                'id' => $tenant->id, 'name' => $tenant->name, 'slug' => $tenant->slug,
                'is_active' => $tenant->is_active, 'plan' => $tenant->plan,
                'billing_source' => $tenant->billing_provider,
                'seat_limit' => $tenant->seat_limit, 'members' => $tenant->users_count,
                'drive_count' => $tenant->drive_connections_count,
                'drive_status' => $items->isEmpty() ? 'not_connected'
                    : ($items->contains('health_status', 'reconnect_required') ? 'reconnect_required'
                    : ($items->contains('health_status', 'error') ? 'error'
                    : ($items->every(fn ($c) => $c->health_status === 'connected') ? 'connected' : 'unknown'))),
                'billing_status' => $tenant->billing_provider === 'mercadopago' && (! $tenant->billing_expires_at || $tenant->billing_expires_at->isPast()) ? 'overdue' : $tenant->billing_status,
                'billing_checked_at' => $tenant->billing_checked_at?->toIso8601String(),
                ];
        });
        return response()->json(['data' => $page]);
    }

    public function users(Request $request): JsonResponse
    {
        $filter = $request->validate(['search' => ['sometimes', 'nullable', 'string', 'max:100']]);
        $query = User::query()->select(['id', 'name', 'email', 'is_active', 'is_dev', 'created_at']);
        if ($search = trim((string) ($filter['search'] ?? ''))) {
            $query->where(fn ($q) => $q->where('name', 'like', '%'.$search.'%')->orWhere('email', 'like', '%'.$search.'%'));
        }
        $page = $query->with(['tenants' => fn ($q) => $q->select(['tenants.id', 'tenants.name'])])
            ->orderBy('name')->paginate(25);
        $page->getCollection()->transform(fn (User $user) => [
            'id' => $user->id, 'name' => $user->name, 'email' => $user->email,
            'is_active' => $user->is_active, 'is_dev' => $user->is_dev,
            'created_at' => $user->created_at?->toIso8601String(),
            'workspaces' => $user->tenants->map(fn ($tenant) => [
                'id' => $tenant->id, 'name' => $tenant->name, 'role' => $tenant->pivot->role,
            ])->values()->all(),
        ]);
        return response()->json(['data' => $page]);
    }

    public function connections(Tenant $tenant): JsonResponse
    {
        $records = GoogleDriveConnection::query()->where('tenant_id', $tenant->id)
            ->orderBy('id')->get(['id', 'tenant_id', 'connected_by', 'email', 'health_status',
                'last_error_code', 'last_error_at', 'last_checked_at', 'last_success_at', 'created_at']);
        $operators = User::query()->whereIn('id', $records->pluck('connected_by')->filter()->unique())
            ->pluck('name', 'id');
        return response()->json(['data' => [
            'tenant' => ['id' => $tenant->id, 'name' => $tenant->name],
            'accounts' => $records->map(fn ($conn) => [
                'id' => $conn->id, 'email' => $conn->email,
                'connected_by' => $conn->connected_by ? [
                    'id' => $conn->connected_by, 'name' => $operators->get($conn->connected_by),
                ] : null,
                'status' => $conn->health_status, 'error_code' => $conn->last_error_code,
                'message' => $conn->last_error_code ? GoogleDriveHealth::message($conn->last_error_code) : null,
                'connected_at' => $conn->created_at?->toIso8601String(),
                'last_checked_at' => $conn->last_checked_at?->toIso8601String(),
                'last_success_at' => $conn->last_success_at?->toIso8601String(),
                'last_error_at' => $conn->last_error_at?->toIso8601String(),
            ])->values()->all(),
        ]]);
    }

    public function checkConnection(Request $request, Tenant $tenant, int $connection, GoogleDriveHealth $health): JsonResponse
    {
        $account = GoogleDriveConnection::query()->where('tenant_id', $tenant->id)->findOrFail($connection);
        $result = $health->check($account);
        $this->log($request, 'dev.drive.checked', $tenant);
        return response()->json(['data' => $result]);
    }

    public function audit(): JsonResponse
    {
        $page = DevAdminAudit::query()->with([
            'tenant' => fn ($q) => $q->select(['id', 'name']),
            'actor' => fn ($q) => $q->select(['id', 'name']),
        ])->orderByDesc('id')->paginate(40);
        $page->getCollection()->transform(fn ($record) => [
            'id' => $record->id,
            'actor' => $record->actor?->name,
            'tenant' => $record->tenant?->name,
            'action' => $record->action,
            'created_at' => $record->created_at?->toIso8601String(),
        ]);
        return response()->json(['data' => $page]);
    }

    public function issues(Request $request): JsonResponse
    {
        $filter = $request->validate(['state' => ['sometimes', 'in:all,open,resolved']]);
        $query = DevOperationalIssue::query()->with(['tenant' => fn ($q) => $q->select(['id', 'name'])]);
        if (($filter['state'] ?? 'open') === 'open') $query->whereNull('resolved_at');
        if (($filter['state'] ?? 'open') === 'resolved') $query->whereNotNull('resolved_at');
        $page = $query->orderByDesc('id')->paginate(40);
        $page->getCollection()->transform(fn ($issue) => [
            'id' => $issue->id, 'tenant' => $issue->tenant ? ['id' => $issue->tenant->id, 'name' => $issue->tenant->name] : null,
            'connection_id' => $issue->connection_id, 'source' => $issue->source,
            'code' => $issue->code, 'summary' => $issue->summary, 'severity' => $issue->severity,
            'created_at' => $issue->created_at?->toIso8601String(),
            'resolved_at' => $issue->resolved_at?->toIso8601String(),
        ]);
        return response()->json(['data' => $page]);
    }

    public function resolveIssue(Request $request, DevOperationalIssue $issue): JsonResponse
    {
        if (! $issue->resolved_at) $issue->forceFill(['resolved_at' => now()])->save();
        $this->log($request, 'dev.issue.resolved', $issue->tenant);
        return response()->json(['message' => 'Ocorrência marcada como resolvida pelo DEV. O histórico será mantido.']);
    }

    public function setSeats(Request $request, Tenant $tenant): JsonResponse
    {
        $data = $request->validate(['seat_limit' => ['required', 'integer', 'min:1', 'max:5000']]);
        $limit = (int) $data['seat_limit'];
        DB::transaction(function () use ($tenant, $limit): void {
            $locked = Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $used = DB::table('tenant_user')->where('tenant_id', $locked->id)->count();
            if ($limit < $used) {
                throw ValidationException::withMessages(['seat_limit' => "Esta equipe já possui {$used} usuário(s). Remova os excedentes antes de reduzir o limite."]);
            }
            $locked->update(['seat_limit' => $limit]);
        });
        $this->log($request, 'dev.seats.updated', $tenant);
        return response()->json(['data' => ['tenant_id' => $tenant->id, 'seat_limit' => $limit]]);
    }

    public function setBilling(Request $request, Tenant $tenant): JsonResponse
    {
        abort_if($tenant->billing_provider === 'mercadopago', 409, 'O status financeiro é confirmado pelo Mercado Pago e não pode ser alterado manualmente.');
        $data = $request->validate([
            'billing_status' => ['required', Rule::in(['unverified', 'pending', 'paid', 'overdue', 'canceled'])],
            'plan' => ['sometimes', Rule::in(['free', 'cloud', 'pro'])],
        ]);
        DB::transaction(function () use ($tenant, $data): void {
            $locked = Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $previousStatus = $locked->billing_status;
            $locked->update([
                'billing_status' => $data['billing_status'],
                'billing_checked_at' => now(),
                'plan' => $data['plan'] ?? $locked->plan,
            ]);
            if ($previousStatus !== 'overdue' && $locked->billing_status === 'overdue') {
                MailEvents::record('manual-overdue:'.$locked->id.':'.$locked->billing_checked_at->timestamp,
                    'invoice_overdue', [
                        'tenant_id' => $locked->id, 'company' => $locked->name,
                        'manual' => true, 'expires_at' => null,
                    ]);
                if ($email = \App\Services\Messaging\CustomerRecipient::forTenant($locked)) {
                    MailEvents::record('customer-manual-overdue:'.$locked->id.':'.$locked->billing_checked_at->timestamp,
                        'customer_overdue', [
                            'tenant_id' => $locked->id, 'company' => $locked->name,
                            'manual' => true, 'expires_at' => null,
                        ], $email);
                }
            }
        });
        $tenant->refresh();
        $this->log($request, 'dev.billing.updated', $tenant);
        return response()->json(['data' => [
            'tenant_id' => $tenant->id, 'billing_status' => $tenant->billing_status,
            'plan' => $tenant->plan, 'billing_source' => $tenant->billing_provider === 'mercadopago' ? 'mercadopago' : 'manual',
                'billing_expires_at' => $tenant->billing_expires_at?->toIso8601String(),
                'billing_cycle' => $tenant->billing_cycle,
        ]]);
    }
}
