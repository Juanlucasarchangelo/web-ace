<?php

namespace App\Services\AceAi;

use App\Models\Site;
use Illuminate\Support\Str;

/**
 * Ferramenta read-only: só retorna dados públicos de sites do tenant atual.
 * Nunca serializa Site completo, acessos, notas ou campos de credenciais.
 */
class SiteSearchTool
{
    public function execute(string $tenantId, string $query): array
    {
        $search = trim($query);
        $builder = Site::query()
            ->select(['id', 'tenant_id', 'client_id', 'name', 'domain'])
            ->where('tenant_id', $tenantId)
            ->with('client:id,name');

        if ($search !== '') {
            // A pesquisa é parametrizada e os caracteres curinga são tratados como literais.
            $terms = array_slice(array_values(array_filter(
                preg_split('/\s+/u', $search) ?: [],
                static fn ($term) => mb_strlen($term) >= 2
            )), 0, 5);
            foreach ($terms as $term) {
                $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
                $builder->where(function ($builder) use ($escaped): void {
                    $builder->where('name', 'like', '%'.$escaped.'%')
                        ->orWhere('domain', 'like', '%'.$escaped.'%')
                        ->orWhereHas('client', static function ($clientQuery) use ($escaped): void {
                            $clientQuery->where('name', 'like', '%'.$escaped.'%');
                        });
                });
            }
        }

        return $builder->orderBy('name')->limit(8)->get()->map(static function (Site $site): array {
            $domain = trim((string) $site->domain);
            $safeDomain = (bool) preg_match('/^(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,63}$/i', $domain);
            return [
                'id' => (int) $site->id,
                'name' => (string) $site->name,
                'client' => (string) ($site->client?->name ?? ''),
                'domain' => $domain,
                'portal_url' => '/sites?site='.(int) $site->id,
                'website_url' => $safeDomain ? 'https://'.Str::lower($domain) : null,
            ];
        })->all();
    }
}
