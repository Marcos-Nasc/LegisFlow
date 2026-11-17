<?php
/**
 * Relatório de Volume de Entradas: novas ações por mês, classificadas por tipo de vara.
 * Classificação original: "CÍVEIS"/"CIVEIS" → Cíveis, "TRABALHISTA" → Trabalhistas, demais → Outros.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
exigir_login();

$ano = get_int('ano', (int) date('Y'), 1990, 2100);
$meses = meses_nomes();

function volume_do_ano($pdo, $ano)
{
    $d = [];
    for ($i = 1; $i <= 12; $i++) { $d[$i] = ['civeis' => 0, 'trabalhistas' => 0, 'outros' => 0, 'total' => 0]; }
    $stmt = $pdo->prepare('SELECT mes, tipo_vara, quantidade FROM relatorio_mensal_processos WHERE ano = ?');
    $stmt->execute([$ano]);
    $varas = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $m = (int) $r['mes'];
        if ($m < 1 || $m > 12) { continue; }
        $q = (int) $r['quantidade'];
        $d[$m][classificar_vara($r['tipo_vara'])] += $q;
        $d[$m]['total'] += $q;
        $varas[$r['tipo_vara']] = ($varas[$r['tipo_vara']] ?? 0) + $q;
    }
    arsort($varas);
    return [$d, $varas];
}

try {
    list($dados, $porVara) = volume_do_ano($pdo, $ano);
    list($anterior) = volume_do_ano($pdo, $ano - 1);
} catch (PDOException $e) {
    $ref = erro_interno('Volume de entrada', $e);
    pagina_mensagem('Não foi possível carregar o relatório', "Erro ao consultar o banco de dados (ref. $ref).", 'fa-circle-exclamation', 'Tentar novamente', 'volume_entrada.php');
}

$tot = ['civeis' => 0, 'trabalhistas' => 0, 'outros' => 0, 'total' => 0];
foreach ($dados as $d) { foreach ($tot as $k => $v) { $tot[$k] += $d[$k]; } }
$totAnterior = array_sum(array_column($anterior, 'total'));
$totaisMes = array_column($dados, 'total');
$mesesComDados = count(array_filter($totaisMes));
$media = $mesesComDados ? $tot['total'] / $mesesComDados : 0;
$pico = $tot['total'] ? (int) array_search(max($totaisMes), $totaisMes) + 1 : null;

if (($_GET['action'] ?? '') === 'export') {
    exigir_nivel(NIVEL_EDITOR, 'Exportação: Volume de Entrada');
    registrar_log($pdo, 'Exportação CSV (Volume de Entrada)', usuario_id(), "Exportou o relatório de volume de entrada de $ano.");
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=volume_entrada_' . $ano . '.csv');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Mês', 'Cíveis', 'Trabalhistas', 'Outros', 'Total', 'Total ' . ($ano - 1)], ';');
    foreach ($dados as $m => $d) { fputcsv($out, [$meses[$m], $d['civeis'], $d['trabalhistas'], $d['outros'], $d['total'], $anterior[$m]['total']], ';'); }
    fputcsv($out, ['Total', $tot['civeis'], $tot['trabalhistas'], $tot['outros'], $tot['total'], $totAnterior], ';');
    fclose($out);
    exit();
}

/** Variação percentual (mais ações = sinal de alerta, por isso "subir" aparece em vermelho). */
function variacao($a, $b)
{
    if (!$b) { return '<span class="text-muted">—</span>'; }
    $p = ($a - $b) / $b * 100;
    $cls = abs($p) < 0.05 ? 'delta-flat' : ($p > 0 ? 'delta-down' : 'delta-up');
    return '<span class="delta ' . $cls . '">' . ($p > 0 ? '+' : '') . number_format($p, 1, ',', '.') . '%</span>';
}

layout_inicio('Volume de Entrada', ['ativo' => 'volume']);
page_header('Volume de entrada', 'Novas ações recebidas por mês em ' . $ano . ', a partir dos totais mensais por vara.',
    seletor_ano(anos_disponiveis($pdo), $ano) . (pode(NIVEL_EDITOR) ? '<a class="btn btn-secondary btn-sm" href="?action=export&ano=' . $ano . '"><i class="fa-solid fa-download"></i>Exportar</a>' : ''));
