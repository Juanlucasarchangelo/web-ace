# ACE — Gestão de clientes, credenciais e assinaturas (Docker)

**Manual único de operação, suporte e desenvolvimento.** Esta entrega substitui os arquivos antigos de `docs/`; mantenha este README atualizado ao modificar o sistema.

> **Atenção: credenciais demo somente para máquina local.** O ambiente deve permanecer acessível apenas por `127.0.0.1`. Antes de expor o ACE à internet: remova as contas de demonstração, troque todas as senhas, desative a seed demo, configure SMTP real, MFA para DEV e reavalie a segurança.

## 1. Visão geral e arquitetura

ACE é um SaaS multiempresa (tenants) em Laravel 13 / PHP 8.3 / MySQL 8.4, frontend Vue 3 + Vuetify + Vite. A autenticação usa sessão Laravel/Sanctum, cookies e CSRF. O DEV global atua somente na administração de metadados, sem acesso aos segredos ou arquivos de clientes dos tenants.

```text
Navegador http://127.0.0.1:8088
    |
    v
Nginx (web) ---> PHP-FPM (app) ---> MySQL db (volume persistente)
                                  +--> Queue (jobs) / Scheduler (tarefas)
                                  +--> Google Drive OAuth (opcional)
                                  +--> Gemini (opcional; ACE IA local por padrão)
                                  +--> Mercado Pago Orders (pagamentos DESATIVADOS)

Entradas opcionais LOCAIS (NÃO são outras aplicações/domínios):
127.0.0.1:8099 => Nginx webhook-ingress (apenas notificações)
127.0.0.1:8100 => Nginx checkout-ingress (apenas contratação pública)
```

**Produção:** um domínio com TLS 443 e proxy reverso por rota é possível, mas a configuração local atual **não** está pronta para produção. Nginx, firewall, segredo de sessão, cabeçalhos proxy, MFA, SMTP, webhook real, backups e regras de autorização exigem homologação específica. Não publique `8088`, `8099`, `8100` nem `3306` diretamente.

## 2. Instalação do zero no Windows

Requisitos: Docker Desktop / Docker Compose v2 e PowerShell 5.1+. Não instale PHP ou Composer no Windows; o Dockerfile instala tudo nas imagens. Na primeira instalação, extraia este ZIP numa pasta **nova** e execute `INICIAR-ACE.bat`.

1. O script confere Docker, gera um `.env` com `APP_KEY` e credenciais MySQL aleatórias se não há volume/`.env` anteriores.
2. O script usa `composer.lock` e `package-lock.json` do projeto; valida Compose e constrói imagens.
3. Executa **somente migrations incrementais**. Quando o volume MySQL ainda não existia antes de iniciar, executa automaticamente `db:seed` com as credenciais locais do `.env` para criar DEV e empresa/usuário teste, mesmo que o `.env` tenha sido preservado após uma limpeza intencional de volumes. O comando é executado pelo **INICIAR-ACE.bat**, não por `docker compose up` isoladamente. Não executa seed automaticamente em atualizações.
4. Abra `http://127.0.0.1:8088/login` — **não** use `8100/login` (é bloqueada propositalmente).

### Contas DEMO locais, fornecidas nesta versão

| Perfil | E-mail | Senha inicial de demonstração | Área após login |
|---|---|---|---|
| DEV global | `ace@ace.com.br` | `AceDev!Local2026#K7` | `/dev` |
| Master da empresa DEMO | `teste@ace.com.br` | `AceUser!Local2026#Q9` | `/dashboard` |

Empresa inicial: **Empresa Demonstração ACE**; plano **Free**, cinco vagas; DEV **não** pertence a nenhuma empresa. As senhas têm hash no banco; as cadeias em texto acima são **apenas para demonstração local** e estão também nas variáveis `ACE_DEMO_DEV_PASSWORD` e `ACE_DEMO_USER_PASSWORD` do `.env` criado. **Troque-as imediatamente após o primeiro login.** Não utilize esta seed nem estas senhas em VPS, homologação pública ou dados reais.

Se o volume MySQL **já existia antes de iniciar**, o instalador **não cria contas automaticamente**, mesmo que as tabelas estejam vazias; isso evita mascarar perda de dados. Se o volume foi removido intencionalmente em uma reinstalação local autorizada, `INICIAR-ACE.bat` identifica o banco novo e cria as contas. O comando `docker compose up` sozinho só inicia serviços e aplica migrations, **não executa a seed**. Nunca remova volumes para consertar login.

## 3. Reinstalação limpa AUTORIZADA — APAGA DADOS

