<?php
/**
 * Dashboard — visão geral do ano selecionado.
 */
require_once __DIR__ . '/includes/bootstrap.php';
exigir_login();

$anos = anos_disponiveis($pdo);
$ano = get_int('ano', (int) date('Y'), 1990, 2100);
$anoAnterior = $ano - 1;
$podeGerir = pode(NIVEL_EDITOR);

/** Variação percentual formatada como badge. */
function delta_html($atual, $anterior, $inverter, $anoRef)
{
    if ((float) $anterior == 0.0) {
        return '<span class="delta delta-flat">sem base de comparação</span>';
    }
    $pct = (($atual - $anterior) / abs($anterior)) * 100;
    if (abs($pct) < 0.05) {
        return '<span class="delta delta-flat">= ano anterior</span>';
    }
    $sobe = $pct > 0;
    $bom = $inverter ? !$sobe : $sobe;
    return '<span class="delta ' . ($bom ? 'delta-up' : 'delta-down') . '"><i class="fa-solid fa-arrow-' . ($sobe ? 'up' : 'down') . '"></i>'
        . number_format(abs($pct), 1, ',', '.') . '%</span> <span>vs. ' . (int) $anoRef . '</span>';
}

try {
    // Indicadores do ano e do ano anterior (uma consulta)
    $stmt = $pdo->prepare(
        "SELECT ano,
                COUNT(id) AS total,
                COALESCE(SUM(valor_causa), 0) AS risco,
                COALESCE(SUM(economia), 0) AS economia,
                COALESCE(SUM(valor_pago), 0) AS pago,
                SUM(CASE WHEN status = 'em andamento' THEN 1 ELSE 0 END) AS andamento
           FROM processos_judiciais WHERE ano IN (?, ?) GROUP BY ano"
    );
    $stmt->execute([$ano, $anoAnterior]);
    $k = [$ano => null, $anoAnterior => null];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $k[(int) $r['ano']] = $r;
    }
    $zero = ['total' => 0, 'risco' => 0, 'economia' => 0, 'pago' => 0, 'andamento' => 0];
    $atual = $k[$ano] ?: $zero;
    $prev = $k[$anoAnterior] ?: $zero;

    // Volume de entrada mensal (totais mensais)
    $stmt = $pdo->prepare('SELECT mes, tipo_vara, quantidade FROM relatorio_mensal_processos WHERE ano = ?');
    $stmt->execute([$ano]);
    $vol = ['civeis' => array_fill(0, 12, 0), 'trabalhistas' => array_fill(0, 12, 0), 'outros' => array_fill(0, 12, 0)];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $m = (int) $r['mes'];
        if ($m >= 1 && $m <= 12) {
            $vol[classificar_vara($r['tipo_vara'])][$m - 1] += (int) $r['quantidade'];
        }
    }
    $totalEntradas = array_sum($vol['civeis']) + array_sum($vol['trabalhistas']) + array_sum($vol['outros']);

    // Processos por situação (ano)
    $stmt = $pdo->prepare('SELECT status, COUNT(*) AS qtd, COALESCE(SUM(valor_causa),0) AS valor FROM processos_judiciais WHERE ano = ? GROUP BY status');
    $stmt->execute([$ano]);
    $porStatus = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $porStatus[$r['status'] ?? ''] = $r;
    }

    // Últimos processos recebidos no ano
    $stmt = $pdo->prepare('SELECT id, numero_processo, autor, data_recebimento, valor_causa, status FROM processos_judiciais WHERE ano = ? ORDER BY data_recebimento DESC, id DESC LIMIT 7');
    $stmt->execute([$ano]);
    $recentes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Maiores causas em andamento (todas as datas)
    $maiores = $pdo->query("SELECT id, numero_processo, autor, valor_causa, data_recebimento FROM processos_judiciais WHERE status = 'em andamento' ORDER BY valor_causa DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
    $maxCausa = $maiores ? max(1, (float) $maiores[0]['valor_causa']) : 1;

    $pendencias = $podeGerir ? pendencias_listar($pdo, true) : [];
} catch (PDOException $e) {
    $ref = erro_interno('Dashboard', $e);
    http_response_code(500);
    pagina_mensagem('Não foi possível carregar o Dashboard', "Ocorreu um erro ao consultar o banco de dados (ref. $ref). Tente novamente.", 'fa-circle-exclamation', 'Tentar novamente', BASE_URL . '/index.php');
}

$primeiroNome = explode(' ', trim($_SESSION['nome_usuario'] ?? 'Usuário'))[0];
$hora = (int) date('G');
$saudacao = $hora < 12 ? 'Bom dia' : ($hora < 18 ? 'Boa tarde' : 'Boa noite');

$seletorAno = '<form method="GET" class="row"><label for="ano" class="label">Ano</label><select name="ano" id="ano" class="select select-sm" style="width:auto" data-autosubmit>';
foreach ($anos as $a) {
    $seletorAno .= '<option value="' . $a . '"' . ($a === $ano ? ' selected' : '') . '>' . $a . '</option>';
}
$seletorAno .= '</select><noscript><button class="btn btn-secondary btn-sm">Aplicar</button></noscript></form>';

