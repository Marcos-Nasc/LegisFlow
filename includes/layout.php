<?php
/**
 * Estrutura visual compartilhada: <head>, sidebar, barra superior,
 * navegação mobile e rodapé de scripts.
 *
 * Uso numa página:
 *   layout_inicio('Processos', ['ativo' => 'processos']);
 *   ... conteúdo ...
 *   layout_fim(['scripts' => ['pages/processos.js'], 'dados' => [...]]);
 */

if (defined('LF_LAYOUT_CARREGADO')) {
    return;
}
define('LF_LAYOUT_CARREGADO', true);

/** Itens de navegação agrupados por seção, com o nível mínimo de cada um. */
function nav_estrutura()
{
    $b = BASE_URL;
    return [
        [
            'titulo' => null,
            'itens' => [
                ['id' => 'dashboard', 'rotulo' => 'Dashboard', 'icone' => 'fa-gauge-high', 'url' => "$b/index.php", 'nivel' => NIVEL_USUARIO],
            ],
        ],
        [
            'titulo' => 'Relatórios',
            'itens' => [
                ['id' => 'performance', 'rotulo' => 'Performance Financeira', 'icone' => 'fa-chart-line', 'url' => "$b/php/performance_financeira.php", 'nivel' => NIVEL_USUARIO],
                ['id' => 'provisao', 'rotulo' => 'Provisão de Risco', 'icone' => 'fa-scale-unbalanced', 'url' => "$b/php/provisao_risco.php", 'nivel' => NIVEL_USUARIO],
                ['id' => 'volume', 'rotulo' => 'Volume de Entrada', 'icone' => 'fa-chart-column', 'url' => "$b/php/volume_entrada.php", 'nivel' => NIVEL_USUARIO],
            ],
        ],
        [
            'titulo' => 'Gestão',
            'itens' => [
                ['id' => 'processos', 'rotulo' => 'Processos', 'icone' => 'fa-gavel', 'url' => "$b/php/processos_detalhados.php", 'nivel' => NIVEL_EDITOR],
                ['id' => 'totais', 'rotulo' => 'Totais Mensais', 'icone' => 'fa-table-cells', 'url' => "$b/php/totais_mensais.php", 'nivel' => NIVEL_EDITOR],
            ],
        ],
        [
            'titulo' => 'Administração',
            'itens' => [
                ['id' => 'usuarios', 'rotulo' => 'Usuários', 'icone' => 'fa-users', 'url' => "$b/php/configuracoes_usuarios.php", 'nivel' => NIVEL_ADMIN],
                ['id' => 'logs', 'rotulo' => 'Logs de Atividade', 'icone' => 'fa-clock-rotate-left', 'url' => "$b/php/logs.php", 'nivel' => NIVEL_ADMIN],
                ['id' => 'gerais', 'rotulo' => 'E-mail (SMTP)', 'icone' => 'fa-envelope', 'url' => "$b/php/configuracoes_gerais.php", 'nivel' => NIVEL_ADMIN],
                ['id' => 'aparencia', 'rotulo' => 'Aparência', 'icone' => 'fa-palette', 'url' => "$b/php/aparencia.php", 'nivel' => NIVEL_ADMIN],
                ['id' => 'manutencao', 'rotulo' => 'Manutenção', 'icone' => 'fa-screwdriver-wrench', 'url' => "$b/php/manutencao.php", 'nivel' => NIVEL_ADMIN],
                ['id' => 'banco', 'rotulo' => 'Banco de Dados', 'icone' => 'fa-database', 'url' => "$b/php/config_banco.php", 'nivel' => NIVEL_ADMIN],
            ],
        ],
    ];
}

/** Iniciais para o avatar. */
function iniciais($nome)
{
    $nome = trim((string) $nome);
    if ($nome === '') {
        return 'U';
    }
    $partes = preg_split('/\s+/', $nome);
    $ini = mb_substr($partes[0], 0, 1);
    if (count($partes) > 1) {
        $ini .= mb_substr(end($partes), 0, 1);
    } elseif (mb_strlen($nome) > 1) {
        $ini .= mb_substr($nome, 1, 1);
    }
    return mb_strtoupper($ini);
}

