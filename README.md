# ReviverLegisFlow

Sistema de gestão jurídica da **Concessionária Reviver**. Acompanha os processos judiciais, os valores em risco, a economia obtida e o volume de novas ações por tipo de vara.

![PHP](https://img.shields.io/badge/PHP-8.2%2B-777BB4?logo=php&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL-5.7%2B-4479A1?logo=mysql&logoColor=white)
![Versão](https://img.shields.io/badge/versão-2.0.0-A85F00)

## Funcionalidades

**Relatórios** (todos os perfis)
- **Dashboard**: risco provisionado, processos recebidos, economia e valor pago, com comparação ao ano anterior. Mostra também o volume mensal de entrada, a situação dos processos, as maiores causas em andamento e as pendências.
- **Performance financeira**: economia calculada (valor da causa − despesas − valor pago), evolução mensal e composição financeira.
- **Provisão de risco**: soma do valor das causas por ano, com visão mensal ou acumulada e filtro por situação.
- **Volume de entrada**: novas ações por mês, separadas em Cíveis, Trabalhistas e Outros, comparadas com o ano anterior.

**Gestão** (Editor e Administrador)
- **Processos**: cadastro, edição e exclusão. Filtros por ano, mês, situação e central de custo, busca, ordenação, painel de detalhes, histórico de alterações campo a campo e exportação em CSV ou Excel (.xlsx).
- **Totais mensais**: quantidade de novas ações por vara e mês, editada direto na tabela com salvamento automático.
- **Importação de planilhas**: em 3 etapas, com prévia validada linha a linha antes de gravar.

**Administração** (somente Administrador)
- Usuários e níveis de acesso, logs de atividade com filtros, configuração de e-mail (SMTP), aparência (nome e logos), modo de manutenção, backup `.sql` e configuração do banco de dados.

**Para todos**
- Tema claro e escuro salvo na conta, layout responsivo (desktop, tablet e celular), "Minha conta" e recuperação de senha por e-mail.

## Perfis de acesso

| Nível | Perfil | Acesso |
| --- | --- | --- |
| 1 | Usuário | Dashboard e relatórios |
| 2 | Editor | Tudo do Usuário + processos, totais mensais, importações e exportações |
| 3 | Administrador | Tudo do Editor + área de administração. Único perfil que acessa o sistema em modo de manutenção |

## Tecnologias

- PHP procedural com PDO, sem framework
- MySQL 5.7+ / MariaDB
- [PhpSpreadsheet](https://github.com/PHPOffice/PhpSpreadsheet) para importar e exportar planilhas
- [PHPMailer](https://github.com/PHPMailer/PHPMailer) para envio de e-mails
- CSS e JavaScript próprios, sem dependências de build; gráficos em SVG
- Font Awesome hospedado localmente e fonte IBM Plex Sans

## Instalação

**Requisitos:** PHP 8.2 ou superior (exigido pelas dependências em `vendor/`), com as extensões `pdo_mysql`, `mbstring`, `zip`, `gd` e `xml`. Servidor Apache (as proteções de pasta usam `.htaccess`).

1. Clone o repositório na pasta pública do servidor:
   ```bash
   git clone https://github.com/Marcos-Nasc/LegisFlow.git
   ```
2. Crie o banco e importe a estrutura das tabelas `usuarios`, `processos_judiciais`, `relatorio_mensal_processos`, `logs` e `configuracoes`.
3. Copie o arquivo de configuração e preencha as credenciais:
   ```bash
   cp includes/config.local.example.php includes/config.local.php
   ```
   ```php
   return [
       'db' => [
           'host'   => 'localhost',
           'dbname' => 'nome_do_banco',
           'user'   => 'usuario',
           'pass'   => 'senha',
       ],
       'app_url' => 'https://seu-dominio.com.br', // usado nos links enviados por e-mail
   ];
   ```
4. O `vendor/` já vem no repositório. Para reinstalar as dependências, rode `composer install`.
5. Acesse o sistema e entre com um usuário Administrador.

> O arquivo `includes/config.local.php` guarda credenciais e está no `.gitignore`. Nunca o envie para o repositório.

Para atualizar uma instalação da versão 1.x, siga o [LEIA-ME_ATUALIZACAO.md](LEIA-ME_ATUALIZACAO.md).

## Estrutura

```
├── index.php                 Dashboard
├── php/                      Páginas (relatórios, gestão, administração, login)
├── includes/
│   ├── bootstrap.php         Inicialização: sessão, conexão, segurança, tema
│   ├── auth.php              Login, permissões, CSRF, modo de manutenção
│   ├── layout.php            Sidebar, barra superior e navegação mobile
│   ├── funcoes.php           Formatação, configurações, logs
│   ├── email.php             Envio de e-mails (SMTP)
│   ├── importacao.php        Leitura de planilhas
│   ├── pendencias.php        Pendências exibidas no Dashboard
│   └── config.local.php      Credenciais (não versionado)
├── assets/
│   ├── css/app.css           Design system (tema claro e escuro)
│   ├── js/app.js             Componentes: modais, toasts, tema, requisições
│   ├── js/charts.js          Gráficos SVG
│   └── js/pages/             Scripts de cada página
├── images/                   Logo e ícone
└── vendor/                   Dependências do Composer
```

## Segurança

- Credenciais fora do código-fonte (`includes/config.local.php` ou variáveis de ambiente `LF_DB_*`)
- Token CSRF em todos os formulários e requisições
- Senhas com `password_hash` e bloqueio temporário após várias tentativas de login sem sucesso
- Usuários inativos não entram, e a sessão é encerrada se a senha mudar em outro dispositivo
- Consultas com prepared statements e saídas escapadas contra XSS
- Acesso direto a `includes/` e `vendor/` bloqueado por `.htaccess`
- Todas as ações relevantes registradas em log, com usuário, data e IP

---

Desenvolvido por [Marcos Nascimento](https://github.com/Marcos-Nasc) para a Concessionária Reviver.
