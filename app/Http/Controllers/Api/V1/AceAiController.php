<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Client;
use App\Models\Site;
use App\Models\SiteAccess;
use App\Services\AceAi\GeminiConversation;
use App\Services\AceAi\IntentResolver;
use App\Services\AceAi\SecureIntakeParser;
use App\Services\AceAi\SiteSearchTool;
use App\Services\AceAi\WorkspaceTools;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** ACE IA only executes preapproved, tenant-scoped capabilities. Never expose a generic DB tool. */
class AceAiController extends AceController
{
    private const PAGES = [
        'dashboard' => ['Visão geral', 'Visão consolidada de clientes, sites, acessos protegidos, equipe e atividade recente. Aqui você também conversa com a ACE IA.'],
        'clientes' => ['Clientes', 'Cadastre e consulte pessoas ou empresas, dados de contato, documentos e endereços. Os registros ficam vinculados ao workspace ativo.'],
        'sites' => ['Sites', 'Gerencie os domínios vinculados aos clientes, DNS, hospedagem, links e acessos técnicos. A ficha de cada site reúne informações e credenciais protegidas.'],
        'cofre' => ['Cofre de acessos', 'Consulte os acessos cadastrados por serviço e site. A revelação de segredos exige permissão e é registrada na auditoria.'],
        'ia' => ['ACE IA', 'Converse com o assistente, consulte dados autorizados e prepare cadastros. Toda criação exige revisão e confirmação.'],
        'empresas' => ['Empresas', 'Visualize e selecione os workspaces aos quais sua conta pertence. Os dados de uma empresa não ficam disponíveis para as demais.'],
        'equipe' => ['Usuários e permissões', 'Gerencie os membros e cargos da empresa. Somente usuários autorizados podem alterar a equipe.'],
        'atividade' => ['Auditoria', 'Acompanhe eventos registrados pelo sistema, como cadastros, alterações e acesso a credenciais.'],
        'nuvem' => ['Integrações em nuvem', 'Vincule contas Google Drive e gerencie arquivos conforme as permissões da empresa e as autorizações concedidas ao Google.'],
    ];

    public function status(Request $request): JsonResponse
    {
        $enabled = config('ace_ai.provider') === 'gemini' && (bool) config('ace_ai.api_key');
        return $this->noStore(response()->json(['data' => [
            'engine' => $enabled ? 'gemini_configured' : 'local',
            'model' => $enabled ? config('ace_ai.model') : null,
            'capabilities' => ['conversation', 'sites', 'clients', 'site_details', 'dashboard', 'drive_status', 'team_summary', 'page_help', 'create_client', 'create_site', 'secure_intake'],
            'can_write' => in_array($this->role($request), ['owner', 'admin'], true),
            'sensitive_policy' => 'Credenciais são processadas apenas pelo Laravel e criptografadas. Todas as escritas exigem confirmação.',
        ]]));
    }