?>
<div class="grid grid-4 grid-kpi-mobile mb-6">
    <section class="card kpi kpi-hero">
        <div class="kpi-label"><i class="fa-solid fa-inbox"></i>Novas ações em <?php echo $ano; ?></div>
        <div class="kpi-value"><?php echo numero($tot['total']); ?></div>
        <div class="kpi-sub"><?php echo $totAnterior ? variacao($tot['total'], $totAnterior) . ' vs. ' . numero($totAnterior) . ' em ' . ($ano - 1) : 'Sem dados de ' . ($ano - 1); ?></div>
    </section>
    <section class="card kpi"><div class="kpi-label"><span class="status-dot" style="background:var(--chart-1)"></span>Cíveis</div><div class="kpi-value"><?php echo numero($tot['civeis']); ?></div><div class="kpi-sub"><?php echo $tot['total'] ? round($tot['civeis'] / $tot['total'] * 100) : 0; ?>% do total</div></section>
    <section class="card kpi"><div class="kpi-label"><span class="status-dot" style="background:var(--chart-2)"></span>Trabalhistas</div><div class="kpi-value"><?php echo numero($tot['trabalhistas']); ?></div><div class="kpi-sub"><?php echo $tot['total'] ? round($tot['trabalhistas'] / $tot['total'] * 100) : 0; ?>% do total</div></section>
    <section class="card kpi"><div class="kpi-label"><i class="fa-solid fa-calendar-days"></i>Média mensal</div><div class="kpi-value"><?php echo number_format($media, 1, ',', '.'); ?></div><div class="kpi-sub"><?php echo $pico ? 'Pico em ' . $meses[$pico] : 'Sem lançamentos'; ?></div></section>
</div>

<section class="card mb-6">
    <div class="card-header"><div><h2>Novas ações por mês</h2><p>Por tipo de vara</p></div></div>
    <div class="card-body"><?php echo $tot['total'] ? '<div class="chart-box" id="chart-volume"></div>' : estado_vazio('Sem totais mensais em ' . $ano, pode(NIVEL_EDITOR) ? 'Lance os totais por vara em Totais Mensais.' : 'Os totais deste ano ainda não foram lançados.', 'fa-chart-column', pode(NIVEL_EDITOR) ? '<a class="btn btn-primary btn-sm" href="totais_mensais.php?ano=' . $ano . '">Lançar totais</a>' : ''); ?></div>
</section>

<div class="grid grid-main-side">
    <section class="card">
        <div class="card-header"><div><h2>Detalhamento mensal</h2><p>Comparado com <?php echo $ano - 1; ?></p></div></div>
        <div class="table-wrap">
            <table class="table table-cards">
                <thead><tr><th>Mês</th><th class="num">Cíveis</th><th class="num">Trabalhistas</th><th class="num">Outros</th><th class="num">Total</th><th class="num"><?php echo $ano - 1; ?></th><th class="num">Variação</th></tr></thead>
                <tbody>
                <?php foreach ($dados as $m => $d): ?>
                    <tr>
                        <td class="card-title cell-strong"><?php echo $meses[$m]; ?></td>
                        <td data-label="Cíveis" class="num"><?php echo numero($d['civeis']); ?></td>
                        <td data-label="Trabalhistas" class="num"><?php echo numero($d['trabalhistas']); ?></td>
                        <td data-label="Outros" class="num"><?php echo numero($d['outros']); ?></td>
                        <td data-label="Total" class="num cell-strong"><?php echo numero($d['total']); ?></td>
                        <td data-label="<?php echo $ano - 1; ?>" class="num text-muted"><?php echo numero($anterior[$m]['total']); ?></td>
                        <td data-label="Variação" class="num"><?php echo ($d['total'] || $anterior[$m]['total']) ? variacao($d['total'], $anterior[$m]['total']) : '<span class="text-muted">—</span>'; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot><tr><td>Total</td><td class="num"><?php echo numero($tot['civeis']); ?></td><td class="num"><?php echo numero($tot['trabalhistas']); ?></td><td class="num"><?php echo numero($tot['outros']); ?></td><td class="num"><?php echo numero($tot['total']); ?></td><td class="num"><?php echo numero($totAnterior); ?></td><td class="num"><?php echo variacao($tot['total'], $totAnterior); ?></td></tr></tfoot>
            </table>
        </div>
    </section>
    <section class="card">
        <div class="card-header"><div><h2>Por tipo de vara</h2><p>Total do ano</p></div></div>
        <?php if ($porVara): $maxV = max($porVara); foreach ($porVara as $v => $q): $cls = classificar_vara($v); ?>
            <div class="bar-row">
                <span style="min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?php echo e($v); ?>"><?php echo e($v); ?></span>
                <span class="num cell-strong"><?php echo numero($q); ?></span>
                <span class="bar-track"><span class="bar-fill" style="width:<?php echo $maxV ? max(2, round($q / $maxV * 100)) : 0; ?>%;background:var(<?php echo $cls === 'civeis' ? '--chart-1' : ($cls === 'trabalhistas' ? '--chart-2' : '--chart-3'); ?>)"></span></span>
            </div>
        <?php endforeach; else: echo estado_vazio('Sem dados', '', 'fa-table-cells'); endif; ?>
    </section>
</div>

<?php layout_fim([
    'charts' => true,
    'scripts' => ['pages/relatorios.js'],
    'dados' => [
        'relatorio' => 'volume', 'ano' => $ano, 'meses' => array_values(meses_nomes(true)),
        'civeis' => array_values(array_column($dados, 'civeis')),
        'trabalhistas' => array_values(array_column($dados, 'trabalhistas')),
        'outros' => array_values(array_column($dados, 'outros')),
    ],
]);
