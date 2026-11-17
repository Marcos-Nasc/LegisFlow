<?php
/**
 * Relatório de Performance Financeira.
 *
 * Regra original preservada: economia = valor da causa − (despesas 1 + despesas 2 + valor pago).
 * (O Dashboard soma o campo "economia" informado; as duas visões estão sinalizadas na interface.)
 */
require_once __DIR__ . '/../includes/bootstrap.php';
exigir_login();

$ano = get_int('ano', (int) date('Y'), 1990, 2100);
$porPagina = 20;
$pagina = get_int('page', 1, 1);
$EXPR_ECON = '(valor_causa - (despesas_processuais_1 + despesas_processuais_2 + valor_pago))';
$ordenaveis = ['recente' => 'id DESC', 'economia' => "economia_proc DESC", 'economia_asc' => 'economia_proc ASC', 'valor' => 'valor_causa DESC', 'pago' => 'valor_pago DESC'];
$ordem = array_key_exists($_GET['ordem'] ?? '', $ordenaveis) ? $_GET['ordem'] : 'recente';

// Exportação CSV do relatório do ano
if (($_GET['action'] ?? '') === 'export') {
    exigir_nivel(NIVEL_EDITOR, 'Exportação: Performance Financeira');
    registrar_log($pdo, 'Exportação CSV (Performance Financeira)', usuario_id(), "Exportou o relatório de performance financeira de $ano.");
    $stmt = $pdo->prepare("SELECT numero_processo, autor, valor_causa, (despesas_processuais_1 + despesas_processuais_2) AS despesas, valor_pago, $EXPR_ECON AS economia_calculada FROM processos_judiciais WHERE ano = ? ORDER BY id DESC");
    $stmt->execute([$ano]);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=performance_financeira_' . $ano . '.csv');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Nº do processo', 'Autor', 'Valor da causa', 'Despesas', 'Valor pago', 'Economia (calculada)'], ';');
    while ($r = $stmt->fetch(PDO::FETCH_NUM)) {
        for ($i = 2; $i <= 5; $i++) { $r[$i] = number_format((float) $r[$i], 2, ',', ''); }
        fputcsv($out, $r, ';');
    }
    fclose($out);
    exit();
}

try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM($EXPR_ECON),0) AS economia, COALESCE(SUM(despesas_processuais_1 + despesas_processuais_2),0) AS despesas,
                                  COALESCE(SUM(valor_pago),0) AS pago, COALESCE(SUM(valor_causa),0) AS causa, COUNT(*) AS qtd
                             FROM processos_judiciais WHERE ano = ?");
    $stmt->execute([$ano]);
    $k = $stmt->fetch(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT mes, SUM($EXPR_ECON) AS eco, SUM(valor_pago) AS pago, SUM(despesas_processuais_1 + despesas_processuais_2) AS desp FROM processos_judiciais WHERE ano = ? GROUP BY mes");
    $stmt->execute([$ano]);
    $mensal = ['eco' => array_fill(0, 12, 0), 'pago' => array_fill(0, 12, 0), 'desp' => array_fill(0, 12, 0)];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $m = (int) $r['mes'];
        if ($m >= 1 && $m <= 12) {
            foreach (['eco', 'pago', 'desp'] as $c) { $mensal[$c][$m - 1] = round((float) $r[$c], 2); }
        }
    }

    $total = (int) $k['qtd'];
    $totalPaginas = max(1, (int) ceil($total / $porPagina));
    $pagina = min($pagina, $totalPaginas);
    $stmt = $pdo->prepare("SELECT id, numero_processo, autor, valor_causa, (despesas_processuais_1 + despesas_processuais_2) AS despesas, valor_pago, $EXPR_ECON AS economia_proc
                             FROM processos_judiciais WHERE ano = :ano ORDER BY {$ordenaveis[$ordem]}, id DESC LIMIT :limit OFFSET :offset");
    $stmt->bindValue(':ano', $ano, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $porPagina, PDO::PARAM_INT);
    $stmt->bindValue(':offset', ($pagina - 1) * $porPagina, PDO::PARAM_INT);
    $stmt->execute();
    $processos = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $ref = erro_interno('Performance financeira', $e);
    pagina_mensagem('Não foi possível carregar o relatório', "Erro ao consultar o banco de dados (ref. $ref).", 'fa-circle-exclamation', 'Tentar novamente', 'performance_financeira.php');
}

$taxa = (float) $k['causa'] > 0 ? ((float) $k['economia'] / (float) $k['causa']) * 100 : null;
$podeGerir = pode(NIVEL_EDITOR);

layout_inicio('Performance Financeira', ['ativo' => 'performance']);
page_header('Performance financeira', 'Economia obtida, despesas e valores pagos nos processos de ' . $ano . '.',
    seletor_ano(anos_disponiveis($pdo), $ano) . (pode(NIVEL_EDITOR) ? '<a class="btn btn-secondary btn-sm" href="?action=export&ano=' . $ano . '"><i class="fa-solid fa-download"></i>Exportar</a>' : ''));
?>

