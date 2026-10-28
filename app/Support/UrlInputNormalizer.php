<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Arr;

/**
 * Normaliza somente os campos que representam URLs web.
 * Domínios sem esquema são salvos como HTTPS, mas esquemas explícitos
 * (inclusive os proibidos) são preservados para a validação rejeitá-los.
 */
final class UrlInputNormalizer
{
    public static function normalize(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        // URLs relativas à rede e protocolos explícitos não são convertidos.
        // O validador do controller continua permitindo apenas HTTP/HTTPS.
        if (str_starts_with($value, '//') || preg_match('~^[a-z][a-z0-9+.-]*:(?://|[^0-9])~i', $value)) {
            return $value;
        }

        // Ex.: archania.com.br, www.exemplo.com/painel, localhost:8080.
        return 'https://'.$value;
    }

    /**
     * Aplica a normalização antes de Request::validate, sem alterar
     * os demais campos da requisição nem exigir um prefixo no frontend.
     * Aceita caminhos aninhados, como social_links.youtube.
     */
    public static function inRequest(Request $request, array $fields): void
    {
        $payload = $request->all();
        $changed = false;

        foreach ($fields as $field) {
            if (! Arr::has($payload, $field)) {
                continue;
            }

            Arr::set($payload, $field, self::normalize(Arr::get($payload, $field)));
            $changed = true;
        }

        if ($changed) {
            $request->replace($payload);
        }
    }
}
