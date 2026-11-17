<?php
/**
 * Logs de atividade (somente Administrador), com filtros e exportação.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
exigir_login();
exigir_nivel(NIVEL_ADMIN, 'Logs de Atividades');

$f = [
    'usuario' => get_int('usuario', null, 0),
    'acao'    => trim((string) ($_GET['acao'] ?? '')),
    'busca'   => trim((string) ($_GET['busca'] ?? '')),
    'de'      => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['de'] ?? '') ? $_GET['de'] : '',
    'ate'     => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['ate'] ?? '') ? $_GET['ate'] : '',
    'tipo'    => in_array($_GET['tipo'] ?? '', ['falhas', 'acessos', 'seguranca'], true) ? $_GET['tipo'] : '',
];
$w = [];
$p = [];
if ($f['usuario'] !== null) {
    if ($f['usuario'] === 0) { $w[] = 'l.id_usuario IS NULL'; } else { $w[] = 'l.id_usuario = :u'; $p[':u'] = $f['usuario']; }
}
if ($f['acao'] !== '') { $w[] = 'l.acao = :a'; $p[':a'] = $f['acao']; }
if ($f['busca'] !== '') { $w[] = '(l.detalhes LIKE :b OR l.acao LIKE :b OR l.ip_usuario LIKE :b)'; $p[':b'] = '%' . $f['busca'] . '%'; }
if ($f['de'] !== '') { $w[] = 'l.data_ocorrencia >= :de'; $p[':de'] = $f['de'] . ' 00:00:00'; }
if ($f['ate'] !== '') { $w[] = 'l.data_ocorrencia <= :ate'; $p[':ate'] = $f['ate'] . ' 23:59:59'; }
if ($f['tipo'] === 'falhas') { $w[] = "(l.acao LIKE '%Falha%' OR l.acao LIKE '%Erro%')"; }
if ($f['tipo'] === 'acessos') { $w[] = "(l.acao LIKE '%Login%' OR l.acao LIKE '%Logout%')"; }
if ($f['tipo'] === 'seguranca') { $w[] = "(l.acao LIKE '%não autorizado%' OR l.acao LIKE '%Bloqueado%' OR l.acao = 'Falha no Login' OR l.acao LIKE '%Senha%')"; }
$where = $w ? ' WHERE ' . implode(' AND ', $w) : '';

if (($_GET['action'] ?? '') === 'export') {
    registrar_log($pdo, 'Exportação CSV (Logs)', usuario_id(), 'Exportou os logs de atividade.');
    $stmt = $pdo->prepare("SELECT l.data_ocorrencia, u.nome, l.acao, l.detalhes, l.ip_usuario FROM logs l LEFT JOIN usuarios u ON u.id = l.id_usuario $where ORDER BY l.data_ocorrencia DESC, l.id DESC");
    $stmt->execute($p);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=logs_' . date('Y-m-d') . '.csv');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Data/hora', 'Usuário', 'Ação', 'Detalhes', 'IP'], ';');
    while ($r = $stmt->fetch(PDO::FETCH_NUM)) { fputcsv($out, $r, ';'); }
    fclose($out);
    exit();
}

$porPagina = 30;
$pagina = get_int('page', 1, 1);
$stmt = $pdo->prepare("SELECT COUNT(*) FROM logs l $where");
$stmt->execute($p);
$total = (int) $stmt->fetchColumn();
$totalPaginas = max(1, (int) ceil($total / $porPagina));
$pagina = min($pagina, $totalPaginas);
$stmt = $pdo->prepare("SELECT l.id, l.acao, l.detalhes, l.ip_usuario, l.data_ocorrencia, u.nome AS nome_usuario
                         FROM logs l LEFT JOIN usuarios u ON l.id_usuario = u.id $where
                        ORDER BY l.data_ocorrencia DESC, l.id DESC LIMIT :limit OFFSET :offset");
foreach ($p as $k => $v) { $stmt->bindValue($k, $v); }
$stmt->bindValue(':limit', $porPagina, PDO::PARAM_INT);
$stmt->bindValue(':offset', ($pagina - 1) * $porPagina, PDO::PARAM_INT);
$stmt->execute();
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

$usuarios = $pdo->query('SELECT id, nome FROM usuarios ORDER BY nome')->fetchAll(PDO::FETCH_KEY_PAIR);
$acoes = $pdo->query('SELECT DISTINCT acao FROM logs ORDER BY acao')->fetchAll(PDO::FETCH_COLUMN);
$falhas24h = (int) $pdo->query("SELECT COUNT(*) FROM logs WHERE acao = 'Falha no Login' AND data_ocorrencia > DATE_SUB(NOW(), INTERVAL 1 DAY)")->fetchColumn();

/** Ícone/cor conforme o tipo de ação. */
function estilo_acao($acao)
{
    $a = mb_strtolower($acao);
    if (strpos($a, 'falha') !== false || strpos($a, 'erro') !== false || strpos($a, 'não autorizado') !== false || strpos($a, 'bloqueado') !== false) { return ['danger', 'fa-triangle-exclamation']; }
    if (strpos($a, 'exclus') !== false) { return ['warning', 'fa-trash-can']; }
    if (strpos($a, 'login') !== false || strpos($a, 'logout') !== false) { return ['neutral', 'fa-right-to-bracket']; }
    if (strpos($a, 'export') !== false) { return ['info', 'fa-download']; }
    if (strpos($a, 'import') !== false) { return ['info', 'fa-file-import']; }
    return ['success', 'fa-pen'];
}
$qs = http_build_query(array_filter($f, function ($v) { return $v !== '' && $v !== null; }));