layout_inicio('Dashboard', ['ativo' => 'dashboard']);
page_header($saudacao . ', ' . $primeiroNome, 'Panorama jurídico de ' . $ano . '.', $seletorAno);
?>

<div class="grid grid-4 grid-kpi-mobile mb-6">
    <section class="card kpi kpi-hero">
        <div class="kpi-label"><i class="fa-solid fa-scale-unbalanced"></i>Risco provisionado</div>
        <div class="kpi-value" title="<?php echo moeda($atual['risco']); ?>"><?php echo moeda_compacta($atual['risco']); ?></div>
        <div class="kpi-sub"><?php echo delta_html($atual['risco'], $prev['risco'], true, $anoAnterior); ?></div>
    </section>
    <section class="card kpi">
        <div class="kpi-label"><i class="fa-solid fa-gavel"></i>Processos recebidos</div>
        <div class="kpi-value"><?php echo numero($atual['total']); ?></div>
        <div class="kpi-sub"><?php echo numero($atual['andamento']); ?> em andamento</div>
    </section>
    <section class="card kpi">
        <div class="kpi-label"><i class="fa-solid fa-piggy-bank"></i>Economia
            <span class="info-tip" title="Soma do campo “Economia” informado em cada processo do ano."><i class="fa-regular fa-circle-question"></i></span></div>
        <div class="kpi-value text-success" title="<?php echo moeda($atual['economia']); ?>"><?php echo moeda_compacta($atual['economia']); ?></div>
        <div class="kpi-sub"><?php echo delta_html($atual['economia'], $prev['economia'], false, $anoAnterior); ?></div>
    </section>
    <section class="card kpi">
        <div class="kpi-label"><i class="fa-solid fa-money-bill-transfer"></i>Valor pago</div>
        <div class="kpi-value" title="<?php echo moeda($atual['pago']); ?>"><?php echo moeda_compacta($atual['pago']); ?></div>
        <div class="kpi-sub"><?php echo delta_html($atual['pago'], $prev['pago'], true, $anoAnterior); ?></div>
    </section>
</div>

<div class="grid grid-main-side mb-6">
    <section class="card">
        <div class="card-header">
            <div><h2>Volume de entrada</h2><p><?php echo numero($totalEntradas); ?> novas ações em <?php echo $ano; ?>, por tipo de vara</p></div>
            <a href="<?php echo BASE_URL; ?>/php/volume_entrada.php?ano=<?php echo $ano; ?>" class="btn btn-ghost btn-sm">Ver relatório</a>
        </div>
        <div class="card-body">
            <?php if ($totalEntradas > 0): ?>
                <div class="chart-box" id="chart-volume"></div>
            <?php else: echo estado_vazio('Sem totais mensais em ' . $ano, $podeGerir ? 'Lance os totais por vara para ver a evolução mensal.' : 'Os totais mensais deste ano ainda não foram lançados.', 'fa-chart-column', $podeGerir ? '<a class="btn btn-primary btn-sm" href="' . BASE_URL . '/php/totais_mensais.php?ano=' . $ano . '">Lançar totais</a>' : ''); endif; ?>
        </div>
    </section>

    <?php if ($podeGerir): ?>
    <section class="card" id="pendencias">
        <div class="card-header"><div><h2>Pendências</h2><p>O que precisa de atenção agora</p></div></div>
        <?php if ($pendencias): ?>
            <ul class="list">
                <?php foreach ($pendencias as $p): ?>
                <li class="list-item">
                    <a class="list-link" href="<?php echo e($p['url']); ?>">
                        <span class="list-icon <?php echo e($p['severidade']); ?>"><i class="fa-solid <?php echo e($p['icone']); ?>"></i></span>
                        <span class="list-body"><span class="list-title"><?php echo e($p['titulo']); ?></span><span class="list-desc" style="display:block"><?php echo e($p['descricao']); ?></span></span>
                        <span class="list-qty"><?php echo numero($p['quantidade']); ?></span>
                        <i class="fa-solid fa-chevron-right text-muted" style="font-size:12px"></i>
                    </a>
                </li>
                <?php endforeach; ?>
            </ul>
        <?php else: echo estado_vazio('Tudo em dia', 'Nenhuma pendência de lançamento ou cadastro no momento.', 'fa-circle-check'); endif; ?>
    </section>
    <?php else: ?>
    <section class="card">
        <div class="card-header"><div><h2>Situação dos processos</h2><p>Recebidos em <?php echo $ano; ?></p></div></div>
        <div class="card-body"><div class="chart-box chart-box-sm" id="chart-status"></div></div>
    </section>
    <?php endif; ?>
</div>

