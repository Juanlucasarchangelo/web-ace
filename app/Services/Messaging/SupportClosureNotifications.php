<?php

namespace App\Services\Messaging;

use App\Models\AceSupportTicket;
use Illuminate\Support\Str;

/** Called only on an actual transition into the closed state, inside a DB transaction. */
final class SupportClosureNotifications
{
    public static function record(AceSupportTicket $ticket): void
    {
        $payload = [
            'ticket' => substr($ticket->id, 0, 8),
            'subject' => $ticket->subject,
            'company' => $ticket->tenant?->name ?? 'Nao informado',
        ];
        // A new transition (including after reopening) is a new event; repeated saves are not.
        $transitionId = (string) Str::uuid();
        MailEvents::record('support-closed:'.$ticket->id.':'.$transitionId, 'ticket_closed', $payload);

        // Company destination is the verified checkout/billing e-mail when available.
        $tenant = $ticket->tenant;
        $customerEmail = $tenant ? CustomerRecipient::forTenant($tenant) : null;
        if ($customerEmail) {
            MailEvents::record('customer-support-closed:'.$ticket->id.':'.$transitionId,
                'customer_ticket_closed', $payload, $customerEmail);
        }
    }
}
