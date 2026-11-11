<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DevOperationalIssue;
use App\Models\GoogleDriveConnection;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AceAi\GeminiConversation;
use App\Support\BillingPlans;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** DEV assistant: whitelisted aggregate metadata only; no tenant membership or content APIs. */
final class DevAiController extends Controller
{
    private function pages(): array { return config('ace_pages.dev', []); }

    public function status(): JsonResponse
    {
        return response()->json(['data' => [
            'engine' => config('ace_ai.provider') === 'gemini' && config('ace_ai.api_key') ? 'gemini_configured' : 'local',
            'model' => config('ace_ai.provider') === 'gemini' && config('ace_ai.api_key') ? config('ace_ai.model') : null,
            'pages' => array_map(static fn ($item) => ['label' => $item[0], 'description' => $item[1]], $this->pages()),
            'can_write' => false,
            'capabilities' => ['dev_help', 'dev_overview', 'dev_plans', 'dev_issues', 'dev_affiliates', 'dev_provision'],
        ]])->header('Cache-Control', 'no-store, private');
    }

    public function chat(Request $request, GeminiConversation $gemini): JsonResponse
    {
        $input = $request->validate([
            'message' => ['required', 'string', 'min:2', 'max:1000'],
            'context' => ['required', 'array'],
            'context.page' => ['required', Rule::in(array_keys($this->pages()))],
            'history' => ['sometimes', 'array', 'max:8'],
            'history.*.role' => ['required', 'in:user,assistant'],
            'history.*.text' => ['required', 'string', 'max:600'],
        ]);
        $page = $input['context']['page'];
        $text = trim($input['message']);
        // Do not transmit DEV account identifiers, personal info or accidental pasted credentials to AI provider.
        if ($gemini->sensitive($text) || preg_match('/\S+@\S+|(?:\d[.\/-]?){11,14}/u', $text)) {
            return $this->reply('Não processe dados de usuários, documentos, senhas ou identificadores privados pelo chat DEV. Use as telas autorizadas; o assistente só consulta indicadores operacionais.', 'unsupported');
        }
        $plain = mb_strtolower(\Illuminate\Support\Str::ascii($text));
        $intent = null;
        if (preg_match('/\b(explicar|explique|esta tela|essa tela|onde estou|o que faz|como uso|esta pagina|nessa pagina)\b/i', $plain)) $intent = 'dev_help';
        elseif (preg_match('/\b(afiliado|afiliados|cupom|cupons|indicacao|indicacoes)\b/i', $plain)) $intent = 'dev_affiliates';
        elseif (preg_match('/\b(plano|planos|preco|precos|valor|valores|mensal|anual|assinatura)\b/i', $plain)) $intent = 'dev_plans';
        elseif (preg_match('/\b(erro|erros|falha|falhas|problema|problemas|logs|incidentes)\b/i', $plain)) $intent = 'dev_issues';
        elseif (preg_match('/\b(criar empresa|cadastrar empresa|criar usuario|cadastrar usuario|adicionar usuario)\b/i', $plain)) $intent = 'dev_provision';
        elseif (preg_match('/\b(usuarios|contas|equipes|empresas|conexoes|drive|resumo|total|quantos)\b/i', $plain)) $intent = 'dev_overview';

        $decision = null;
        if (! $intent) {
            $decision = $gemini->respond($text, [], ['dev' => true, 'label' => $this->pages()[$page][0]]);
            $intent = in_array($decision['intent'] ?? '', ['dev_help', 'dev_plans', 'dev_issues', 'dev_affiliates', 'dev_provision', 'dev_overview', 'chat'], true)
                ? $decision['intent'] : 'unsupported';
        }
        if ($intent === 'dev_help') {
            [$label, $desc] = $this->pages()[$page];
            return $this->reply('Você está em '.$label.'. '.$desc.' Posso explicar o painel e consultar indicadores agregados. Para alterações de planos use o formulário do DEV.', $intent);
        }
        if ($intent === 'dev_provision') {
            return $this->reply('Abra Criar empresas e usuários no menu DEV para provisionar empresa, Master ou membros. Não depende de SMTP; o fluxo público de contratação continua exigindo verificação de e-mail. Nenhuma cobrança é confirmada por essa criação.', $intent);
        }
        if ($intent === 'dev_affiliates') {
            return $this->reply('Abra Afiliados e cupons no menu DEV para vincular usuários existentes, emitir descontos e consultar cadastros atribuídos, pedidos e vendas pagas. O uso do cupom não significa pagamento confirmado. O assistente não cria cupons pelo chat.', $intent);
        }
        if ($intent === 'dev_plans') {
            $plans = BillingPlans::catalog(true)['plans'];
            $lines = array_map(static function (array $p): string {
                return $p['name'].' ('.$p['id'].'): mensal R$ '.number_format($p['monthly_cents'] / 100, 2, ',', '.').
                    '; anual R$ '.number_format($p['annual_cents'] / 100, 2, ',', '.').
                    ($p['is_active'] ? '' : ' — indisponível para novas vendas');
            }, $plans);
            return $this->reply("Planos atuais:\n".implode("\n", $lines)."\nPara editar nomes, descrições e valores, abra Planos e preços e salve após conferir. O chat não altera cobranças.", $intent);
        }
        if ($intent === 'dev_issues') {
            return $this->reply('Existem '.DevOperationalIssue::whereNull('resolved_at')->count().' ocorrência(s) operacional(is) em aberto e '.GoogleDriveConnection::where('health_status', 'reconnect_required')->count().' conexão(ões) Drive aguardando reconexão. Abra Problemas e logs para ver o diagnóstico sanitizado.', $intent);
        }
        if ($intent === 'dev_overview') {
            return $this->reply('Monitoramento global: '.Tenant::count().' empresa(s), '.User::count().' usuário(s), '.GoogleDriveConnection::count().' vínculo(s) Google Drive. Não acesso arquivos, clientes ou sites de nenhuma empresa.', $intent);
        }
        if ($intent === 'chat') {
            return $this->reply((string) ($decision['reply'] ?: 'Posso explicar as telas DEV, mostrar planos e consultar indicadores operacionais.'), 'chat', $decision['engine'] ?? 'local', $decision['model'] ?? null);
        }
        return $this->reply('Posso explicar o cadastro DEV de empresas e usuários, planos, alertas e indicadores operacionais sem acessar dados internos dos workspaces.', 'unsupported');
    }

    private function reply(string $text, string $intent, string $engine = 'ace', ?string $model = null): JsonResponse
    {
        return response()->json(['data' => ['text' => $text, 'intent' => $intent, 'engine' => $engine, 'model' => $model, 'results' => [], 'clients' => [], 'confirmation' => null, 'preview' => []]])
            ->header('Cache-Control', 'no-store, private');
    }
}
