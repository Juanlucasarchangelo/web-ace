<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\SiteAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VaultController extends AceController
{
    public function index(Request $request): JsonResponse
    {
        $query = SiteAccess::query()
            ->where('tenant_id', $this->tenant($request)->id)
            ->with(['site:id,tenant_id,client_id,name,domain', 'site.client:id,name']);
        $tenantId = $this->tenant($request)->id;
        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $query->where(function ($q) use ($search, $tenantId): void {
                $q->where('label', 'like', '%'.$search.'%')
                    ->orWhere('service', 'like', '%'.$search.'%')
                    ->orWhere('username', 'like', '%'.$search.'%')
                    ->orWhereHas('site', fn ($sq) => $sq->where('tenant_id', $tenantId)
                        ->where(fn ($inner) => $inner->where('name', 'like', '%'.$search.'%')
                            ->orWhere('domain', 'like', '%'.$search.'%')));
            });
        }
        if (in_array($request->query('category'), ['email', 'store', 'social', 'hosting', 'ftp', 'other'], true)) {
            $query->where('category', $request->query('category'));
        }
        return response()->json(['data' => $query->latest()->paginate(20)]);
    }
}
