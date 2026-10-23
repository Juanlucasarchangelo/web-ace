<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\AuditEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ActivityController extends AceController
{
    public function index(Request $request): JsonResponse
    {
        $events = AuditEvent::where('tenant_id', $this->tenant($request)->id)
            ->with('user:id,name')->latest('created_at')->paginate(30);
        return response()->json(['data' => $events]);
    }
}