/** Cor estável para o avatar (mesma paleta do sistema anterior). */
function cor_avatar($nome)
{
    $cores = ['#dc2626', '#d97706', '#65a30d', '#059669', '#0891b2', '#0284c7', '#4f46e5', '#7c3aed', '#c026d3'];
    return $cores[abs(crc32((string) $nome)) % count($cores)];
}

/** Cabeçalho padrão de página (título, subtítulo e ações). */
function page_header($titulo, $subtitulo = '', $acoesHtml = '')
{
    echo '<div class="page-header"><div class="page-header-text"><h1>' . e($titulo) . '</h1>';
    if ($subtitulo !== '') {
        echo '<p>' . $subtitulo . '</p>';
    }
    echo '</div>';
    if ($acoesHtml !== '') {
        echo '<div class="page-actions">' . $acoesHtml . '</div>';
    }
    echo '</div>';
}

/** Estado vazio padronizado. */
function estado_vazio($titulo, $texto = '', $icone = 'fa-folder-open', $acaoHtml = '')
{
    return '<div class="empty-state"><div class="empty-icon"><i class="fa-solid ' . e($icone) . '"></i></div>'
        . '<h3>' . e($titulo) . '</h3>' . ($texto !== '' ? '<p>' . $texto . '</p>' : '')
        . ($acaoHtml !== '' ? '<div class="empty-action">' . $acaoHtml . '</div>' : '') . '</div>';
}

