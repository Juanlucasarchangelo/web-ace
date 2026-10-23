<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Client;
use App\Models\Site;
use App\Models\SiteAccess;
use App\Models\AuditEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends AceController
{
    public function index(Request $request): JsonResponse
    {
        $tenantId = $this->tenant($request)->id;
        $clients = Client::query()->where('tenant_id', $tenantId);
        $sites = Site::query()->where('tenant_id', $tenantId);

        return response()->json(['data' => [
            'clients_total' => (clone $clients)->count(),
            'clients_active' => (clone $clients)->where('status', 'active')->count(),
            'clients_inactive' => (clone $clients)->where('status', 'inactive')->count(),
            'sites_total' => (clone $sites)->count(),
            'sites_active' => (clone $sites)->where('status', 'active')->count(),
            'sites_inactive' => (clone $sites)->where('status', 'inactive')->count(),
            'sites_maintenance' => (clone $sites)->where('status', 'maintenance')->count(),
            'accesses_total' => SiteAccess::where('tenant_id', $tenantId)->count(),
            'team_total' => $this->tenant($request)->users()->count(),
            'latest_sites' => (clone $sites)->with('client:id,name')->latest()->limit(6)->get(['id', 'client_id', 'name', 'domain', 'status', 'updated_at']),
            'recent_activity' => AuditEvent::where('tenant_id', $tenantId)
                ->with('user:id,name')->latest('created_at')->limit(7)->get(['id', 'user_id', 'action', 'created_at']),
        ]]);
    }
}
