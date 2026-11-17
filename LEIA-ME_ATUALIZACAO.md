# ReviverLegisFlow 2.0 — Guia de atualização

## Antes de começar
1. **Faça backup do banco** pelo phpMyAdmin (Exportar → SQL) e **copie a pasta atual** do sistema no servidor.
2. Esta versão **não altera a estrutura do banco**. Nenhum script SQL precisa ser executado.
3. Requisitos (os mesmos de hoje): PHP 8.1 ou superior com `pdo_mysql`, `mbstring`, `zip`, `gd` e `xml`; MySQL 5.7+.

## Passo a passo
1. Coloque o sistema em manutenção (opcional): crie um arquivo vazio `maintenance.flag` na raiz.
2. Envie **todos os arquivos** deste pacote para a pasta do sistema, substituindo os existentes.
3. **Remova do servidor** os arquivos antigos que não existem mais:
   `css/style.css`, `css/mobile.css`, `js/app.js`, `js/mobile.js`, `js/script.js`,
   `includes/sidebar.php`, `includes/mobile_nav.php` (e as pastas `css/` e `js/` vazias).
4. Confira o arquivo **`includes/config.local.php`**: ele contém as credenciais do banco
   (as mesmas que estavam em `includes/conexao.php`). Ele **não deve ir para o git** — já está no `.gitignore`.
5. Acesse o sistema, faça login como administrador e confira o Dashboard.
6. Apague o `maintenance.flag` (ou desative em Administração → Manutenção).

## Recomendações de segurança (importante)
- **Troque a senha do banco de dados.** A senha atual estava gravada em texto puro no `conexao.php`
  e versionada no git. Depois de trocar no painel da hospedagem, atualize em
  Administração → Banco de Dados (a tela testa a conexão antes de salvar).
- Remova a senha antiga do histórico do repositório git (ou trate o repositório como comprometido).
- Os arquivos `.htaccess` incluídos bloqueiam o acesso direto a `includes/`, `vendor/`, `.sql`, `.bak` etc.
  Eles funcionam em servidores Apache (padrão da hospedagem atual).

## O que mudou para os usuários
- Usuários marcados como **Inativos não conseguem mais entrar** (antes o status era ignorado).
- Após o deploy, todos precisarão **fazer login novamente** uma vez (os formulários agora têm proteção CSRF).
- Novas senhas exigem **8+ caracteres com letras e números** (senhas atuais continuam válidas).
- "Esqueci minha senha" funciona quando o e-mail (SMTP) estiver configurado em Administração → E-mail.