/** Abre o documento e a estrutura principal. */
function layout_inicio($titulo, $opcoes = [])
{
    global $pdo;
    $ativo = $opcoes['ativo'] ?? '';
    $GLOBALS['lf_ativo'] = $ativo;
    $b = BASE_URL;
    $v = APP_VERSION;
    $tema = (($_SESSION['tema'] ?? 'light') === 'dark') ? 'dark' : '';
    $nome = $_SESSION['nome_usuario'] ?? 'Usuário';
    $cargo = $_SESSION['cargo_usuario'] ?? '';
    $nivel = usuario_nivel();
    $sistema = nome_sistema();

    $pend = 0;
    if ($nivel >= NIVEL_EDITOR) {
        require_once __DIR__ . '/pendencias.php';
        $pend = pendencias_total($pdo);
    }
    ?><!DOCTYPE html>
<html lang="pt-br" class="<?php echo $tema; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="<?php echo e(csrf_token()); ?>">
    <meta name="base-url" content="<?php echo e($b); ?>">
    <meta name="theme-color" content="#0f172a">
    <title><?php echo e($titulo); ?> · <?php echo e($sistema); ?></title>
    <link rel="icon" type="image/png" href="<?php echo $b; ?>/images/logo_icone.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/vendor/fontawesome/css/all.min.css">
    <link rel="stylesheet" href="<?php echo $b; ?>/assets/css/app.css?v=<?php echo $v; ?>">
    <script>
        // Aplica o estado recolhido da sidebar antes da renderização (evita "piscar")
        try { if (localStorage.getItem('lf.sidebar') === 'collapsed' && window.innerWidth > 1024) document.documentElement.classList.add('sidebar-collapsed'); } catch (e) {}
    </script>
</head>
<body>
<a class="skip-link" href="#conteudo">Pular para o conteúdo</a>
<div class="app">
    <aside class="sidebar" id="sidebar" aria-label="Menu principal">
        <div class="sidebar-brand">
            <a href="<?php echo $b; ?>/index.php" class="brand-link" title="<?php echo e($sistema); ?>">
                <img src="<?php echo $b; ?>/images/logo_icone.png" alt="" class="brand-icon">
                <img src="<?php echo $b; ?>/images/logo.png" alt="<?php echo e($sistema); ?>" class="brand-logo">
            </a>
            <button type="button" class="sidebar-close" data-action="sidebar-close" aria-label="Fechar menu"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <nav class="sidebar-nav">
            <?php foreach (nav_estrutura() as $secao):
                $visiveis = array_filter($secao['itens'], function ($i) use ($nivel) { return $nivel >= $i['nivel']; });
                if (!$visiveis) { continue; } ?>
                <div class="nav-section">
                    <?php if ($secao['titulo']): ?><div class="nav-section-title"><?php echo e($secao['titulo']); ?></div><?php endif; ?>
                    <?php foreach ($visiveis as $item):
                        $isAtivo = $item['id'] === $ativo; ?>
                        <a href="<?php echo e($item['url']); ?>" class="nav-link<?php echo $isAtivo ? ' is-active' : ''; ?>"<?php echo $isAtivo ? ' aria-current="page"' : ''; ?> data-tooltip="<?php echo e($item['rotulo']); ?>">
                            <i class="fa-solid <?php echo e($item['icone']); ?>" aria-hidden="true"></i>
                            <span class="nav-label"><?php echo e($item['rotulo']); ?></span>
                            <?php if ($item['id'] === 'dashboard' && $pend > 0): ?><span class="nav-count" title="Pendências"><?php echo (int) $pend; ?></span><?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </nav>
        <div class="sidebar-footer">
            <button type="button" class="nav-link sidebar-collapse-btn" data-action="sidebar-collapse" data-tooltip="Expandir menu" aria-label="Recolher ou expandir menu">
                <i class="fa-solid fa-angles-left" aria-hidden="true"></i><span class="nav-label">Recolher menu</span>
            </button>
        </div>
    </aside>
    <div class="sidebar-backdrop" data-action="sidebar-close"></div>

    <div class="main">
        <header class="topbar">
            <button type="button" class="icon-btn topbar-menu" data-action="sidebar-open" aria-label="Abrir menu"><i class="fa-solid fa-bars"></i></button>
            <a href="<?php echo $b; ?>/index.php" class="topbar-brand-mobile"><img src="<?php echo $b; ?>/images/logo_icone.png" alt="" width="28" height="28"><span><?php echo e($sistema); ?></span></a>

            <?php if ($nivel >= NIVEL_EDITOR): ?>
            <form class="topbar-search" action="<?php echo $b; ?>/php/processos_detalhados.php" method="GET" role="search">
                <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                <input type="search" name="busca" placeholder="Buscar processo por número, autor ou matéria…" aria-label="Buscar processo" autocomplete="off" data-shortcut="/">
                <kbd>/</kbd>
            </form>
            <?php else: ?>
            <div class="topbar-spacer"></div>
            <?php endif; ?>

            <div class="topbar-actions">
                <?php if ($nivel >= NIVEL_ADMIN && modo_manutencao_ativo()): ?>
                    <a href="<?php echo $b; ?>/php/manutencao.php" class="chip chip-warning" title="Somente administradores conseguem acessar o sistema"><i class="fa-solid fa-triangle-exclamation"></i><span>Manutenção ativa</span></a>
                <?php endif; ?>
                <?php if ($nivel >= NIVEL_EDITOR): ?>
                    <a href="<?php echo $b; ?>/index.php#pendencias" class="icon-btn has-badge" aria-label="Pendências (<?php echo (int) $pend; ?>)" title="Pendências">
                        <i class="fa-regular fa-bell"></i><?php if ($pend > 0): ?><span class="icon-badge"><?php echo $pend > 99 ? '99+' : (int) $pend; ?></span><?php endif; ?>
                    </a>
                <?php endif; ?>
                <button type="button" class="icon-btn" data-action="toggle-theme" aria-label="Alternar tema claro/escuro" title="Alternar tema">
                    <i class="fa-regular fa-moon theme-icon-dark"></i><i class="fa-solid fa-sun theme-icon-light"></i>
                </button>
                <div class="dropdown">
                    <button type="button" class="user-btn" data-action="dropdown" aria-haspopup="true" aria-expanded="false">
                        <span class="avatar" style="background:<?php echo cor_avatar($nome); ?>"><?php echo e(iniciais($nome)); ?></span>
                        <span class="user-btn-text"><strong><?php echo e($nome); ?></strong><small><?php echo e($cargo !== '' ? $cargo : nome_nivel($nivel)); ?></small></span>
                        <i class="fa-solid fa-chevron-down user-btn-caret" aria-hidden="true"></i>
                    </button>
                    <div class="dropdown-menu dropdown-menu-right" role="menu">
                        <div class="dropdown-header">
                            <strong><?php echo e($nome); ?></strong>
                            <span><?php echo e(nome_nivel($nivel)); ?></span>
                        </div>
                        <a href="<?php echo $b; ?>/php/minha_conta.php" class="dropdown-item" role="menuitem"><i class="fa-regular fa-user"></i>Minha conta</a>
                        <div class="dropdown-divider"></div>
                        <a href="<?php echo $b; ?>/includes/logout.php" class="dropdown-item text-danger" role="menuitem"><i class="fa-solid fa-arrow-right-from-bracket"></i>Sair</a>
                    </div>
                </div>
            </div>
        </header>

        <main class="content" id="conteudo" tabindex="-1">
<?php
}

