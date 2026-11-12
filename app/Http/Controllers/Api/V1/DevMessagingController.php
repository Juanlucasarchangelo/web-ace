<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AceMessagingSetting;
use App\Models\DevAdminAudit;
use App\Services\Messaging\MailOutbox;
use App\Services\Messaging\ResendMailer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class DevMessagingController extends Controller
{
    public function index(): JsonResponse
    {
        $setting = AceMessagingSetting::find(1);
        $data = [
            'enabled' => (bool) ($setting?->enabled ?? false),
            'configured' => (bool) ($setting?->resend_api_key),
            'from_name' => $setting?->from_name ?: 'ACE',
            'from_email' => $setting?->from_email ?: '',
            'event_preferences' => array_replace(array_fill_keys(array_keys(MailOutbox::TYPES), true), $setting?->event_preferences ?? []),
            'event_types' => MailOutbox::TYPES,
            'statistics' => [
                'waiting' => DB::table('ace_mail_events')->whereNull('routed_at')->whereNull('cancelled_at')->count(),
                'pending' => DB::table('ace_mail_deliveries')->whereIn('status', ['pending','failed'])->count(),
                'sent' => DB::table('ace_mail_deliveries')->where('status', 'sent')->count(),
            ],
            'deliveries' => DB::table('ace_mail_deliveries as d')
                ->join('ace_mail_events as e', 'e.id', '=', 'd.event_id')
                ->orderByDesc('d.id')->limit(35)->get([
                    'd.id', 'd.recipient_email', 'd.status', 'd.attempts', 'd.sent_at', 'd.last_error',
                    'd.next_attempt_at', 'd.created_at', 'e.event_type',
                ]),
        ];
        return response()->json(['data' => $data])->header('Cache-Control', 'no-store');
    }

    public function save(Request $request): JsonResponse
    {
        $types = array_keys(MailOutbox::TYPES);
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'from_name' => ['required', 'string', 'min:2', 'max:120'],
            'from_email' => ['required', 'email:rfc', 'max:255'],
            'resend_api_key' => ['nullable', 'string', 'min:10', 'max:300', 'regex:/^re_[A-Za-z0-9_\-]+$/'],
            'clear_key' => ['sometimes', 'boolean'],
            'event_preferences' => ['required', 'array'],
            'event_preferences.*' => ['boolean'],
        ]);
        if (array_diff(array_keys($data['event_preferences']), $types)) {
            throw ValidationException::withMessages(['event_preferences' => 'Evento não reconhecido.']);
        }
        $setting = AceMessagingSetting::firstOrNew(['id' => 1]);
        $setting->enabled = (bool) $data['enabled'];
        $setting->from_name = trim($data['from_name']);
        $setting->from_email = Str::lower(trim($data['from_email']));
        $setting->event_preferences = array_replace(array_fill_keys($types, true), $data['event_preferences']);
        if (! empty($data['clear_key'])) {
            $setting->resend_api_key = null;
            $setting->enabled = false;
        } elseif (! empty($data['resend_api_key'])) {
            $setting->resend_api_key = trim($data['resend_api_key']);
        }
        if ($setting->enabled && ! $setting->resend_api_key) {
            throw ValidationException::withMessages(['resend_api_key' => 'Cadastre uma chave do Resend antes de ativar o envio.']);
        }
        $setting->save();
        DevAdminAudit::create([
            'actor_user_id' => $request->user()->id, 'tenant_id' => null,
            'action' => 'messaging.settings.updated', 'created_at' => now(),
        ]);
        return response()->json(['message' => 'Mensageria atualizada. A chave permanece criptografada e oculta.']);
    }

    /** Verifica autenticação e domínio no Resend sem enviar mensagens nem expor a chave. */
    public function diagnose(ResendMailer $mailer): JsonResponse
    {
        $setting = $mailer->setting();
        if (! $setting || ! $setting->resend_api_key) {
            return response()->json(['data' => ['status' => 'missing_key', 'message' => 'Cadastre e salve uma chave de API do Resend.']]);
        }
        if (! $setting->enabled) {
            return response()->json(['data' => ['status' => 'disabled', 'message' => 'A chave está salva, mas o envio automático está desativado.']]);
        }
        if (! filter_var($setting->from_email, FILTER_VALIDATE_EMAIL)) {
            return response()->json(['data' => ['status' => 'invalid_sender', 'message' => 'Configure um e-mail remetente válido.']]);
        }
        $result = $mailer->diagnose($setting);
        return response()->json(['data' => $result])->header('Cache-Control', 'no-store');
    }

    public function test(Request $request, ResendMailer $mailer): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email:rfc', 'max:255']]);
        $email = Str::lower(trim($data['email']));
        $recipients = collect($request->user()->dev_notification_emails ?: [])->map(fn ($v) => Str::lower(trim((string) $v)))->all();
        if (! in_array($email, $recipients, true)) {
            throw ValidationException::withMessages(['email' => 'Primeiro salve este endereço em Meu perfil → E-mails de notificações DEV.']);
        }
        try {
            $mailer->send($email, 'ACE · Teste da mensageria',
                'Este é um teste de entrega do ACE via Resend. Nenhuma cobrança foi realizada.',
                'ace-test-'.$request->user()->id.'-'.Str::uuid());
            return response()->json(['message' => 'Resend aceitou a mensagem de teste. Confira a caixa de entrada e o painel Resend.']);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Não foi possível enviar: '.$e->getMessage().'. Verifique a chave e o domínio remetente no Resend.'], 422);
        }
    }
}
