<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AceSupportMessage;
use App\Models\AceSupportTicket;
use App\Models\DevAdminAudit;
use App\Services\Messaging\MailEvents;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class DevSupportTicketController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['sometimes', Rule::in(['all','open','answered','waiting_user','closed'])],
            'search' => ['nullable', 'string', 'max:100'],
        ]);
        $query = AceSupportTicket::query()->with(['tenant:id,name', 'creator:id,name,email']);
        if (isset($filters['status']) && $filters['status'] !== 'all') $query->where('status', $filters['status']);
        if (! empty($filters['search'])) {
            $term = '%'.addcslashes($filters['search'], '%_\\').'%';
            $query->where(function ($q) use ($term) {
                $q->where('subject', 'like', $term)->orWhereHas('tenant', fn ($t) => $t->where('name','like',$term));
            });
        }
        return response()->json(['data' => $query->orderByRaw("CASE WHEN status = 'closed' THEN 1 ELSE 0 END")
            ->orderByDesc('updated_at')->paginate(30)]);
    }

    public function show(string $ticket): JsonResponse
    {
        return response()->json(['data' => AceSupportTicket::query()->with([
            'tenant:id,name', 'creator:id,name,email', 'messages.author:id,name',
        ])->findOrFail($ticket)]);
    }

    public function reply(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['message' => ['required', 'string', 'min:2', 'max:10000']]);
        DB::transaction(function () use ($request, $id, $data): void {
            $ticket = AceSupportTicket::query()->whereKey($id)->lockForUpdate()->firstOrFail();
            $message = AceSupportMessage::create([
                'ticket_id' => $ticket->id, 'user_id' => $request->user()->id,
                'sender_role' => 'dev', 'body' => trim($data['message']),
            ]);
            $ticket->forceFill(['status' => 'answered', 'closed_at' => null, 'last_reply_at' => now()])->save();
            // Respostas escritas pelo DEV permanecem no historico, sem e-mail automatico.
            DevAdminAudit::create(['actor_user_id' => $request->user()->id,
                'tenant_id' => $ticket->tenant_id, 'action' => 'support.ticket.replied', 'created_at' => now()]);
        });
        return response()->json(['message' => 'Resposta enviada ao chamado.']);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['open','waiting_user','answered','closed'])],
            'priority' => ['required', Rule::in(['normal','high'])],
        ]);
        DB::transaction(function () use ($id, $request, $data): void {
            $ticket = AceSupportTicket::query()->whereKey($id)->lockForUpdate()->firstOrFail();
            $wasClosed = $ticket->status === 'closed';
            $ticket->forceFill([
                'status' => $data['status'], 'priority' => $data['priority'],
                'closed_at' => $data['status'] === 'closed' ? ($wasClosed ? $ticket->closed_at ?? now() : now()) : null,
            ])->save();
            if ($data['status'] === 'closed' && ! $wasClosed) {
                \App\Services\Messaging\SupportClosureNotifications::record($ticket);
            }
            DevAdminAudit::create(['actor_user_id' => $request->user()->id,
                'tenant_id' => $ticket->tenant_id, 'action' => 'support.ticket.updated', 'created_at' => now()]);
        });
        return response()->json(['message' => 'Chamado atualizado.']);
    }
}