**Somente quando você deliberadamente deseja recomeçar, como nesta instalação solicitada.** Execute `REINSTALAR-ACE-LIMPO.bat` **após** instalar o ZIP e iniciar Docker. O script verifica `APP_ENV=local` e `DB_DATABASE=ace_db`, exige **duas confirmações explícitas**, gera dump SQL e cópia do `.env`, confere um arquivo de backup não vazio, constrói as imagens da nova versão, para workers e só então executa `php artisan migrate:fresh --seed --force` com a seed demo explicitamente habilitada. Não remove volumes e não altera outras stacks.

**Esse comando apaga as tabelas e registros de `ace_db`.** Não execute para atualizar. Não execute em VPS nem com dados que precisam ser preservados. Se você pretende recuperar registros antigos, **não faça a reinstalação limpa**.

O backup fica em `backups/` com SQL e arquivo `.env` contendo chaves: guarde fora da máquina e criptografado. Backup é responsabilidade do operador; verifique a possibilidade de restaurá-lo antes de considerar o processo seguro. O dump SQL gerado por `scripts/docker-backup.ps1` não é prova de recuperação de registros já ausentes.

## 4. Atualizar sem apagar registros

1. **Faça backup e revise migrations antes de atualizar.** `INICIAR-ACE.bat` em um projeto existente primeiro inicia somente o MySQL e tenta gerar backup SQL e `.env` **antes** da reconstrução e migrations. Se o backup falha, o script aborta.
2. Substitua os arquivos do projeto, **preservando `.env`, volumes, backup e lockfiles**.
3. Execute `INICIAR-ACE.bat` ou, se preferir comandos manuais depois do backup, `docker compose up -d --build`.
4. Confira `docker compose ps`, `docker compose logs --tail=100 migrate app` e `docker compose exec -T app php artisan migrate:status`.
5. Verifique contagens de usuários/workspaces, login e as telas críticas ANTES de seguir com novas alterações.

**PROIBIDO em atualização:** `migrate:fresh`, `migrate:reset`, `db:wipe`, `docker compose down -v`, `docker volume rm`, `TRUNCATE users/tenants`. A única exceção é o procedimento da seção 3, autorizado explicitamente e com backup confirmado. `PARAR-ACE.bat` faz somente `docker compose down` **sem** `-v`, preservando volumes.

## 5. Administração DEV, empresas e usuários

No ACE local, abra `http://127.0.0.1:8088/login`, entre com a conta DEV e vá a **Criar empresas e usuários**. O DEV pode:

- Criar uma empresa e seu usuário Master (`POST /api/v1/dev/provision/tenants`), definindo plano inicial Free/Cloud/Pro e número de vagas;
- Criar outros usuários em uma empresa ativa (`POST /api/v1/dev/provision/users`), respeitando quantidade de vagas;
- Consultar empresas/usuários, alterar vagas, administrar planos e cupons, ver indicadores e auditoria.

O cadastro DEV interno **não depende de SMTP/Resend**. O e-mail dos usuários provisionados pelo DEV permanece **não verificado** (`email_verified_at = null`) até haver confirmação real; o login interno é permitido por autenticação de senha e vínculo ativo, sem exigir SMTP. O DEV não ganha acesso às APIs internas da empresa. Planos provisionados manualmente **não são pagamentos comprovados**; o campo de origem financeira fica `manual` e status `unverified`. O cadastro público de interessados continua exigindo e-mail real antes de liberar pagamento ou acesso pago.

Usuários Master gerenciam a equipe dentro das vagas concedidas; contas de teste são apenas para uso local. A tela de usuários e empresas do DEV apresenta metadados globais, não acessos privados.

## 6. Planos e contratação

Acesso público local: `http://127.0.0.1:8088/contratar` ou, na entrada pública isolada, `http://127.0.0.1:8100/contratar`. Catálogo Free/Cloud/Pro e valores são geridos no DEV; a API lê valores do banco e cria snapshot por pedido. Cupons vinculados a afiliados são cotados no servidor e atribuídos aos cadastros/pedidos. **Cadastro com cupom não equivale a venda paga.** A tela DEV de afiliados separa cadastros, Orders e pagamentos confirmados.

`ACE_PAYMENTS_ENABLED=false` **deve permanecer assim**. O Mercado Pago Orders e o Webhook não passaram em um teste real ponta a ponta nesta entrega; o simulador externo retornou erro. O sistema permite pré-cadastro de planos pagos, mas não habilita acesso pago por simples formulário. Com `MAIL_MAILER=log`, o cadastro público fica aguardando serviço de e-mail; o DEV pode criar manualmente empresas/usuários via fluxo interno enquanto Resend está pendente. **Não trate o checkout como produção.**

## 7. ACE IA — operação e limites

