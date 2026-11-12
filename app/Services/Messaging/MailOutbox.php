<?php

namespace App\Services\Messaging;

use App\Models\AceMessagingSetting;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Durable delivery queue: no emails are sent inside checkout or ticket transactions. */
final class MailOutbox
{
    public const TYPES = [
        'new_signup' => 'Novo cadastro',
        'new_payment' => 'Novo pagamento',
        'invoice_overdue' => 'Fatura em atraso',
        'invoice_renewed' => 'Fatura renovada',
        'ticket_opened' => 'Chamado aberto',
        'ticket_reply_dev' => 'Resposta em chamado',
        'ticket_reply_user' => 'Resposta do suporte à empresa',
        'customer_signup' => 'Cadastro confirmado (cliente)',
        'customer_payment' => 'Pagamento confirmado (cliente)',
        'customer_overdue' => 'Fatura em atraso (cliente)',
        'customer_renewed' => 'Assinatura renovada (cliente)',
        'customer_ticket_opened' => 'Chamado aberto (cliente)',
    ];

    public function process(int $limit = 30): array
    {
        $mailer = app(ResendMailer::class);
        $setting = $mailer->setting();
        if (! $mailer->ready($setting)) return ['sent' => 0, 'failed' => 0, 'pending_setup' => true];

        $preferences = $setting->event_preferences ?? [];
        $emails = User::query()->where('is_dev', true)->where('is_active', true)
            ->get(['dev_notification_emails'])->flatMap(function (User $user): array {
                return is_array($user->dev_notification_emails) ? $user->dev_notification_emails : [];
            })->map(fn ($email) => Str::lower(trim((string) $email)))
            ->filter(fn ($email) => (bool) filter_var($email, FILTER_VALIDATE_EMAIL))->unique()->values()->all();

        $events = DB::table('ace_mail_events')->whereNull('routed_at')->whereNull('cancelled_at')
            ->orderBy('id')->limit(100)->get();
        foreach ($events as $event) {
            if (($preferences[$event->event_type] ?? true) === false) {
                DB::table('ace_mail_events')->where('id', $event->id)->update(['routed_at' => now()]);
                continue;
            }
            $payload = json_decode($event->payload, true) ?: [];
            if (in_array($event->event_type, ['invoice_overdue', 'customer_overdue'], true) && ! $this->stillOverdue($payload)) {
                DB::table('ace_mail_events')->where('id', $event->id)->update(['cancelled_at' => now()]);
                continue;
            }
            // Customer events must never fall back to the DEV distribution list.
            $isCustomer = str_starts_with($event->event_type, 'customer_') || $event->event_type === 'ticket_reply_user';
            $recipients = $event->direct_email ? [$event->direct_email] : ($isCustomer ? [] : $emails);
            if (! count($recipients)) continue; // wait for DEV profile notification addresses
            DB::transaction(function () use ($event, $recipients): void {
                foreach ($recipients as $email) {
                    DB::table('ace_mail_deliveries')->insertOrIgnore([
                        'event_id' => $event->id, 'recipient_email' => $email,
                        'status' => 'pending', 'attempts' => 0,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
                DB::table('ace_mail_events')->where('id', $event->id)->whereNull('routed_at')
                    ->update(['routed_at' => now()]);
            });
        }

        $sent = 0; $failed = 0;
        $deliveries = DB::table('ace_mail_deliveries as d')
            ->join('ace_mail_events as e', 'e.id', '=', 'd.event_id')
            ->whereIn('d.status', ['pending', 'failed'])
            ->where(function ($q): void { $q->whereNull('d.next_attempt_at')->orWhere('d.next_attempt_at', '<=', now()); })
            ->whereNull('e.cancelled_at')->orderBy('d.id')->limit(max(1, min($limit, 100)))
            ->get(['d.*', 'e.event_key', 'e.event_type', 'e.payload']);
        foreach ($deliveries as $delivery) {
            $payload = json_decode($delivery->payload, true) ?: [];
            if (in_array($delivery->event_type, ['invoice_overdue', 'customer_overdue'], true) && ! $this->stillOverdue($payload)) {
                DB::table('ace_mail_events')->where('id', $delivery->event_id)->update(['cancelled_at' => now()]);
                continue; // Do not send stale overdue notices after successful renewal.
            }
            [$subject, $body] = $this->format($delivery->event_type, $payload);
            try {
                $resendId = $mailer->send($delivery->recipient_email, $subject, $body,
                    'ace-delivery-'.$delivery->id, $setting);
                DB::table('ace_mail_deliveries')->where('id', $delivery->id)->update([
                    'status' => 'sent', 'resend_id' => $resendId,
                    'sent_at' => now(), 'last_error' => null, 'attempts' => $delivery->attempts + 1,
                    'updated_at' => now(),
                ]);
                $sent++;
            } catch (\Throwable $e) {
                $attempt = min(1000, (int) $delivery->attempts + 1);
                DB::table('ace_mail_deliveries')->where('id', $delivery->id)->update([
                    'status' => 'failed', 'attempts' => $attempt,
                    'last_error' => Str::limit($e->getMessage(), 255, ''),
                    'next_attempt_at' => now()->addMinutes(min(360, 2 ** min(8, $attempt))),
                    'updated_at' => now(),
                ]);
                $failed++;
            }
        }
        return ['sent' => $sent, 'failed' => $failed, 'pending_setup' => false];
    }

    private function stillOverdue(array $payload): bool
    {
        $tenant = Tenant::find($payload['tenant_id'] ?? '');
        if (! empty($payload['manual'])) {
            return $tenant && $tenant->billing_provider !== 'mercadopago'
                && $tenant->billing_status === 'overdue';
        }
        return $tenant && $tenant->billing_provider === 'mercadopago'
            && $tenant->billing_expires_at && $tenant->billing_expires_at->isPast()
            && $tenant->billing_status === 'paid'
            && $tenant->billing_expires_at->toIso8601String() === ($payload['expires_at'] ?? '');
    }

    private function format(string $type, array $p): array
    {
        $site = rtrim((string) (config('ace_billing.public_url') ?: config('app.url')), '/');
        $company = (string) ($p['company'] ?? 'Não informado');
        $ticket = (string) ($p['ticket'] ?? '');
        $money = isset($p['amount_cents']) ? 'R$ '.number_format(((int) $p['amount_cents']) / 100, 2, ',', '.') : 'Não informado';
        return match ($type) {
            'new_signup' => ['ACE · Novo cadastro', "Nova empresa cadastrada no ACE.\nEmpresa: {$company}\nPlano: ".($p['plan'] ?? '—')."\nResponsável: ".($p['name'] ?? '—')."\nAcesse: {$site}/dev"],
            'new_payment' => ['ACE · Novo pagamento confirmado', "Pagamento confirmado pelo gateway.\nEmpresa: {$company}\nPlano: ".($p['plan'] ?? '—')."\nValor: {$money}\nPedido: ".($p['order'] ?? '—')."\nAcesse: {$site}/dev"],
            'invoice_overdue' => ['ACE · Fatura em atraso', (! empty($p['manual'])
                ? "Situação financeira marcada manualmente como atrasada no painel DEV.\nEmpresa: {$company}"
                : "Prazo da assinatura expirou.\nEmpresa: {$company}\nVencimento: ".($p['expires_at'] ?? '—'))
                ."\nAcesse: {$site}/dev"],
            'invoice_renewed' => ['ACE · Fatura renovada', "Renovação confirmada pelo gateway.\nEmpresa: {$company}\nValor: {$money}\nNova validade: ".($p['expires_at'] ?? '—')."\nAcesse: {$site}/dev"],
            'ticket_opened' => ['ACE · Novo chamado '.$ticket, "Chamado aberto no suporte.\nEmpresa: {$company}\nAssunto: ".($p['subject'] ?? '—')."\nCategoria: ".($p['category'] ?? '—')."\nAcesse: {$site}/dev"],
            'ticket_reply_dev' => ['ACE · Nova resposta em chamado '.$ticket, "O usuário respondeu ao chamado.\nEmpresa: {$company}\nAssunto: ".($p['subject'] ?? '—')."\nAcesse: {$site}/dev"],
            'ticket_reply_user' => ['ACE · Resposta do suporte '.$ticket, "A empresa recebeu uma resposta no chamado.\nAssunto: ".($p['subject'] ?? '—')."\nAcesse o ACE para consultar o atendimento: {$site}/chamados"],
            'customer_ticket_opened' => ['ACE · Chamado recebido '.$ticket, "Recebemos um chamado da empresa {$company}.\nAssunto: ".($p['subject'] ?? '—')."\nConsulte o atendimento no ACE: {$site}/chamados"],
            'customer_signup' => ['ACE · Cadastro confirmado', "O cadastro da empresa {$company} foi concluído.\nPlano: ".($p['plan'] ?? '—')."\nAcesse: {$site}"],
            'customer_payment' => ['ACE · Pagamento confirmado', "Recebemos a confirmação do pagamento da empresa {$company}.\nValor: {$money}\nPedido: ".($p['order'] ?? '—')."\nAcesse o ACE para detalhes: {$site}"],
            'customer_overdue' => ['ACE · Aviso de faturamento', "A assinatura da empresa {$company} está em atraso.\nConfira as informações de cobrança no ACE: {$site}"],
            'customer_renewed' => ['ACE · Assinatura renovada', "A assinatura da empresa {$company} foi renovada.\nValor: {$money}\nNova validade: ".($p['expires_at'] ?? '—')."\nAcesse: {$site}"],
            default => ['ACE · Atualização', 'Existe uma atualização no ACE. Acesse o painel para detalhes.'],
        };
    }
}
