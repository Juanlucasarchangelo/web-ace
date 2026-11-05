<?php

namespace App\Services\AceAi;

use Illuminate\Support\Str;

/** Restricted local fallback when the Gemini provider is unavailable. */
class IntentResolver
{
    public function resolve(string $message): array
    {
        $plain = Str::lower(Str::ascii(trim($message)));
        $decision = [
            'intent' => 'unsupported', 'query' => '', 'reply' => '',
            'presentation' => 'text', 'client_name' => '', 'site_name' => '',
            'domain' => '', 'engine' => 'local', 'model' => null,
        ];

        // In degraded mode never authorize writes or secrets by keyword heuristics.
        if (preg_match('/\b(senha|password|token|credencial|secret|segredo|crie|criar|cadastre|cadastrar|excluir|apagar|deletar|remover|editar|alterar)\b/i', $plain)) {
            return $decision;
        }
        if (preg_match('/\b(o que faz esta tela|o que posso fazer aqui|como uso essa pagina|onde estou|explique essa tela|para que serve essa pagina)\b/i', $plain)) {
            $decision['intent'] = 'page_help';
            return $decision;
        }
        if (preg_match('/\b(quantos membros|equipe|minha permissao|meu cargo)\b/i', $plain)) {
            $decision['intent'] = 'team_summary';
            return $decision;
        }
        if (preg_match('/\b(google drive|drive|contas conectadas|contas vinculadas|conexao com o google)\b/i', $plain)) {
            $decision['intent'] = 'drive_status';
            return $decision;
        }
        if (preg_match('/\b(cliente|clientes)\b/i', $plain) && ! preg_match('/\b(site|sites|dominio)\b/i', $plain)) {
            $decision['intent'] = 'clients';
            $decision['query'] = $this->search($plain, 'cliente|clientes');
            return $decision;
        }
        if (preg_match('/\b(dns|nameserver|nameservers|hospedagem)\b/i', $plain)) {
            $decision['intent'] = 'site_details';
            $decision['query'] = $this->search($plain, 'dns|nameserver|nameservers|hospedagem|site|dominio');
            return $decision;
        }
        if (preg_match('/\b(site|sites|dominio|dominios|website|url)\b/i', $plain)) {
            $decision['intent'] = 'sites';
            $decision['query'] = $this->search($plain, 'site|sites|dominio|dominios|website|url');
            $decision['presentation'] = preg_match('/\b(mostre|liste|listar|exiba)\b/i', $plain) ? 'cards' : 'text';
            return $decision;
        }
        if (preg_match('/\b(resumo|dashboard|painel|visao geral|quantos acessos|quantos usuarios)\b/i', $plain)) {
            $decision['intent'] = 'dashboard';
        }
        return $decision;
    }

    private function search(string $plain, string $words): string
    {
        if (preg_match('~(?:https?://)?((?:[a-z0-9-]+\.)+[a-z]{2,63})~i', $plain, $match)) {
            return $match[1];
        }
        $plain = preg_replace('/\b('.$words.'|e|a|o|os|as|de|da|do|dos|das|me|mostre|encontre|procure|buscar|pesquise|liste|listar|quantos|quantas|tem|tenho|aqui|cadastrados|cadastradas|no|na|ace|qual|quais|sao|só|so|texto|cards)\b/i', ' ', $plain);
        return Str::limit(trim((string) preg_replace('/\s+/u', ' ', $plain), ' .?!'), 160, '');
    }
}