A IA aparece nas páginas de workspace e no painel DEV. Configuração padrão no `.env`: `ACE_AI_PROVIDER=local`, sem chave externa. O modo local usa intenções e ferramentas limitadas no Laravel: consulta de sites, clientes, contexto de página, indicadores, planos e ajuda operacional. Para DEV, o assistente responde dúvidas sobre administração e cadastro interno, sem executar operações de escrita. Ele **não** é um LLM completo offline nem inventa resultados. A ferramenta de busca de sites respeita o `tenant_id` autorizado e não revela senhas; mutações assistidas exigem confirmação. Não inclua credenciais pessoais em prompts.

O modo Gemini é **opcional**: configure `ACE_AI_PROVIDER=gemini`, `GEMINI_API_KEY` e um nome de modelo válido disponível na sua conta (o modelo padrão configurado neste pacote é `gemini-3.8-flash`), depois reinicie o app. Sem chave, permanece no modo local. O status pode ser consultado em `GET /api/v1/ai/status` (workspace) e `GET /api/v1/dev/ai/status` (DEV). O registro de telas para a IA está em `config/ace_pages.php`; toda tela nova deve ser adicionada a ele.

## 8. Segurança, permissões e dados

- `auth:sanctum` e CSRF em rotas privadas; middleware `dev.only` separa DEV de `tenant.member`. O DEV nunca acessa cofre, sites ou arquivos de outras empresas.
- Só o Master (`owner`) administra sua equipe e seus workspaces. Operações administrativas globais têm auditoria.
- Senhas de usuários são armazenadas com hash. Segredos de sites devem permanecer cifrados e suas revelações auditadas.
- Cookies seguros, MFA, SMTP real, URLs de retorno, backups e configuração do proxy precisam ser avaliados antes de produção.
- `.env`, dumps de SQL e backups não devem entrar no ZIP, Git ou imagem Docker. O `.dockerignore` impede que `backups/` integre o contexto de build.
- Nenhum evento de Webhook, navegador ou cupom pode sozinho marcar um pagamento como confirmado. Conciliar somente pela API oficial do provedor.
- Nunca gerar ou mudar `APP_KEY` de uma instalação existente: isso pode inviabilizar leitura de dados criptografados.

## 9. Mapa do projeto — onde o suporte encontra cada recurso

| Caminho | Responsabilidade |
|---|---|
| `compose.yaml` / `Dockerfile` | Topologia Docker e builds PHP/Vue/Nginx |
| `docker/nginx/`, `docker/checkout-only.conf`, `docker/webhook-only.conf` | Entrada HTTP/rotas públicas restritas |
| `app/Http/Controllers/Api/V1/` | API do sistema, DEV, IA e Billing |
| `app/Services/AceAi/` | Ferramentas e roteamento IA |
| `app/Services/Billing/` | Mercado Pago, cupons, validação e conciliação |
| `app/Http/Middleware/` | Autorização DEV e isolamento tenant |
| `app/Models/` | Entidades Eloquent |
| `database/migrations/` | Alterações incrementais de esquema |
| `database/seeders/DatabaseSeeder.php` | Contas DEMO locais, somente banco inicialmente vazio |
| `resources/js/views/` | Telas Vue, DEV e usuários |
| `resources/js/components/` | Componentes compartilhados e formulários |
| `resources/css/` | Estilos |
| `routes/api.php`, `routes/ace-ai.php`, `routes/ace-billing.php` | Definição das APIs |
| `config/ace_pages.php` | Catálogo das telas da IA |
| `scripts/docker-start.ps1` | Instalação/atualização com backup em ambiente existente |
| `scripts/reset-local.ps1` | **Somente** reinstalação destrutiva autorizada |
| `scripts/docker-backup.ps1` | Backup SQL/.env |
| `tests/` | Testes unitários e de API |

## 10. Operação e diagnóstico

```powershell
# Na pasta do projeto:
docker compose ps
docker compose logs --tail=80 app web migrate
docker compose exec -T app php artisan migrate:status
docker compose exec -T app php artisan route:list --path=api/v1
# Backup do banco e do .env (criptografe o destino):
powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\docker-backup.ps1
# Testes isolados de API (verifique sempre o DB de testes sqlite em memoria):
docker compose exec -T app php artisan test --filter=DevProvision
```

**PROTEÇÃO DOS TESTES:** `phpunit.xml` força `APP_ENV=testing`, `DB_CONNECTION=sqlite` e `DB_DATABASE=:memory:` mesmo se o Docker exportar MySQL; `tests/TestCase.php` bloqueia execução de testes HTTP/Feature antes das migrations se não estiver em SQLite em memória. Testes podem criar/apagar suas próprias tabelas em SQLite, mas nunca devem tocar no banco ACE. Não remova essa trava.

