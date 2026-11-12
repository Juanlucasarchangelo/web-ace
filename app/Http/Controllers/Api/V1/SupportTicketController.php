<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AceSupportMessage;
use App\Models\AceSupportTicket;
use App\Models\Tenant;
use App\Services\Messaging\MailEvents;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Member support intentionally stays accessible for billing-blocked accounts. */
final class SupportTicketController extends Controller
{
    private function tenant(Request $request): Tenant
    {
        $user = $request->user();
        abort_unless($user && $user->is_active && ! $user->is_dev, 403);
        $tenant = $user->tenants()->where('tenants.is_active', true)->wherePivot('is_active', true)
            ->orderBy('tenants.created_at')->first();
        abort_unless($tenant, 403);
        abort_if($request->header('X-Tenant-Id') && $request->header('X-Tenant-Id') !== $tenant->id, 403);
        return $tenant;
    }

    private function ticket(Request $request, string $id): AceSupportTicket
    {
        $tenant = $this->tenant($request);
        $ticket = AceSupportTicket::query()->where('tenant_id', $tenant->id)->findOrFail($id);
        if (! in_array($tenant->pivot->role, ['owner', 'admin'], true)) {
            abort_unless($ticket->created_by_user_id === $request->user()->id, 403);
        }
        return $ticket;
    }

    public function index(Request $request): JsonResponse
    {
        $tenant = $this->tenant($request);
        $query = AceSupportTicket::query()->where('tenant_id', $tenant->id)->with('creator:id,name');
        if (! in_array($tenant->pivot->role, ['owner', 'admin'], true)) {
            $query->where('created_by_user_id', $request->user()->id);
        }
        return response()->json(['data' => $query->orderByDesc('updated_at')->paginate(20)]);
    }

    public function show(Request $request, string $ticket): JsonResponse
    {
        return response()->json(['data' => $this->ticket($request, $ticket)
            ->load(['creator:id,name', 'messages.author:id,name'])]);
    }

    public function store(Request $request): JsonResponse
    {
        $tenant = $this->tenant($request);
        $data = $request->validate([
            'subject' => ['required', 'string', 'min:5', 'max:180'],
            'category' => ['required', Rule::in(['question','billing','technical','request','other'])],
            'priority' => ['required', Rule::in(['normal','high'])],
            'message' => ['required', 'string', 'min:10', 'max:10000'],
        ]);
        $ticket = DB::transaction(function () use ($tenant, $request, $data) {
            $ticket = AceSupportTicket::create([
                'tenant_id' => $tenant->id, 'created_by_user_id' => $request->user()->id,
                'subject' => trim($data['subject']), 'category' => $data['category'],
                'priority' => $data['priority'], 'status' => 'open', 'last_reply_at' => now(),
            ]);
            AceSupportMessage::create([
                'ticket_id' => $ticket->id, 'user_id' => $request->user()->id,
                'sender_role' => 'member', 'body' => trim($data['message']),
            ]);
            MailEvents::record('support-open:'.$ticket->id, 'ticket_opened', [
                'ticket' => substr($ticket->id, 0, 8), 'subject' => $ticket->subject,
                'category' => $ticket->category, 'company' => $tenant->name,
            ]);
            if ($email = \App\Services\Messaging\CustomerRecipient::forTenant($tenant)) {
                MailEvents::record('customer-support-open:'.$ticket->id, 'customer_ticket_opened', [
                    'ticket' => substr($ticket->id, 0, 8), 'subject' => $ticket->subject,
                    'company' => $tenant->name,
                ], $email);
            }
            return $ticket;
        });
        return response()->json(['data' => $ticket, 'message' => 'Chamado aberto.'], 201);
    }

    public function reply(Request $request, string $id): JsonResponse
    {
        $ticket = $this->ticket($request, $id);
        $data = $request->validate(['message' => ['required', 'string', 'min:2', 'max:10000']]);
        DB::transaction(function () use ($ticket, $request, $data): void {
            $locked = AceSupportTicket::whereKey($ticket->id)->lockForUpdate()->firstOrFail();
            $message = AceSupportMessage::create([
                'ticket_id' => $locked->id, 'user_id' => $request->user()->id,
                'sender_role' => 'member', 'body' => trim($data['message']),
            ]);
            $locked->forceFill(['status' => 'open', 'closed_at' => null, 'last_reply_at' => now()])->save();
            $company = Tenant::whereKey($locked->tenant_id)->value('name');
            MailEvents::record('support-member-reply:'.$message->id, 'ticket_reply_dev', [
                'ticket' => substr($locked->id, 0, 8), 'subject' => $locked->subject, 'company' => $company,
            ]);
        });
        return response()->json(['message' => 'Resposta registrada.']);
    }

    public function close(Request $request, string $id): JsonResponse
    {
        $ticket = $this->ticket($request, $id);
        $ticket->forceFill(['status' => 'closed', 'closed_at' => now()])->save();
        return response()->json(['message' => 'Chamado encerrado.']);
    }
}
