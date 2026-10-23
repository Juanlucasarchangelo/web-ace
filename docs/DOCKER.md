# Operação do ACE Docker — Laravel 13

## Iniciar / retomar

Execute `INICIAR-ACE.bat`. Apenas o Docker Desktop é necessário no Windows.

O fluxo do instalador é seguro para repetir:

1. Preserva `.env` existente. Se já há volumes mas o `.env` sumiu, para para evitar geração de chave inválida.
2. Quando **não existe** `composer.lock`, executa `docker compose --profile setup run --rm --build --no-deps php-lock` (Composer resolve sem instalar, sem desativar a política de advisories). Escreve um lock verdadeiro no seu computador.
3. Quando **não existe** `package-lock.json`, executa `docker compose --profile setup run --rm --no-deps node-lock` (npm gera um lock verdadeiro).
4. Executa `docker compose up --build -d`. A imagem usa `npm ci` e `composer install` travados pelo lockfile; `composer audit --locked` deve passar.
5. Após migrations, verifica se existe usuário e oferece bootstrap se ainda não houver.

## Topologia

| Serviço | Acesso |
| --- | --- |
| web (Nginx) | 127.0.0.1:8088 no Windows |
| app (PHP-FPM 8.3 / Laravel 13) | somente rede Docker |
| queue | somente rede Docker |
| db (MySQL 8.4) | somente rede Docker, volume persistente |
| migrate | processo temporário |
| php-lock / node-lock | tarefas de setup; não são iniciadas pelo `up` |

## Comandos PowerShell

```powershell
docker compose ps
docker compose logs --tail=100 web app migrate
docker compose exec app php artisan route:list --path=api/v1
docker compose exec app php artisan test
docker compose down # preserva volumes
powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\docker-backup.ps1
```

## Atualizações

- Faça backup dos volumes e do `.env` antes de atualizar.
- Não apague `.env`, `composer.lock`, `package-lock.json` nem os volumes Docker.
- Para atualizar conscientemente as dependências, remova apenas os lockfiles depois de backup e execute o instalador novamente. Nunca desabilite advisories para forçar versões comprometidas.
- O primeiro lock depende dos registros Composer/npm disponíveis no dia da instalação; depois permanece fixo até atualização explícita.

## Solução de problemas

- **Composer Security Advisories**: a resolução travou por dependência vulnerável. Não desative o bloqueio. Envie apenas o nome e versão do pacote reportado (não envie segredos).
- **Falha npm ci**: confira existência do `package-lock.json`. Não troque por `npm install` no Dockerfile.
- **`migrate exited (1)`**: `docker compose logs migrate db`.
- **502**: `docker compose ps` e `docker compose logs app web`.
- **419**: use `http://127.0.0.1:8088`, verifique XSRF-TOKEN; não misture `localhost` com `127.0.0.1`.
- **401**: autenticação usa sessão Sanctum, sem Passport. Veja se o cookie de sessão é enviado.
- **Porta 8088 em uso**: altere `ACE_HTTP_PORT`, `APP_URL`, `SANCTUM_STATEFUL_DOMAINS` no `.env` antes de subir.
- **Admin ausente após falha anterior**: o instalador confere o banco em toda execução, mesmo quando `.env` já existe.

## Backup

`scripts/docker-backup.ps1` gera dump SQL e cópia do `.env`. Ambos são sensíveis; guarde criptografados em local separado. **Nunca execute `docker compose down -v` ou `docker volume rm` sem ter backup confirmado.**
