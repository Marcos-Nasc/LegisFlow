<?php
/**
 * Relatório de Provisão de Risco: soma do valor da causa dos processos do ano.
 * Padrão original preservado (todas as situações); o filtro de situação é opcional.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
exigir_login();

$ano = get_int('ano', (int) date('Y'), 1990, 2100);
$status = array_key_exists($_GET['status'] ?? '', status_processo_opcoes()) ? $_GET['status'] : '';
$porPagina = 20;
$pagina = get_int('page', 1, 1);

$where = 'WHERE ano = :ano' . ($status !== '' ? ' AND status = :status' : '');
$params = [':ano' => $ano] + ($status !== '' ? [':status' => $status] : []);

if (($_GET['action'] ?? '') === 'export') {
    exigir_nivel(NIVEL_EDITOR, 'Exportação: Provisão de Risco');
    registrar_log($pdo, 'Exportação CSV (Provisão de Risco)', usuario_id(), "Exportou o relatório de provisão de risco de $ano" . ($status ? " (situação: $status)" : '') . '.');
    $stmt = $pdo->prepare("SELECT numero_processo, autor, materia, status, valor_causa FROM processos_judiciais $where ORDER BY valor_causa DESC");
    $stmt->execute($params);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=provisao_risco_' . $ano . '.csv');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Nº do processo', 'Autor', 'Matéria', 'Situação', 'Valor da causa'], ';');
    while ($r = $stmt->fetch(PDO::FETCH_NUM)) {
        $r[4] = number_format((float) $r[4], 2, ',', '');
        fputcsv($out, $r, ';');
    }
    fclose($out);
    exit();
}

try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(valor_causa),0) AS total, COUNT(*) AS qtd, COALESCE(MAX(valor_causa),0) AS maior FROM processos_judiciais $where");
    $stmt->execute($params);
    $k = $stmt->fetch(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT mes, SUM(valor_causa) AS risco FROM processos_judiciais $where AND mes IS NOT NULL GROUP BY mes");
    $stmt->execute($params);
    $mensal = array_fill(0, 12, 0);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $m = (int) $r['mes'];
        if ($m >= 1 && $m <= 12) { $mensal[$m - 1] = round((float) $r['risco'], 2); }
    }
    $acumulado = [];
    $s = 0;
    foreach ($mensal as $v) { $s += $v; $acumulado[] = round($s, 2); }

    $stmt = $pdo->prepare('SELECT status, COALESCE(SUM(valor_causa),0) AS v, COUNT(*) AS q FROM processos_judiciais WHERE ano = ? GROUP BY status');
    $stmt->execute([$ano]);
    $porStatus = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) { $porStatus[$r['status']] = $r; }

    $total = (int) $k['qtd'];
    $totalPaginas = max(1, (int) ceil($total / $porPagina));
    $pagina = min($pagina, $totalPaginas);
    $stmt = $pdo->prepare("SELECT id, numero_processo, autor, materia, status, valor_causa FROM processos_judiciais $where ORDER BY valor_causa DESC, id DESC LIMIT :limit OFFSET :offset");
    foreach ($params as $kk => $v) { $stmt->bindValue($kk, $v); }
    $stmt->bindValue(':limit', $porPagina, PDO::PARAM_INT);
    $stmt->bindValue(':offset', ($pagina - 1) * $porPagina, PDO::PARAM_INT);
    $stmt->execute();
    $linhas = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $ref = erro_interno('Provisão de risco', $e);
    pagina_mensagem('Não foi possível carregar o relatório', "Erro ao consultar o banco de dados (ref. $ref).", 'fa-circle-exclamation', 'Tentar novamente', 'provisao_risco.php');
}
$podeGerir = pode(NIVEL_EDITOR);
$filtroStatus = '<form method="GET" class="row"><input type="hidden" name="ano" value="' . $ano . '"><label for="st" class="label">Situação</label><select name="status" id="st" class="select select-sm" style="width:auto" data-autosubmit><option value="">Todas</option>';
foreach (status_processo_opcoes() as $kk => $r) {
    $filtroStatus .= '<option value="' . e($kk) . '"' . ($status === $kk ? ' selected' : '') . '>' . e($r) . '</option>';
}
$filtroStatus .= '</select></form>';

layout_inicio('Provisão de Risco', ['ativo' => 'provisao']);
page_header('Provisão de risco', 'Soma do valor das causas dos processos recebidos em ' . $ano . ($status ? ' — situação: ' . e(status_processo_opcoes()[$status]) : '') . '.',
    seletor_ano(anos_disponiveis($pdo), $ano) . $filtroStatus . (pode(NIVEL_EDITOR) ? '<a class="btn btn-secondary btn-sm" href="?action=export&ano=' . $ano . '&status=' . urlencode($status) . '"><i class="fa-solid fa-download"></i>Exportar</a>' : ''));
?>
<div class="grid grid-3 mb-6">
    <section class="card kpi kpi-hero">
        <div class="kpi-label"><i class="fa-solid fa-scale-unbalanced"></i>Risco total provisionado</div>
        <div class="kpi-value" title="<?php echo moeda($k['total']); ?>"><?php echo moeda($k['total']); ?></div>
        <div class="kpi-sub"><?php echo numero($total); ?> processos em <?php echo $ano; ?></div>
    </section>
    <section class="card kpi">
        <div class="kpi-label"><i class="fa-solid fa-hourglass-half"></i>Ainda em andamento</div>
        <div class="kpi-value" title="<?php echo moeda($porStatus['em andamento']['v'] ?? 0); ?>"><?php echo moeda_compacta($porStatus['em andamento']['v'] ?? 0); ?></div>
        <div class="kpi-sub"><?php echo numero($porStatus['em andamento']['q'] ?? 0); ?> processos de <?php echo $ano; ?> em aberto</div>
    </section>
    <section class="card kpi">
        <div class="kpi-label"><i class="fa-solid fa-arrow-up-wide-short"></i>Maior causa</div>
        <div class="kpi-value" title="<?php echo moeda($k['maior']); ?>"><?php echo moeda_compacta($k['maior']); ?></div>
        <div class="kpi-sub">Média de <?php echo moeda_compacta($total ? $k['total'] / $total : 0); ?> por processo</div>
    </section>
</div>

<section class="card mb-6">
    <div class="card-header"><div><h2>Evolução do risco em <?php echo $ano; ?></h2><p>Valor das causas recebidas por mês e acumulado no ano</p></div>
        <div class="segmented" id="modo-risco"><button type="button" class="is-active" data-modo="mensal" aria-pressed="true">Mensal</button><button type="button" data-modo="acumulado" aria-pressed="false">Acumulado</button></div></div>
    <div class="card-body"><?php echo $total ? '<div class="chart-box" id="chart-risco"></div>' : estado_vazio('Sem processos neste filtro', '', 'fa-chart-line'); ?></div>
</section>

<section class="card">
    <div class="card-header"><div><h2>Processos por valor da causa</h2><p>Maiores riscos primeiro</p></div></div>
    <?php if ($linhas): ?>
    <div class="table-wrap">
        <table class="table table-cards">
            <thead><tr><th>Nº do processo</th><th>Autor</th><th>Matéria</th><th>Situação</th><th class="num">Valor da causa</th></tr></thead>
            <tbody>
            <?php foreach ($linhas as $l): ?>
                <tr<?php echo $podeGerir ? ' class="is-clickable" data-href="processos_detalhados.php?ver=' . (int) $l['id'] . '"' : ''; ?>>
                    <td class="card-title nowrap cell-strong"><?php echo e($l['numero_processo']); ?></td>
                    <td data-label="Autor"><?php echo e($l['autor']); ?></td>
                    <td data-label="Matéria"><span class="truncate" style="display:inline-block;max-width:280px;vertical-align:bottom" title="<?php echo e($l['materia']); ?>"><?php echo $l['materia'] ? e($l['materia']) : '<span class="text-muted">—</span>'; ?></span></td>
                    <td data-label="Situação"><?php echo badge_status($l['status']); ?></td>
                    <td data-label="Valor da causa" class="num cell-strong"><?php echo moeda($l['valor_causa']); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php echo paginacao($pagina, $totalPaginas); ?>
    <?php else: echo estado_vazio('Nenhum processo encontrado', 'Escolha outro ano ou situação.', 'fa-folder-open'); endif; ?>
</section>

<?php layout_fim([
    'charts' => true,
    'scripts' => ['pages/relatorios.js'],
    'dados' => ['relatorio' => 'provisao', 'ano' => $ano, 'meses' => array_values(meses_nomes(true)), 'mensal' => $mensal, 'acumulado' => $acumulado],
]);