<div class="grid grid-main-side">
    <section class="card">
        <div class="card-header">
            <div><h2>Últimos processos recebidos</h2><p>Em <?php echo $ano; ?></p></div>
            <?php if ($podeGerir): ?><a href="<?php echo BASE_URL; ?>/php/processos_detalhados.php?ano=<?php echo $ano; ?>" class="btn btn-ghost btn-sm">Ver todos</a><?php endif; ?>
        </div>
        <?php if ($recentes): ?>
        <div class="table-wrap">
            <table class="table table-cards">
                <thead><tr><th>Nº do processo</th><th>Autor</th><th>Recebido em</th><th>Situação</th><th class="num">Valor da causa</th></tr></thead>
                <tbody>
                <?php foreach ($recentes as $r): ?>
                    <tr<?php if ($podeGerir): ?> class="is-clickable" data-href="<?php echo BASE_URL; ?>/php/processos_detalhados.php?ver=<?php echo (int) $r['id']; ?>"<?php endif; ?>>
                        <td class="card-title nowrap"><?php echo e($r['numero_processo']); ?></td>
                        <td data-label="Autor"><span class="truncate" style="display:inline-block;max-width:220px;vertical-align:bottom" title="<?php echo e($r['autor']); ?>"><?php echo e($r['autor']); ?></span></td>
                        <td data-label="Recebido em" class="nowrap"><?php echo data_br($r['data_recebimento']); ?></td>
                        <td data-label="Situação"><?php echo badge_status($r['status']); ?></td>
                        <td data-label="Valor da causa" class="num"><?php echo moeda($r['valor_causa']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: echo estado_vazio('Nenhum processo recebido em ' . $ano, '', 'fa-gavel', $podeGerir ? '<a class="btn btn-primary btn-sm" href="' . BASE_URL . '/php/processos_detalhados.php?novo=1">Cadastrar processo</a>' : ''); endif; ?>
    </section>

    <div class="stack">
        <?php if ($podeGerir): ?>
        <section class="card">
            <div class="card-header"><div><h2>Situação dos processos</h2><p>Recebidos em <?php echo $ano; ?></p></div></div>
            <?php
            $totalStatus = array_sum(array_map(function ($r) { return (int) $r['qtd']; }, $porStatus));
            foreach (status_processo_opcoes() as $chave => $rotulo):
                $q = (int) ($porStatus[$chave]['qtd'] ?? 0);
                $pct = $totalStatus ? round($q / $totalStatus * 100) : 0;
                $cor = $chave === 'em andamento' ? 'var(--chart-2)' : ($chave === 'finalizado' ? 'var(--chart-4)' : 'var(--chart-3)'); ?>
                <a class="bar-row" style="color:inherit;text-decoration:none" href="<?php echo BASE_URL; ?>/php/processos_detalhados.php?ano=<?php echo $ano; ?>&status=<?php echo urlencode($chave); ?>">
                    <span><?php echo e($rotulo); ?></span>
                    <span class="num"><strong><?php echo numero($q); ?></strong> <span class="text-muted"><?php echo $pct; ?>%</span></span>
                    <span class="bar-track"><span class="bar-fill" style="width:<?php echo $pct; ?>%;background:<?php echo $cor; ?>"></span></span>
                </a>
            <?php endforeach; ?>
        </section>
        <?php endif; ?>

        <section class="card">
            <div class="card-header"><div><h2>Maiores causas em andamento</h2><p>Todos os anos, por valor da causa</p></div></div>
            <?php if ($maiores): foreach ($maiores as $m): ?>
                <<?php echo $podeGerir ? 'a href="' . BASE_URL . '/php/processos_detalhados.php?ver=' . (int) $m['id'] . '"' : 'div'; ?> class="bar-row" style="color:inherit;text-decoration:none">
                    <span style="min-width:0"><span class="cell-strong" style="display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?php echo e($m['autor']); ?></span><span class="hint"><?php echo e($m['numero_processo']); ?></span></span>
                    <span class="num cell-strong"><?php echo moeda_compacta($m['valor_causa']); ?></span>
                    <span class="bar-track"><span class="bar-fill" style="width:<?php echo max(2, round($m['valor_causa'] / $maxCausa * 100)); ?>%"></span></span>
                </<?php echo $podeGerir ? 'a' : 'div'; ?>>
            <?php endforeach; else: echo estado_vazio('Nenhum processo em andamento', '', 'fa-scale-balanced'); endif; ?>
        </section>
    </div>
</div>

<?php
layout_fim([
    'charts' => true,
    'scripts' => ['pages/dashboard.js'],
    'dados' => [
        'meses' => array_values(meses_nomes(true)),
        'ano' => $ano,
        'volume' => $vol,
        'status' => [
            'em andamento' => (int) ($porStatus['em andamento']['qtd'] ?? 0),
            'finalizado' => (int) ($porStatus['finalizado']['qtd'] ?? 0),
            'arquivado' => (int) ($porStatus['arquivado']['qtd'] ?? 0),
        ],
    ],
]);