**401:** sessão não autenticada; **422:** validação/campos ou credenciais; **403:** permissão insuficiente; **402:** assinatura paga bloqueada; **419:** CSRF/host; **404 em `8100/login`:** normal, checkout ingress não expõe login. Evite misturar `localhost` e `127.0.0.1` para cookies. Se a contagem de usuários/workspaces inesperadamente cair a zero, **interrompa imediatamente atualizações e seed**, preserve volume e procure backups. Não recrie contas antes de investigar.

## 11. Integrações opcionais

**Google Drive:** OAuth oficial, escopos mínimos e refresh token sob criptografia. Reconexão e estado aparecem em Cloud/DEV; não compartilhar tokens.

**Resend (posterior):** configure transporte autenticado e domínio verificado; `MAIL_MAILER=log` não envia e-mail. Até lá, empresas e usuários podem ser criados manualmente no painel DEV.

**Mercado Pago (posterior):** Sandbox Checkout Pro via Orders API (`POST /v1/orders`), GET autoritativo, `x-signature`, `x-request-id`, `data.id`, uso do túnel `8099` exclusivamente para Webhooks, `8100` exclusivamente para contratação. Para um único domínio HTTPS definitivo na VPS, usar roteamento seguro no proxy reverso. `SELLER_ID` é ID numérico do vendedor, não da Order. Não alterar `ACE_PAYMENTS_ENABLED` antes de validação real com Order paga e testada.

---

## 12. Referência consolidada de API e suporte

Prefixo base: `/api/v1`. Todas as respostas são JSON; endpoints privados exigem autenticação de sessão e permissões no servidor.

| Recurso | Endpoints principais | Acesso |
|---|---|---|
| Autenticação | `POST /auth/login`, `GET /auth/me`, `POST /auth/logout` | Sessão / CSRF |
| Workspaces | `GET,POST /workspaces`, `PUT /workspaces/current` | Usuário associado, Master para escrita |
| Equipe | `GET,POST /team`, `PUT,DELETE /team/{id}` | Associado; Master para alterações |
| Clientes | `GET,POST /clients`, `GET,PUT,DELETE /clients/{id}` | Membro autorizado |
| Sites/acessos | `GET,POST /sites`, `GET,PUT,DELETE /sites/{id}` e `/sites/{id}/accesses` | Membro autorizado; revelação auditada |
| Auditoria | `GET /activity` | Associado |
| IA da empresa | `GET /ai/status`, `POST /ai/chat`, `POST /ai/confirm` | Associado; isolado por tenant |
| IA DEV | `GET /dev/ai/status`, `POST /dev/ai/chat` | DEV |
| Provisionamento DEV | `POST /dev/provision/tenants`, `POST /dev/provision/users` | DEV |
| Administração DEV | `GET /dev/overview`, `/dev/tenants`, `/dev/users`, `/dev/issues`, `/dev/audit` | DEV |
| Planos e afiliados | `/dev/plans`, `/dev/affiliates`, `/dev/affiliates/coupons` | DEV |
| Checkout público | `GET /billing/plans`, `POST /billing/signup`, `POST /billing/coupon/quote`, `POST /billing/verify-email` | Público; limitador de taxa |
| Billing | `GET /billing/current`, `POST /billing/renew`, `POST /billing/webhook` | Empresa ou webhook assinado, respectivamente |

`X-Tenant-Id` é validado contra associação real com `tenant_user` e nunca autoriza por si só. O DEV atua fora da API dos workspaces. As classes em `app/Http/Controllers/Api/V1` e os arquivos `routes/*.php` são a referência operacional de campos e permissões.

**Checkout Pro via Orders API:** `POST https://api.mercadopago.com/v1/orders` com chave de idempotência baseada no UUID do pedido ACE; `GET /v1/orders/{id}` para validar status, recebedor, montante e moeda. `provider_preference_id` é um nome de coluna legado que hoje pode guardar `ORD...`. O Webhook deve validar `x-signature`, `x-request-id` e `data.id`; assinatura correta **não** implica venda paga sem conciliação com a API. Túnel temporário é para testes locais, não infraestrutura de produção.

**Logs e erros:** `docker compose logs app web migrate db`, `storage/logs/laravel.log` no contêiner, `php artisan migrate:status`, `php artisan route:list`. Jamais cole senhas ou tokens na solicitação de suporte. Em suspeita de perda de dados, interrompa escrita e preserve volume/backups antes de qualquer intervenção.

---
**Versão documental:** 09/11/2026. Este README é o único manual `.md` do projeto. Não interpretar os testes estáticos desta entrega como validação real do Mercado Pago, do Google OAuth ou do provedor Gemini.
