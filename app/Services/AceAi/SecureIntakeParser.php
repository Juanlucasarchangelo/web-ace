<?php

namespace App\Services\AceAi;

use Illuminate\Support\Str;

/**
 * Deterministic, server-side intake of sensitive blocks. NEVER send these fields to an LLM.
 * Unrecognized content is discarded, not appended to notes, logs or prompts.
 */
class SecureIntakeParser
{
    public function looksLikeIntake(string $message): bool
    {
        if (! str_contains($message, "\n")) return false;
        $lines = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $message) ?: [])));
        if (count($lines) < 3) return false;
        // First-line name + second-line domain is the standard paste workflow.
        $compact = ! str_contains($lines[0], '?') && mb_strlen($lines[0]) <= 180
            && $this->domain(preg_replace('/^(?:dom[ií]nio|site|website)\s*:\s*/iu', '', $lines[1])) !== null;
        // Or accept explicit field labels in any order, with a standalone domain.
        $labelled = (bool) preg_match('/(?:^|\n)\s*(?:usu[aá]rio|login|senha|password|cnpj|cpf|endereço|endere[cç]o|link de acesso|acesso|cliente|nome)\b/iu', $message)
            && (bool) preg_match('/(?:^|\n)\s*(?:(?:dom[ií]nio|site|website)\s*:\s*)?(?:https?:\/\/)?(?:[a-z0-9-]+\.)+[a-z]{2,}(?:\/\S*)?\s*$/mi', $message);
        return $compact || $labelled;
    }

    public function parse(string $message): array
    {
        $result = [
            'client' => ['name' => '', 'person_type' => 'person', 'status' => 'active'],
            'site' => ['name' => '', 'domain' => '', 'status' => 'active'],
            'access' => ['category' => 'email', 'label' => 'Webmail', 'service' => 'Webmail'],
            'has_secret' => false,
            'warnings' => [],
        ];
        $free = [];
        foreach (preg_split('/\r\n|\r|\n/', trim($message)) ?: [] as $raw) {
            $line = trim($raw);
            if ($line === '' || mb_strlen($line) > 2200) continue;
            if (preg_match('/^(?:(?:cadastre|cadastrar|crie|criar)\s+(?:um\s+|o\s+)?(?:site|cliente)\s*:?\s*)$/iu', $line)) continue;
            if (preg_match('/^(?:cliente|nome(?: do cliente)?|raz[aã]o social)\s*:\s*(.+)$/iu', $line, $m)) {
                $result['client']['name'] = trim($m[1]); continue;
            }
            if (preg_match('/^(?:nome do site|site)\s*:\s*(.+)$/iu', $line, $m) && ! $this->domain($m[1])) {
                $result['site']['name'] = trim($m[1]); continue;
            }
            if (preg_match('/^(?:dom[ií]nio|site|website)\s*:\s*(.+)$/iu', $line, $m) && ($domain = $this->domain($m[1]))) {
                $result['site']['domain'] = $domain; continue;
            }
            if (preg_match('/^(?:usu[aá]rio|user(?:name)?|login)\s*:\s*(.*)$/iu', $line, $m)) {
                $result['access']['username'] = trim($m[1]); continue;
            }
            if (preg_match('/^(?:senha|password|pass(?:word)?)\s*:\s*(.*)$/iu', $line, $m)) {
                $result['access']['secret'] = trim($m[1]);
                $result['has_secret'] = $result['access']['secret'] !== '';
                continue;
            }
            if (preg_match('/^(?:link de acesso|url de acesso|acesso|webmail)\s*:\s*(.*)$/iu', $line, $m)) {
                $result['access']['url'] = $this->safeUrl(trim($m[1]));
                if ($m[1] !== '' && $result['access']['url'] === null) $result['warnings'][] = 'URL de acesso inválida; revise antes de cadastrar.';
                continue;
            }
            if (preg_match('/^(?:cnpj|cpf|documento)\s*:\s*(.*)$/iu', $line, $m)) {
                $result['client']['document'] = preg_replace('/\D/', '', $m[1]); continue;
            }
            if (preg_match('/^(?:e-?mail(?: do cliente)?|email)\s*:\s*(.*)$/iu', $line, $m)) {
                $result['client']['email'] = trim($m[1]); continue;
            }
            if (preg_match('/^(?:telefone|celular)\s*:\s*(.*)$/iu', $line, $m)) {
                $result['client']['phone'] = trim($m[1]); continue;
            }
            if (preg_match('/^(?:endere[cç]o|logradouro)\s*:\s*(.*)$/iu', $line, $m)) {
                $this->address($result['client'], $m[1]); continue;
            }
            if ($result['site']['domain'] === '' && ($domain = $this->domain($line))) {
                $result['site']['domain'] = $domain; continue;
            }
            if (preg_match('/^\d{14}$/', preg_replace('/\D/', '', $line)) && preg_match('/^[\d.\/\- ]{14,22}$/', $line)) {
                $result['client']['document'] = preg_replace('/\D/', '', $line); continue;
            }
            if (preg_match('/^\d{11}$/', preg_replace('/\D/', '', $line)) && preg_match('/^[\d.\- ]{11,16}$/', $line)) {
                $result['client']['document'] = preg_replace('/\D/', '', $line); continue;
            }
            $free[] = $line;
        }
        if ($result['client']['name'] === '' && isset($free[0]) && ! $this->looksLikeAddress($free[0])) {
            $result['client']['name'] = array_shift($free);
        }
        foreach ($free as $line) {
            if ($this->looksLikeAddress($line)) { $this->address($result['client'], $line); break; }
        }
        $result['site']['name'] = $result['site']['name'] ?: $result['client']['name'];
        $document = (string) ($result['client']['document'] ?? '');
        $result['client']['person_type'] = strlen($document) === 14 ? 'company' : 'person';
        if ($document !== '' && ! $this->validDocument($document)) {
            $result['warnings'][] = 'O CPF/CNPJ informado é inválido. Corrija o documento e envie novamente antes de cadastrar.';
        }
        return $result;
    }

    public function errors(array $draft): array
    {
        $errors = [];
        $client = $draft['client']; $site = $draft['site']; $access = $draft['access'];
        if (mb_strlen((string) ($client['name'] ?? '')) < 2 || mb_strlen((string) ($client['name'] ?? '')) > 180) $errors[] = 'Nome do cliente inválido (2–180 caracteres).';
        if (mb_strlen((string) ($site['name'] ?? '')) < 2 || mb_strlen((string) ($site['name'] ?? '')) > 180) $errors[] = 'Nome do site inválido.';
        if (! $this->domain((string) ($site['domain'] ?? ''))) $errors[] = 'Domínio inválido.';
        if (! empty($client['document']) && ! $this->validDocument($client['document'])) $errors[] = 'CPF/CNPJ inválido.';
        foreach (['username' => 255, 'secret' => 30000, 'url' => 2048] as $field => $max) {
            if (isset($access[$field]) && mb_strlen((string) $access[$field]) > $max) $errors[] = 'Campo de acesso muito longo: '.$field.'.';
        }
        if (isset($access['username']) && $access['username'] !== '' && mb_strlen($access['username']) < 2) $errors[] = 'Usuário de acesso inválido.';
        if (isset($client['email']) && $client['email'] !== '' && (! filter_var($client['email'], FILTER_VALIDATE_EMAIL) || mb_strlen($client['email']) > 180)) $errors[] = 'E-mail do cliente inválido.';
        foreach ($client as $field => $value) {
            $max = ['address_street'=>180, 'address_number'=>30, 'address_city'=>120, 'phone'=>50, 'document'=>20][$field] ?? null;
            if ($max !== null && mb_strlen((string) $value) > $max) $errors[] = 'Campo do cliente excede o limite: '.$field.'.';
        }
        return $errors;
    }

    public function publicPreview(array $draft, bool $existingClient): array
    {
        $client = $draft['client']; $site = $draft['site']; $access = $draft['access'];
        $doc = (string) ($client['document'] ?? '');
        return [
            'client' => $client['name'].' · '.($existingClient ? 'cliente existente' : 'novo cliente'),
            'site' => $site['name'].' · '.$site['domain'],
            'document' => $doc === '' ? 'não informado' : str_repeat('•', max(0, strlen($doc) - 4)).substr($doc, -4),
            'address' => implode(', ', array_filter([$client['address_street'] ?? '', $client['address_number'] ?? '', $client['address_city'] ?? ''])),
            'access' => ! empty($access['username']) || $draft['has_secret'] || ! empty($access['url'])
                ? 'Webmail · usuário '.($access['username'] ?? 'não informado').' · senha '.($draft['has_secret'] ? 'protegida' : 'não informada')
                : 'nenhum acesso',
            'access_url' => $access['url'] ?? '',
        ];
    }

    private function domain(string $text): ?string
    {
        $text = strtolower(trim($text));
        if (preg_match('~^https?://~', $text)) $text = (string) parse_url($text, PHP_URL_HOST);
        $text = trim($text, './ ');
        return strlen($text) <= 253 && preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/D', $text) ? $text : null;
    }

    private function safeUrl(string $text): ?string
    {
        if ($text === '') return null;
        if (! preg_match('~^https?://~i', $text)) $text = 'https://'.$text;
        if (mb_strlen($text) > 2048 || ! filter_var($text, FILTER_VALIDATE_URL)) return null;
        $parsed = parse_url($text);
        if (! in_array(strtolower((string) ($parsed['scheme'] ?? '')), ['http','https'], true)) return null;
        // Never save userinfo in URLs; it might contain embedded credentials.
        if (isset($parsed['user']) || isset($parsed['pass'])) return null;
        return $text;
    }

    private function looksLikeAddress(string $value): bool
    {
        return (bool) preg_match('/^.{3,160},\s*\d{1,8}\s*(?:[-–]\s*[^,]+)?$/u', $value);
    }

    private function address(array &$client, string $raw): void
    {
        $raw = trim($raw);
        if (preg_match('/^(.+?),\s*([^,\s]+)(?:\s*[-–]\s*(.+))?$/u', $raw, $m)) {
            $client['address_street'] = trim($m[1]);
            $client['address_number'] = trim($m[2]);
            if (! empty($m[3])) $client['address_city'] = trim($m[3]);
        } else {
            $client['address_street'] = $raw;
        }
    }

    public function validDocument(string $document): bool
    {
        $digits = preg_replace('/\D/', '', $document);
        $n = strlen($digits);
        if (! in_array($n, [11,14], true) || preg_match('/^(\d)\1+$/', $digits)) return false;
        if ($n === 11) {
            for ($i = 9; $i <= 10; $i++) {
                $sum = 0;
                for ($j = 0; $j < $i; $j++) $sum += (int) $digits[$j] * ($i + 1 - $j);
                $check = ($sum * 10) % 11;
                if ($check === 10) $check = 0;
                if ((int) $digits[$i] !== $check) return false;
            }
            return true;
        }
        foreach ([[5,4,3,2,9,8,7,6,5,4,3,2], [6,5,4,3,2,9,8,7,6,5,4,3,2]] as $index => $weights) {
            $sum = 0;
            foreach ($weights as $i => $weight) $sum += (int) $digits[$i] * $weight;
            $check = $sum % 11 < 2 ? 0 : 11 - ($sum % 11);
            if ((int) $digits[12 + $index] !== $check) return false;
        }
        return true;
    }
}
