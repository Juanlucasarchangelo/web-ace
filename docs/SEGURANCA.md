# ACE — notas de segurança

- Laravel 13 e PHP 8.3 são executados dentro do Docker. Requisito do framework: PHP >=8.3; Laravel 13 recebe suporte de segurança até 2028-03-17.
- A versão exigida do Laravel é `^13.38`, posterior às correções divulgadas em outubro de 2026. A dependência real é checada pelo Composer ao resolver o `composer.lock`.
- Não desative `policy.advisories.block`. O build executa também `composer audit --locked`.
- Composer e npm geram lockfiles no Docker na primeira instalação; nunca inventamos hashes.
- Sanctum: cookie HTTP-only e CSRF, sem JWT personalizado armazenado em JavaScript.
- API JSON exclusivamente em `routes/api.php`, middleware de associação à empresa e validação de autorização no backend.
- Segredos são criptografados pelo Eloquent; revelação sob permissão e com evento de auditoria.
- `.env` contém APP_KEY e senhas do banco; não faça upload deste arquivo nem o compartilhe. Preserve-o, pois perder a APP_KEY impede descriptografar registros antigos.
- Interface restrita ao loopback `127.0.0.1:8088`. Ao publicar em outro domínio exige HTTPS, cookies Secure, testes CSRF e nova revisão.
- Segurança de runtime não garante segurança da aplicação. Execute testes, revise permissões e faça backups antes de uso real com credenciais.
