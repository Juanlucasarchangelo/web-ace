<?php

namespace App\Services\Messaging;

use App\Models\AceMessagingSetting;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Direct HTTPS transport; avoids installing Composer packages or modifying existing mailers. */
final class ResendMailer
{
    public function setting(): ?AceMessagingSetting
    {
        return AceMessagingSetting::query()->find(1);
    }

    public function ready(?AceMessagingSetting $setting = null): bool
    {
        $setting ??= $this->setting();
        return $setting && $setting->enabled && $setting->resend_api_key
            && filter_var($setting->from_email, FILTER_VALIDATE_EMAIL);
    }

    /** Diagnóstico não destrutivo: valida chave e status do domínio remetente. */
    public function diagnose(?AceMessagingSetting $setting = null): array
    {
        $setting ??= $this->setting();
        if (! $setting || ! $setting->resend_api_key) {
            return ['status' => 'missing_key', 'message' => 'Nenhuma chave de API configurada.'];
        }
        try {
            $response = Http::acceptJson()->withToken((string) $setting->resend_api_key)
                ->connectTimeout(5)->timeout(12)->get('https://api.resend.com/domains');
            if ($response->status() === 401) {
                return ['status' => 'invalid_key', 'message' => 'A chave foi rejeitada pelo Resend (HTTP 401).'];
            }
            if ($response->status() === 403) {
                return ['status' => 'restricted_key', 'message' => 'A chave não tem permissão para consultar domínios. Use uma chave com acesso completo ou faça um envio de teste para validar a entrega.'];
            }
            if (! $response->successful()) {
                return ['status' => 'provider_error', 'message' => 'O Resend respondeu com HTTP '.$response->status().'.'];
            }
            $senderDomain = strtolower((string) substr(strrchr((string) $setting->from_email, '@') ?: '', 1));
            $domains = $response->json('data');
            if (! is_array($domains)) {
                return ['status' => 'provider_error', 'message' => 'O Resend retornou uma lista de domínios inesperada.'];
            }
            foreach ($domains as $domain) {
                if (! is_array($domain) || strtolower((string) ($domain['name'] ?? '')) !== $senderDomain) continue;
                $verified = ($domain['status'] ?? '') === 'verified';
                return [
                    'status' => $verified ? 'connected' : 'domain_pending',
                    'message' => $verified
                        ? 'API autenticada e domínio remetente verificado no Resend. Faça o envio de teste para conferir a entrega.'
                        : 'API autenticada, mas o domínio remetente ainda não está verificado no Resend.',
                    'domain' => $senderDomain,
                ];
            }
            return ['status' => 'domain_missing', 'message' => 'API autenticada, mas o domínio do remetente não consta na conta Resend.', 'domain' => $senderDomain];
        } catch (\Throwable $e) {
            return ['status' => 'network_error', 'message' => 'Não foi possível alcançar a API do Resend. Verifique a rede do container e tente novamente.'];
        }
    }

    public function send(string $email, string $subject, string $body, string $key, ?AceMessagingSetting $setting = null): string
    {
        $setting ??= $this->setting();
        if (! $this->ready($setting)) throw new RuntimeException('Resend ainda não configurado.');
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Destinatário inválido.');
        $fromName = trim(preg_replace('/[\r\n<>]/', '', (string) $setting->from_name)) ?: 'ACE';
        $html = '<!doctype html><html lang="pt-BR"><body style="margin:0;padding:32px;background:#f3f7f8;font-family:Arial,sans-serif;color:#162934">'
            .'<main style="max-width:590px;margin:auto;padding:30px;background:#fff;border-radius:14px">'
            .'<p style="font-size:12px;font-weight:700;color:#168e83;letter-spacing:2px">ACE · NOTIFICAÇÕES</p>'
            .'<h1 style="font-size:23px;line-height:1.3">'.e($subject).'</h1>'
            .'<p style="white-space:pre-line;line-height:1.65;font-size:15px">'.nl2br(e($body)).'</p>'
            .'<p style="font-size:12px;color:#667b84;margin-top:30px">Mensagem transacional automática do ACE.</p>'
            .'</main></body></html>';
        $response = Http::acceptJson()->asJson()->withToken((string) $setting->resend_api_key)
            ->withHeaders(['Idempotency-Key' => hash('sha256', $key)])
            ->timeout(15)->post('https://api.resend.com/emails', [
                'from' => $fromName.' <'.$setting->from_email.'>',
                'to' => [$email],
                'subject' => $subject,
                'html' => $html,
                'text' => $body,
            ]);
        if (! $response->successful()) {
            // Never include provider response bodies, API keys or mailbox data in application logs.
            throw new RuntimeException('Resend HTTP '.$response->status());
        }
        $id = $response->json('id');
        if (! is_string($id) || $id === '') throw new RuntimeException('Resend não confirmou o identificador da mensagem.');
        return $id;
    }
}