<div class="grid grid-4 grid-kpi-mobile mb-6">
    <section class="card kpi kpi-hero">
        <div class="kpi-label"><i class="fa-solid fa-piggy-bank"></i>Economia total</div>
        <div class="kpi-value" title="<?php echo moeda($k['economia']); ?>"><?php echo moeda_compacta($k['economia']); ?></div>
        <div class="kpi-sub"><?php echo $taxa !== null ? number_format($taxa, 1, ',', '.') . '% do valor total das causas' : 'Sem causas no ano'; ?></div>
    </section>
    <section class="card kpi">
        <div class="kpi-label"><i class="fa-solid fa-scale-unbalanced"></i>Valor das causas</div>
        <div class="kpi-value" title="<?php echo moeda($k['causa']); ?>"><?php echo moeda_compacta($k['causa']); ?></div>
        <div class="kpi-sub"><?php echo numero($total); ?> processos</div>
    </section>
    <section class="card kpi">
        <div class="kpi-label"><i class="fa-solid fa-receipt"></i>Despesas processuais</div>
        <div class="kpi-value text-warning" title="<?php echo moeda($k['despesas']); ?>"><?php echo moeda_compacta($k['despesas']); ?></div>
        <div class="kpi-sub">Despesas 1 + despesas 2</div>
    </section>
    <section class="card kpi">
        <div class="kpi-label"><i class="fa-solid fa-money-bill-transfer"></i>Valor pago</div>
        <div class="kpi-value text-danger" title="<?php echo moeda($k['pago']); ?>"><?php echo moeda_compacta($k['pago']); ?></div>
        <div class="kpi-sub">Condenações e acordos</div>
    </section>
</div>

<div class="alert alert-info mb-6"><i class="fa-solid fa-circle-info"></i><div class="alert-body">Neste relatório a economia é <strong>calculada</strong>: valor da causa − despesas − valor pago. O Dashboard mostra a soma do campo “Economia” informado em cada processo, por isso os números podem diferir.</div></div>

<div class="grid grid-main-side mb-6">
    <section class="card">
        <div class="card-header"><div><h2>Evolução mensal</h2><p>Por mês de referência do processo</p></div></div>
        <div class="card-body"><?php echo $total ? '<div class="chart-box" id="chart-mensal"></div>' : estado_vazio('Sem processos em ' . $ano, '', 'fa-chart-line'); ?></div>
    </section>
    <section class="card">
        <div class="card-header"><div><h2>Composição financeira</h2><p>Para onde foi o valor das causas</p></div></div>
        <div class="card-body"><div class="chart-box chart-box-sm" id="chart-composicao"></div></div>
    </section>
</div>

<section class="card">
    <div class="card-header">
        <div><h2>Processos de <?php echo $ano; ?></h2><p><?php echo numero($total); ?> processos</p></div>
        <form method="GET" class="row"><input type="hidden" name="ano" value="<?php echo $ano; ?>">
            <label for="ordem" class="label">Ordenar por</label>
            <select name="ordem" id="ordem" class="select select-sm" style="width:auto" data-autosubmit>
                <?php foreach (['recente' => 'Mais recentes', 'economia' => 'Maior economia', 'economia_asc' => 'Menor economia', 'valor' => 'Maior valor da causa', 'pago' => 'Maior valor pago'] as $kk => $r): ?>
                    <option value="<?php echo $kk; ?>" <?php echo $ordem === $kk ? 'selected' : ''; ?>><?php echo $r; ?></option>
                <?php endforeach; ?>
            </select></form>
    </div>
    <?php if ($processos): ?>
    <div class="table-wrap">
        <table class="table table-cards">
            <thead><tr><th>Nº do processo</th><th>Autor</th><th class="num">Valor da causa</th><th class="num">Despesas</th><th class="num">Valor pago</th><th class="num">Economia</th></tr></thead>
            <tbody>
            <?php foreach ($processos as $p): ?>
                <tr<?php echo $podeGerir ? ' class="is-clickable" data-href="processos_detalhados.php?ver=' . (int) $p['id'] . '"' : ''; ?>>
                    <td class="card-title nowrap cell-strong"><?php echo e($p['numero_processo']); ?></td>
                    <td data-label="Autor"><?php echo e($p['autor']); ?></td>
                    <td data-label="Valor da causa" class="num"><?php echo moeda($p['valor_causa']); ?></td>
                    <td data-label="Despesas" class="num"><?php echo moeda($p['despesas']); ?></td>
                    <td data-label="Valor pago" class="num"><?php echo moeda($p['valor_pago']); ?></td>
                    <td data-label="Economia" class="num cell-strong <?php echo $p['economia_proc'] >= 0 ? 'text-success' : 'text-danger'; ?>"><?php echo moeda($p['economia_proc']); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php echo paginacao($pagina, $totalPaginas); ?>
    <?php else: echo estado_vazio('Nenhum processo em ' . $ano, 'Escolha outro ano no seletor acima.', 'fa-folder-open'); endif; ?>
</section>

<?php layout_fim([
    'charts' => true,
    'scripts' => ['pages/relatorios.js'],
    'dados' => [
        'relatorio' => 'performance',
        'ano' => $ano,
        'meses' => array_values(meses_nomes(true)),
        'mensal' => $mensal,
        'composicao' => ['economia' => max(0, (float) $k['economia']), 'despesas' => (float) $k['despesas'], 'pago' => (float) $k['pago']],
    ],
]);