layout_inicio('Logs de Atividade', ['ativo' => 'logs']);
page_header('Logs de atividade', 'Tudo o que foi feito no sistema, por quem e quando.', '<a class="btn btn-secondary" href="?action=export&' . e($qs) . '"><i class="fa-solid fa-download"></i>Exportar CSV</a>');
?>
<?php if ($falhas24h >= 5): ?>
<div class="alert alert-warning mb-4"><i class="fa-solid fa-shield-halved"></i><div class="alert-body"><strong><?php echo $falhas24h; ?> tentativas de login sem sucesso</strong> nas últimas 24 horas. <a href="?tipo=seguranca">Ver eventos de segurança</a></div></div>
<?php endif; ?>
<section class="card">
    <div class="tabs">
        <?php foreach (['' => 'Todos', 'acessos' => 'Acessos', 'falhas' => 'Falhas e erros', 'seguranca' => 'Segurança'] as $k => $r): ?>
            <a href="<?php echo e(url_com(['tipo' => $k, 'page' => null])); ?>" class="<?php echo $f['tipo'] === $k ? 'is-active' : ''; ?>"><?php echo $r; ?></a>
        <?php endforeach; ?>
    </div>
    <form method="GET" class="toolbar">
        <?php if ($f['tipo']): ?><input type="hidden" name="tipo" value="<?php echo e($f['tipo']); ?>"><?php endif; ?>
        <div class="field grow"><label for="busca">Buscar nos detalhes</label><input type="search" class="input input-sm" id="busca" name="busca" value="<?php echo e($f['busca']); ?>" placeholder="Ex.: número do processo, e-mail, IP"></div>
        <div class="field"><label for="usuario">Usuário</label><select class="select select-sm" id="usuario" name="usuario" data-autosubmit><option value="">Todos</option><option value="0" <?php echo $f['usuario'] === 0 ? 'selected' : ''; ?>>(Sem usuário)</option><?php foreach ($usuarios as $id => $n): ?><option value="<?php echo (int) $id; ?>" <?php echo $f['usuario'] === (int) $id ? 'selected' : ''; ?>><?php echo e($n); ?></option><?php endforeach; ?></select></div>
        <div class="field"><label for="acao">Ação</label><select class="select select-sm" id="acao" name="acao" data-autosubmit><option value="">Todas</option><?php foreach ($acoes as $a): ?><option <?php echo $f['acao'] === $a ? 'selected' : ''; ?>><?php echo e($a); ?></option><?php endforeach; ?></select></div>
        <div class="field"><label for="de">De</label><input type="date" class="input input-sm" id="de" name="de" value="<?php echo e($f['de']); ?>"></div>
        <div class="field"><label for="ate">Até</label><input type="date" class="input input-sm" id="ate" name="ate" value="<?php echo e($f['ate']); ?>"></div>
        <div class="toolbar-actions"><button class="btn btn-secondary btn-sm" data-no-loading="1">Filtrar</button><?php if ($qs !== ''): ?><a href="logs.php" class="btn btn-ghost btn-sm">Limpar</a><?php endif; ?></div>
    </form>
    <div class="result-meta"><span><strong class="num" style="color:var(--text)"><?php echo numero($total); ?></strong> registros</span></div>
    <?php if ($logs): ?>
    <div class="table-wrap">
        <table class="table table-cards">
            <thead><tr><th>Data e hora</th><th>Usuário</th><th>Ação</th><th>Detalhes</th><th>IP</th></tr></thead>
            <tbody>
            <?php foreach ($logs as $l): list($cor, $ic) = estilo_acao($l['acao']); ?>
                <tr>
                    <td class="card-title nowrap num"><?php echo data_br($l['data_ocorrencia'], true); ?></td>
                    <td data-label="Usuário"><?php echo $l['nome_usuario'] ? e($l['nome_usuario']) : '<span class="text-muted">—</span>'; ?></td>
                    <td data-label="Ação"><span class="row" style="flex-wrap:nowrap;gap:8px"><span class="list-icon <?php echo $cor; ?>" style="width:26px;height:26px;font-size:11px"><i class="fa-solid <?php echo $ic; ?>"></i></span><span><?php echo e($l['acao']); ?></span></span></td>
                    <td data-label="Detalhes"><span style="display:block;max-width:480px;overflow-wrap:anywhere;color:var(--text-2);font-size:13px"><?php echo e($l['detalhes']); ?></span></td>
                    <td data-label="IP" class="nowrap text-muted"><?php echo e($l['ip_usuario']); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php echo paginacao($pagina, $totalPaginas); ?>
    <?php else: echo estado_vazio('Nenhum registro encontrado', 'Ajuste os filtros ou o período.', 'fa-clock-rotate-left'); endif; ?>
</section>
<?php layout_fim();
