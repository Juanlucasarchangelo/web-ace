<div align="center">
  <img src="https://media.giphy.com/media/Ll22OhMLAlVDb8UQWe/giphy.gif" width="120"/>
  <h1>📂 Hub de gestão digital pessoal</h1>
  <p>Uma plataforma SaaS de gestão e integração de dados digitais do usuário, com sincronização e automação via Drive.</p>

  <a href="https://www.youtube.com/@ArchaniaSolum" target="_blank" rel="noopener noreferrer">
    <img src="https://img.shields.io/static/v1?message=Youtube&logo=youtube&label=&color=FF0000&logoColor=white&labelColor=&style=for-the-badge" height="35" alt="youtube logo"/>
  </a>
  <a href="https://www.instagram.com/webarchangelo/" target="_blank" rel="noopener noreferrer">
    <img src="https://img.shields.io/static/v1?message=Instagram&logo=instagram&label=&color=E4405F&logoColor=white&labelColor=&style=for-the-badge" height="35" alt="instagram logo"/>
  </a>
  <a href="https://www.twitch.tv/zudokan_original" target="_blank" rel="noopener noreferrer">
    <img src="https://img.shields.io/static/v1?message=Twitch&logo=twitch&label=&color=9146FF&logoColor=white&labelColor=&style=for-the-badge" height="35" alt="twitch logo"/>
  </a>
  <a href="https://www.linkedin.com/in/juan-lucas-archangelo-061035180/" target="_blank" rel="noopener noreferrer">
    <img src="https://img.shields.io/static/v1?message=LinkedIn&logo=linkedin&label=&color=0077B5&logoColor=white&labelColor=&style=for-the-badge" height="35" alt="linkedin logo"/>
  </a>
</div>

---

## 📖 Visão Geral

Este projeto tem como objetivo centralizar e organizar **credenciais, arquivos e documentos dos clientes**, oferecendo segurança, praticidade e controle.  
O sistema permite armazenar informações sensíveis de forma segura, além de integrar com serviços externos como o **Google Drive**, para gerenciamento de arquivos e pastas.

---

## 🎯 Objetivos

-   Gerenciar credenciais (usuários, senhas, chaves de acesso) dos clientes.
-   Armazenar e organizar documentos de forma estruturada.
-   Integrar com o **Google Drive** para upload, listagem e exclusão de arquivos.
-   Garantir segurança com autenticação e permissões de acesso.
-   Fornecer uma interface intuitiva para usuários internos.

## 🚀 Tecnologias Utilizadas

<div align="left">
  <img src="https://cdn.jsdelivr.net/gh/devicons/devicon/icons/php/php-original.svg" height="30" alt="Php logo"  />
  <img src="https://cdn.jsdelivr.net/gh/devicons/devicon/icons/laravel/laravel-original.svg" height="30" alt="Php logo"  />
  <img width="12" />
</div>

---

## 🚀 Funcionalidades

-   [ ] Cadastro e gerenciamento de clientes.
-   [ ] Cadastro seguro de credenciais.
-   [ ] Upload, download e exclusão de arquivos locais.
-   [ ] Integração com Google Drive:
    -   Listar arquivos e pastas.
    -   Criar pastas.
    -   Upload de arquivos.
    -   Excluir arquivos e pastas.
-   [ ] Controle de acesso baseado em perfis (admin, usuário).
-   [ ] Logs de auditoria de acessos e alterações.
-   [ ] Pesquisa avançada por cliente, credencial ou documento.

---

## 🏗 Arquitetura do Sistema

```mermaid
flowchart TD
    A["Frontend"] --> B["Backend/API"]
    B --> C["Banco de Dados"]
    B --> D["Google Drive API"]
    A --> E["Autenticacao e Autorizacao (JWT / OAuth2)"]
```

---

## 📦 Instalação

```bash
# Clone o repositório
git clone https://github.com/seuusuario/web-ace.git

# Acesse o diretório
cd web ace

# Instale as dependências (exemplo Laravel)
copy .env.exemple .env
npm install

# Configure o .env
cp .env.example .env
php artisan key:generate
```
