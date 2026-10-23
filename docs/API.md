# API ACE v1

Prefixo `/api/v1` em `routes/api.php` (com prefixo `/api` aplicado pelo bootstrap do Laravel 13). Todas as respostas usam JSON. As rotas privadas exigem cookie de sessão válido e `auth:sanctum`, com CSRF para métodos mutáveis.

## Abertas
- `POST /auth/login`: `{email,password}` (antes GET `/sanctum/csrf-cookie`).

## Privadas
- `GET /auth/me`, `POST /auth/logout`
- `GET /dashboard`
- `GET,POST /clients`; `GET,PUT,DELETE /clients/{id}`
- `GET,POST /sites`; `GET,PUT,DELETE /sites/{id}`
- `POST /sites/{id}/registry/reveal`
- `POST /sites/{id}/accesses`; `PUT,DELETE /sites/{id}/accesses/{access}`
- `POST /sites/{id}/accesses/{access}/reveal`
- `GET /vault` (busca agregada, sem segredo)
- `GET,POST /workspaces`; `PUT /workspaces/current`
- `GET,POST /team`; `PUT,DELETE /team/{member}`
- `GET /activity`

`X-Tenant-Id` é opcional; se ausente seleciona-se a primeira empresa ativa do usuário. Se presente, é validado contra a tabela de vínculos, **nunca usado para autorizar sozinho**.

Permissões: `owner`/`admin`: CRUD e revelação; `owner`: nome e criação de workspace e promoção a admin; `member`/`viewer`: consulta sem segredos.

`GET /vault?search=ftp&category=hosting&page=1` devolve um paginador com metadados somente. Para revelar um segredo use o endpoint específico do site com o ID de acesso.
