<?php

namespace App\Services\AceAi;

use App\Models\Client;
use App\Models\GoogleDriveConnection;
use App\Models\Site;
use App\Models\SiteAccess;
use App\Models\Tenant;
use App\Support\PlanFeatures;
use Illuminate\Support\Str;

/** Queries are scoped to the authenticated workspace and return allowlisted fields only. */
class WorkspaceTools
{
    public function siteCount(string $tenantId, string $query = ''): int
    {
        return $this->sites($tenantId, $query)->count();
    }

    public function sites(string $tenantId, string $search = '')
    {
        $q = Site::query()->where('tenant_id', $tenantId);
        if ($search !== '') {
            $terms = array_slice(array_values(array_filter(
                preg_split('/\s+/u', Str::limit(trim($search), 160, '')) ?: [],
                static fn ($term) => mb_strlen($term) >= 2
            )), 0, 5);
            foreach ($terms as $term) {
                $like = $this->like($term);
                $q->where(static function ($builder) use ($like): void {
                    $builder->where('name', 'like', $like)
                        ->orWhere('domain', 'like', $like)
                        ->orWhereHas('client', static fn ($client) => $client->where('name', 'like', $like));
                });
            }
        }
        return $q;
    }

    public function clients(string $tenantId, string $search = ''): array
    {
        $q = Client::query()->where('tenant_id', $tenantId);
        if ($search !== '') {
            $term = $this->like($search);
            $q->where(static fn ($q) => $q->where('name', 'like', $term)->orWhere('trade_name', 'like', $term));
        }
        $count = (clone $q)->count();
        $items = $q->withCount('sites')->orderBy('name')->limit(10)->get(['id', 'name', 'status'])
            ->map(static fn (Client $c) => [
                'id' => (int) $c->id,
                'name' => (string) $c->name,
                'status' => (string) $c->status,
                'sites_count' => (int) $c->sites_count,
            ])->all();
        return ['count' => $count, 'items' => $items];
    }

    public function siteDetails(string $tenantId, string $search): array
    {
        if ($search === '') return ['count' => 0, 'items' => []];
        $q = $this->sites($tenantId, $search)->with('client:id,name')->orderBy('name')->limit(5);
        return ['items' => $q->get([
            'id', 'client_id', 'name', 'domain', 'dns_primary', 'dns_secondary', 'hosting_provider', 'status',
        ])->map(static fn (Site $s) => [
            'id' => $s->id,
            'name' => $s->name,
            'client' => $s->client?->name,
            'domain' => $s->domain,
            'dns_primary' => $s->dns_primary,
            'dns_secondary' => $s->dns_secondary,
            'hosting_provider' => $s->hosting_provider,
            'status' => $s->status,
            'portal_url' => '/sites?site='.$s->id,
        ])->all()];
    }

    public function siteDetailsById(string $tenantId, int $siteId): ?array
    {
        $site = Site::query()->where('tenant_id', $tenantId)->whereKey($siteId)->first([
            'id', 'name', 'domain', 'dns_primary', 'dns_secondary', 'hosting_provider', 'status',
        ]);
        if (! $site) return null;
        return [
            'id' => $site->id, 'name' => $site->name, 'domain' => $site->domain,
            'dns_primary' => $site->dns_primary, 'dns_secondary' => $site->dns_secondary,
            'hosting_provider' => $site->hosting_provider, 'status' => $site->status,
        ];
    }

    public function driveStatus(Tenant $tenant, bool $canManage): array
    {
        $enabled = PlanFeatures::cloud($tenant);
        $accounts = $enabled ? GoogleDriveConnection::query()->where('tenant_id', $tenant->id)
            ->orderBy('id')->get(['id', 'email', 'created_at', 'expires_at']) : collect();
        return [
            'cloud_enabled' => $enabled,
            'configured' => (bool) (config('services.google_drive.client_id') && config('services.google_drive.client_secret')),
            'connected' => $accounts->isNotEmpty(),
            'count' => $accounts->count(),
            'can_manage' => $canManage,
            'accounts' => $accounts->map(static fn (GoogleDriveConnection $c) => [
                'id' => $c->id,
                'email' => $c->email,
                'connected_at' => $c->created_at?->toDateString(),
            ])->all(),
            'note' => 'Vínculo salvo no ACE; não é um teste de conectividade em tempo real.',
        ];
    }

    public function dashboard(Tenant $tenant): array
    {
        $id = $tenant->id;
        return [
            'clients_total' => Client::where('tenant_id', $id)->count(),
            'sites_total' => Site::where('tenant_id', $id)->count(),
            'accesses_total' => SiteAccess::where('tenant_id', $id)->count(),
            'team_total' => $tenant->users()->count(),
        ];
    }

    private function like(string $value): string
    {
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], Str::limit(trim($value), 160, ''));
        return '%'.$escaped.'%';
    }
}