/** Fecha a estrutura principal e carrega os scripts. */
function layout_fim($opcoes = [])
{
    $b = BASE_URL;
    $v = APP_VERSION;
    $ativo = $opcoes['ativo'] ?? ($GLOBALS['lf_ativo'] ?? '');
    $nivel = usuario_nivel();
    ?>
        </main>
    </div>
</div>

<nav class="bottom-nav" aria-label="Navegação rápida">
    <a href="<?php echo $b; ?>/index.php" class="<?php echo $ativo === 'dashboard' ? 'is-active' : ''; ?>"><i class="fa-solid fa-gauge-high"></i><span>Início</span></a>
    <?php if ($nivel >= NIVEL_EDITOR): ?>
        <a href="<?php echo $b; ?>/php/processos_detalhados.php" class="<?php echo $ativo === 'processos' ? 'is-active' : ''; ?>"><i class="fa-solid fa-gavel"></i><span>Processos</span></a>
        <a href="<?php echo $b; ?>/php/totais_mensais.php" class="<?php echo $ativo === 'totais' ? 'is-active' : ''; ?>"><i class="fa-solid fa-table-cells"></i><span>Totais</span></a>
    <?php else: ?>
        <a href="<?php echo $b; ?>/php/performance_financeira.php" class="<?php echo $ativo === 'performance' ? 'is-active' : ''; ?>"><i class="fa-solid fa-chart-line"></i><span>Financeiro</span></a>
        <a href="<?php echo $b; ?>/php/volume_entrada.php" class="<?php echo $ativo === 'volume' ? 'is-active' : ''; ?>"><i class="fa-solid fa-chart-column"></i><span>Volume</span></a>
    <?php endif; ?>
    <button type="button" data-action="sidebar-open"><i class="fa-solid fa-bars"></i><span>Menu</span></button>
</nav>

<div class="toast-region" id="toasts" aria-live="polite" aria-atomic="false"></div>

<?php if (!empty($opcoes['dados'])): ?>
<script type="application/json" id="page-data"><?php echo json_encode($opcoes['dados'], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>
<?php endif; ?>
<?php
    $flashes = flashes_pendentes();
    if ($flashes): ?>
<script type="application/json" id="flash-data"><?php echo json_encode($flashes, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?></script>
<?php endif; ?>
<script src="<?php echo $b; ?>/assets/js/app.js?v=<?php echo $v; ?>"></script>
<?php if (!empty($opcoes['charts'])): ?>
<script src="<?php echo $b; ?>/assets/js/charts.js?v=<?php echo $v; ?>"></script>
<?php endif; ?>
<?php foreach (($opcoes['scripts'] ?? []) as $s): ?>
<script src="<?php echo $b; ?>/assets/js/<?php echo e($s); ?>?v=<?php echo $v; ?>"></script>
<?php endforeach; ?>
</body>
</html>
<?php
}

/** Seletor de ano que navega ao mudar (preserva demais filtros da URL). */
function seletor_ano($anos, $ano, $nome = 'ano', $permitirTodos = false)
{
    $h = '<form method="GET" class="row">';
    foreach ($_GET as $k => $v) {
        if ($k === $nome || $k === 'page' || is_array($v)) {
            continue;
        }
        $h .= '<input type="hidden" name="' . e($k) . '" value="' . e($v) . '">';
    }
    $h .= '<label for="sel-' . e($nome) . '" class="label">Ano</label><select name="' . e($nome) . '" id="sel-' . e($nome) . '" class="select select-sm" style="width:auto" data-autosubmit>';
    if ($permitirTodos) {
        $h .= '<option value="">Todos</option>';
    }
    foreach ($anos as $a) {
        $h .= '<option value="' . (int) $a . '"' . ((int) $a === (int) $ano ? ' selected' : '') . '>' . (int) $a . '</option>';
    }
    return $h . '</select><noscript><button class="btn btn-secondary btn-sm">Aplicar</button></noscript></form>';
}