    public function chat(Request $request, GeminiConversation $gemini, IntentResolver $resolver, SiteSearchTool $siteSearch, WorkspaceTools $tools, SecureIntakeParser $parser): JsonResponse
    {
        $input = $request->validate([
            'message' => ['required', 'string', 'min:2', 'max:5000'],
            'history' => ['sometimes', 'array', 'max:8'],
            'history.*.role' => ['required', 'in:user,assistant'],
            'history.*.text' => ['required', 'string', 'max:600'],
            'context' => ['sometimes', 'array'],
            'context.page' => ['sometimes', 'string', Rule::in(array_keys(self::PAGES))],
            'context.site_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ]);
        $message = trim($input['message']);
        $page = (string) ($input['context']['page'] ?? 'dashboard');
        $tenant = $this->tenant($request);
        $tenantId = (string) $tenant->id;

        // High-priority path. Confidential values never leave the application server.
        if ($parser->looksLikeIntake($message)) {
            $request->merge(['message' => '[conteúdo sensível omitido]']);
            $this->canWrite($request);
            return $this->prepareIntake($request, $parser->parse($message), $parser);
        }
        if ($gemini->sensitive($message)) {
            $request->merge(['message' => '[conteúdo sensível omitido]']);
            return $this->answer('Por segurança, não envio senhas, chaves ou documentos ao modelo de IA. Para cadastrar cliente, site e webmail de uma vez, cole um bloco com nome, domínio e os dados de acesso, cada um em uma linha.', 'unsupported', 'ace');
        }
        $activeSite = $page === 'sites' && ! empty($input['context']['site_id'])
            ? $tools->siteDetailsById($tenantId, (int) $input['context']['site_id']) : null;
        if ($this->isPageHelp($message)) return $this->pageHelp($page, 'ace', null, $activeSite);
        if ($activeSite && preg_match('/\b(este site|deste site|esse site|essa ficha|dessa ficha)\b/iu', $message)) {
            return $this->answer($this->siteInfoText($activeSite), 'site_details', 'ace');
        }

        // Context is an allowlisted page identifier, NOT private page contents, HTML or secrets.
        $decision = $gemini->respond($message, $input['history'] ?? [], ['page' => $page, 'label' => self::PAGES[$page][0]]);
        $providerFailed = $decision === null && config('ace_ai.provider') === 'gemini' && config('ace_ai.api_key');
        $decision ??= $resolver->resolve($message);
        $intent = (string) ($decision['intent'] ?? 'unsupported');
        $engine = (string) ($decision['engine'] ?? 'local');
        $model = $decision['model'] ?? null;
        $query = (string) ($decision['query'] ?? '');
        $countOnly = (bool) preg_match('/\b(quantos|quantas|quantidade|total|numero de|número de)\b/iu', $message);
        $noCards = (bool) preg_match('/\b(sem cards|sem cart[oõ]es|s[oó] texto|apenas texto|n[aã]o precisa.*cards)\b/iu', $message);
        $presentation = $noCards || $countOnly ? 'text' : ($decision['presentation'] ?? 'text');

        if ($intent === 'page_help') return $this->pageHelp($page, $engine, $model, $activeSite);
        if ($intent === 'sites') {
            $count = $tools->siteCount($tenantId, $query);
            $results = $countOnly ? [] : $siteSearch->execute($tenantId, $query);
            $text = $count === 0 ? 'Não encontrei sites correspondentes neste workspace.'
                : ($countOnly ? 'Você tem '.$count.' site(s)'.($query ? ' correspondentes a "'.$query.'"' : '').' neste workspace.'
                    : 'Encontrei '.$count.' site(s)'.($count > count($results) ? '; mostrando os primeiros '.count($results) : '').'.');
            if ($presentation !== 'cards') {
                $text .= $results !== [] ? "\n".implode("\n", array_map(static fn ($s) => '• '.$s['name'].' — '.$s['domain'], $results)) : '';
                $results = [];
            }
            return $this->answer($text, $intent, $engine, $results, [], null, $model);
        }
        if ($intent === 'clients') {
            $found = $tools->clients($tenantId, $query);
            $count = $found['count'];
            $text = $count === 0 ? 'Não encontrei clientes correspondentes neste workspace.'
                : ($countOnly ? 'Você tem '.$count.' cliente(s)'.($query ? ' correspondentes à pesquisa' : '').' neste workspace.' : 'Encontrei '.$count.' cliente(s).');
            $clients = $countOnly ? [] : $found['items'];
            if ($presentation !== 'cards') {
                $text .= $clients !== [] ? "\n".implode("\n", array_map(static fn ($c) => '• '.$c['name'].' ('.$c['sites_count'].' site(s))', $clients)) : '';
                if ($count > count($clients) && ! $countOnly) $text .= "\nExibindo os primeiros ".count($clients).'.';
                $clients = [];
            }
            return $this->answer($text, $intent, $engine, [], $clients, null, $model);
        }
        if ($intent === 'site_details') {
            $items = $tools->siteDetails($tenantId, $query)['items'];
            if ($items === []) return $this->answer('Não encontrei a ficha. Informe o nome ou domínio do site.', $intent, $engine, [], [], null, $model);
            $text = implode("\n\n", array_map(static fn ($item) =>
                $item['name'].' ('.$item['domain'].")\n".
                'DNS primário: '.($item['dns_primary'] ?: 'não informado')."\n".
                'DNS secundário: '.($item['dns_secondary'] ?: 'não informado')."\n".
                'Hospedagem: '.($item['hosting_provider'] ?: 'não informada'), $items));
            return $this->answer($text, $intent, $engine, [], [], null, $model);
        }
        if ($intent === 'drive_status') {
            $drive = $tools->driveStatus($tenant, in_array($this->role($request), ['owner', 'admin'], true));
            $text = ! $drive['cloud_enabled'] ? 'O plano deste workspace não habilita o Google Drive.'
                : ($drive['count'] === 0 ? 'Não há contas Google Drive vinculadas a este workspace.'
                    : 'Há '.$drive['count'].' conta(s) Google Drive vinculada(s) neste workspace: '.implode(', ', array_column($drive['accounts'], 'email')).'.');
            $text .= "\nO ACE confirma o vínculo salvo; isso não testa o acesso ao Google em tempo real.";
            return $this->answer($text, $intent, $engine, [], [], null, $model);
        }
        if ($intent === 'dashboard') {
            $data = $tools->dashboard($tenant);
            return $this->answer('Neste workspace: '.$data['clients_total'].' clientes, '.$data['sites_total'].' sites, '.$data['accesses_total'].' acessos cadastrados e '.$data['team_total'].' membros na equipe.', $intent, $engine, [], [], null, $model);
        }
        if ($intent === 'team_summary') {
            return $this->answer('Você está em "'.self::PAGES['equipe'][0].'"? O workspace possui '.$tenant->users()->count().' membro(s). Seu papel atual é '.$this->role($request).'. Posso explicar onde gerenciar a equipe; não altero cargos pelo chat.', 'team_summary', $engine, [], [], null, $model);
        }
        if (in_array($intent, ['create_client', 'create_site'], true)) {
            if ($engine !== 'gemini') return $this->answer('Para criar registros a partir de texto livre, o Gemini precisa estar disponível. Para dados estruturados com credenciais, use o bloco de cadastro seguro.', 'unsupported', $engine);
            $this->canWrite($request);
            return $this->prepareWrite($request, $decision);
        }
        if ($intent === 'chat') return $this->answer($decision['reply'] ?: 'Posso consultar dados e preparar cadastros dentro das permissões do seu workspace.', 'chat', $engine, [], [], null, $model);
        return $this->answer(
            $providerFailed ? 'O Gemini está temporariamente indisponível. Você ainda pode consultar sites, clientes e o Drive. O cadastro seguro em bloco também continua disponível.'
                : ($decision['reply'] ?: 'Essa operação ainda não está disponível no chat. Posso pesquisar dados autorizados e preparar cadastros supervisionados.'),
            'unsupported', $engine, [], [], null, $model
        );
    }

    private function isPageHelp(string $message): bool
    {
        return (bool) preg_match('/(?:o que (?:faz|é|tem)|pra que serve|para que serve|me (?:ajud[ae]|explique)|onde estou|qual (?:é )?essa p[aá]gina|como (?:uso|utilizo)|o que posso fazer).{0,55}(?:aqui|nesta|nessa|esta|essa|p[aá]gina|tela)|(?:explica|explique|ajuda).{0,30}(?:tela|p[aá]gina)/iu', $message);
    }

    private function pageHelp(string $page, string $engine = 'ace', ?string $model = null, ?array $activeSite = null): JsonResponse
    {
        [$title, $description] = self::PAGES[$page] ?? self::PAGES['dashboard'];
        $context = $activeSite ? ' Ficha selecionada: '.$activeSite['name'].' ('.$activeSite['domain'].').' : '';
        return $this->answer('Você está em '.$title.'. '.$description.$context.' Posso orientar você e consultar os dados disponíveis conforme seu acesso.', 'page_help', $engine, [], [], null, $model);
    }

    private function siteInfoText(array $site): string
    {
        return 'Ficha atual: '.$site['name'].' ('.$site['domain'].")\n".
            'DNS primário: '.($site['dns_primary'] ?: 'não informado')."\n".
            'DNS secundário: '.($site['dns_secondary'] ?: 'não informado')."\n".
            'Hospedagem: '.($site['hosting_provider'] ?: 'não informada');
    }

    private function prepareIntake(Request $request, array $draft, SecureIntakeParser $parser): JsonResponse
    {
        $errors = array_merge($parser->errors($draft), $draft['warnings'] ?? []);
        $tenantId = (string) $this->tenant($request)->id;
        $clientName = trim((string) ($draft['client']['name'] ?? ''));
        $siteDomain = (string) ($draft['site']['domain'] ?? '');
        $existing = $clientName !== '' ? Client::where('tenant_id', $tenantId)->where('name', $clientName)->first() : null;
        if ($existing && ! empty($draft['client']['document']) && $existing->document && $existing->document !== $draft['client']['document']) {
            $errors[] = 'Já existe um cliente com este nome e outro CPF/CNPJ. Verifique o cadastro.';
        }
        if (! empty($draft['client']['document']) && Client::where('tenant_id', $tenantId)->where('document', $draft['client']['document'])->where('name', '!=', $clientName)->exists()) {
            $errors[] = 'Este CPF/CNPJ já pertence a outro cliente deste workspace.';
        }
        if ($siteDomain !== '' && Site::where('tenant_id', $tenantId)->where('domain', $siteDomain)->exists()) {
            $errors[] = 'Este domínio já está cadastrado neste workspace. Não vou duplicá-lo.';
        }
        if (! empty($draft['has_secret']) && empty($draft['access']['username'])) $errors[] = 'Informe o usuário/login junto da senha.';
        $preview = $parser->publicPreview($draft, $existing !== null);
        if ($errors !== []) {
            return $this->answer('Recebi os dados em modo protegido, mas preciso que você revise: '.implode(' ', $errors).' Nenhum registro foi criado.', 'secure_intake', 'ace', [], [], null, null, $preview);
        }
        $summary = ($existing ? 'Vincular ao cliente existente "' : 'Criar cliente "').$clientName.'"; criar site "'.$draft['site']['name'].'" ('.$siteDomain.')'.
            (! empty($draft['has_secret']) || ! empty($draft['access']['username']) || ! empty($draft['access']['url']) ? '; guardar acesso de webmail criptografado.' : '.');
        $confirmation = $this->storeConfirmation($request, 'secure_intake', $draft, $summary, $preview);
        return $this->answer('Identifiquei os campos do cadastro. Revise abaixo: nada foi gravado ainda. O conteúdo sensível foi tratado exclusivamente no servidor ACE.', 'secure_intake', 'ace', [], [], $confirmation, null, $preview);
    }

    private function prepareWrite(Request $request, array $decision): JsonResponse
    {
        $tenantId = (string) $this->tenant($request)->id;
        $model = $decision['model'] ?? null;
        $intent = $decision['intent'];
        $clientName = trim((string) ($decision['client_name'] ?? ''));
        $siteName = trim((string) ($decision['site_name'] ?? ''));
        $domain = Str::lower(trim((string) ($decision['domain'] ?? '')));
        if (mb_strlen($clientName) < 2 || mb_strlen($clientName) > 180 || preg_match('/[\x00-\x1f]/', $clientName)) {
            return $this->answer('Informe o nome do cliente para preparar o cadastro.', $intent, 'gemini', [], [], null, $model);
        }
        if ($intent === 'create_client') {
            if (Client::query()->where('tenant_id', $tenantId)->where('name', $clientName)->exists()) {
                return $this->answer('Já existe um cliente com esse nome neste workspace. Confira antes de cadastrar novamente.', $intent, 'gemini', [], [], null, $model);
            }
            $payload = ['name' => $clientName, 'person_type' => 'company', 'status' => 'active'];
            $summary = 'Cadastrar cliente "'.$clientName.'" (empresa ativa).';
        } else {
            if (mb_strlen($siteName) < 2 || mb_strlen($siteName) > 180 || ! $this->validDomain($domain)) {
                return $this->answer('Para cadastrar o site, informe nome e domínio válido.', $intent, 'gemini', [], [], null, $model);
            }
            $client = Client::query()->where('tenant_id', $tenantId)->where('name', $clientName)->first();
            if (! $client) return $this->answer('Não encontrei o cliente "'.$clientName.'". Você pode colar um bloco estruturado com nome, domínio e dados de acesso para criar cliente e site juntos.', $intent, 'gemini', [], [], null, $model);
            if (Site::query()->where('tenant_id', $tenantId)->where('domain', $domain)->exists()) {
                return $this->answer('Este domínio já está cadastrado no workspace.', $intent, 'gemini', [], [], null, $model);
            }
            $payload = ['client_id' => $client->id, 'name' => $siteName, 'domain' => $domain, 'status' => 'active'];
            $summary = 'Cadastrar site "'.$siteName.'" ('.$domain.') para "'.$client->name.'".';
        }
        return $this->answer('Revise antes de confirmar: '.$summary.' Nenhum registro foi criado ainda.', $intent, 'gemini', [], [],
            $this->storeConfirmation($request, $intent, $payload, $summary), $model);
    }

    private function storeConfirmation(Request $request, string $intent, array $payload, string $summary, array $preview = []): array
    {
        $token = bin2hex(random_bytes(32));
        $value = ['user_id' => (int) $request->user()->id, 'tenant_id' => (string) $this->tenant($request)->id, 'intent' => $intent, 'payload' => $payload];
        // Even on a database cache driver the pending secret is encrypted at rest.
        Cache::put('ace_ai_confirmation:'.hash('sha256', $token), Crypt::encryptString(json_encode($value, JSON_THROW_ON_ERROR)), now()->addMinutes(5));
        return ['token' => $token, 'summary' => $summary, 'preview' => $preview, 'expires_in_seconds' => 300];
    }

    public function confirm(Request $request, SecureIntakeParser $parser): JsonResponse
    {
        $this->canWrite($request);
        $data = $request->validate(['token' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]{64}$/D']]);
        $digest = hash('sha256', $data['token']);
        // Lock prevents concurrent confirmation of the same one-use token.
        return Cache::lock('ace_ai_confirm_lock:'.$digest, 10)->block(3, function () use ($request, $digest, $parser): JsonResponse {
            $encrypted = Cache::get('ace_ai_confirmation:'.$digest);
            abort_unless(is_string($encrypted), 422, 'Confirmação expirada ou já utilizada. Envie o pedido novamente.');
            $pending = json_decode(Crypt::decryptString($encrypted), true, 512, JSON_THROW_ON_ERROR);
            abort_unless(is_array($pending) && (string) ($pending['tenant_id'] ?? '') === (string) $this->tenant($request)->id
                && (int) ($pending['user_id'] ?? 0) === (int) $request->user()->id, 403, 'Confirmação não pertence a este usuário ou workspace.');
            Cache::forget('ace_ai_confirmation:'.$digest);
            $payload = $pending['payload'];
            $tenantId = (string) $this->tenant($request)->id;

            return DB::transaction(function () use ($request, $parser, $pending, $payload, $tenantId): JsonResponse {
                if ($pending['intent'] === 'create_client') {
                    abort_if(Client::where('tenant_id', $tenantId)->where('name', $payload['name'])->exists(), 422, 'Cliente já cadastrado.');
                    $created = Client::create($payload + ['tenant_id' => $tenantId]);
                    $this->logAction($request, 'ace_ai.client.created');
                    return $this->answer('Cliente "'.$created->name.'" cadastrado com sucesso.', 'create_client', 'ace');
                }
                if ($pending['intent'] === 'create_site') {
                    abort_unless(Client::where('tenant_id', $tenantId)->whereKey($payload['client_id'])->exists(), 422, 'Cliente inválido neste workspace.');
                    abort_if(Site::where('tenant_id', $tenantId)->where('domain', $payload['domain'])->exists(), 422, 'Domínio já cadastrado.');
                    $created = Site::create($payload + ['tenant_id' => $tenantId]);
                    $this->logAction($request, 'ace_ai.site.created');
                    return $this->createdSiteReply($created, 'Site cadastrado com sucesso.');
                }
                if ($pending['intent'] === 'secure_intake') {
                    abort_if($parser->errors($payload) !== [], 422, 'Dados inválidos. Revise o bloco e envie novamente.');
                    $name = $payload['client']['name'];
                    $domain = $payload['site']['domain'];
                    abort_if(! empty($payload['client']['document']) && Client::where('tenant_id', $tenantId)->where('document', $payload['client']['document'])->where('name', '!=', $name)->exists(), 422, 'CPF/CNPJ já associado a outro cliente.');
                    abort_if(Site::where('tenant_id', $tenantId)->where('domain', $domain)->exists(), 422, 'Domínio já cadastrado.');
                    $client = Client::where('tenant_id', $tenantId)->where('name', $name)->first();
                    if ($client) {
                        abort_if(! empty($payload['client']['document']) && $client->document && $client->document !== $payload['client']['document'], 422, 'Cliente com documento divergente.');
                    } else {
                        $client = Client::create($payload['client'] + ['tenant_id' => $tenantId]);
                        $this->logAction($request, 'ace_ai.client.created');
                    }
                    $site = Site::create($payload['site'] + ['client_id' => $client->id, 'tenant_id' => $tenantId]);
                    $this->logAction($request, 'ace_ai.site.created');
                    $access = $payload['access'];
                    if (! empty($access['username']) || ! empty($access['secret']) || ! empty($access['url'])) {
                        SiteAccess::create($access + ['site_id' => $site->id, 'tenant_id' => $tenantId]);
                        $this->logAction($request, 'ace_ai.access.created');
                    }
                    return $this->createdSiteReply($site, 'Cadastro concluído: cliente, site'.
                        (! empty($access['username']) || ! empty($access['secret']) || ! empty($access['url']) ? ' e acesso protegido' : '').' registrados.');
                }
                abort(422, 'Operação inválida.');
            });
        });
    }

    private function createdSiteReply(Site $site, string $message): JsonResponse
    {
        return $this->answer($message.' Você pode abrir a ficha para conferir os dados.', 'create_site', 'ace', [[
            'id' => (int) $site->id, 'name' => $site->name, 'domain' => $site->domain,
            'client' => '', 'portal_url' => '/sites?site='.$site->id,
            'website_url' => 'https://'.$site->domain,
        ]]);
    }

    private function validDomain(string $domain): bool
    {
        return mb_strlen($domain) <= 253 && (bool) preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/D', $domain);
    }

    private function answer(string $text, string $intent, string $engine, array $results = [], array $clients = [], ?array $confirmation = null, ?string $model = null, array $preview = []): JsonResponse
    {
        return $this->noStore(response()->json(['data' => [
            'text' => $text, 'intent' => $intent, 'engine' => $engine, 'model' => $model,
            'results' => $results, 'clients' => $clients, 'confirmation' => $confirmation,
            'preview' => $preview,
        ]]));
    }

    private function noStore(JsonResponse $response): JsonResponse
    {
        $response->headers->set('Cache-Control', 'no-store, private');
        return $response;
    }
}
