<?php
/**
 * Gestão de totais mensais de novas ações por tipo de vara (Editor e Administrador).
 */
require_once __DIR__ . '/../includes/bootstrap.php';
exigir_login();
exigir_nivel(NIVEL_EDITOR, 'Gestão de Totais Mensais');

$uid = usuario_id();
$ano = get_int('ano', (int) date('Y'), 1990, 2100);
$mesesCurto = meses_nomes(true);

/* =========================================================================
 * Exportação CSV (formato original)
 * ========================================================================= */
if (($_GET['action'] ?? '') === 'export') {
    registrar_log($pdo, 'Exportação CSV (Totais Mensais)', $uid, "Usuário (ID: $uid) exportou o relatório CSV de Totais Mensais para o ano: $ano.");
    try {
        $stmt = $pdo->prepare('SELECT mes, tipo_vara, quantidade FROM relatorio_mensal_processos WHERE ano = ? ORDER BY tipo_vara');
        $stmt->execute([$ano]);
        $piv = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $piv[$r['tipo_vara']][(int) $r['mes']] = $r['quantidade'];
        }
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=relatorio_mensal_' . $ano . '.csv');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, array_merge(['Varas'], array_values($mesesCurto)));
        foreach ($piv as $vara => $meses) {
            $linha = [$vara];
            for ($m = 1; $m <= 12; $m++) { $linha[] = $meses[$m] ?? '0'; }
            fputcsv($out, $linha);
        }
        fclose($out);
        exit();
    } catch (PDOException $e) {
        $ref = erro_interno('Exportar totais', $e);
        pagina_mensagem('Falha na exportação', "Não foi possível gerar o arquivo (ref. $ref).", 'fa-circle-exclamation', 'Voltar', 'totais_mensais.php');
    }
}

/* =========================================================================
 * AJAX: salvar célula / excluir vara
 * ========================================================================= */
if (is_post()) {
    $acao = $_POST['action'] ?? '';

    if ($acao === 'salvar_manual') {
        $a = filter_input(INPUT_POST, 'ano', FILTER_VALIDATE_INT);
        $m = filter_input(INPUT_POST, 'mes', FILTER_VALIDATE_INT);
        $vara = trim((string) ($_POST['tipo_vara'] ?? ''));
        $qtd = filter_input(INPUT_POST, 'quantidade', FILTER_VALIDATE_INT);

        if (!$a || $a < 1990 || $a > 2100 || !$m || $m < 1 || $m > 12 || $vara === '' || $qtd === false || $qtd === null) {
            responder_json(['status' => 'error', 'message' => 'Preencha ano, mês, tipo de vara e uma quantidade inteira.'], 422);
        }
        if ($qtd < 0) {
            responder_json(['status' => 'error', 'message' => 'A quantidade não pode ser negativa.'], 422);
        }
        if (mb_strlen($vara) > 100) {
            responder_json(['status' => 'error', 'message' => 'O nome da vara deve ter no máximo 100 caracteres.'], 422);
        }
        try {
            $stmt = $pdo->prepare('SELECT quantidade FROM relatorio_mensal_processos WHERE ano = ? AND mes = ? AND tipo_vara = ?');
            $stmt->execute([$a, $m, $vara]);
            $anterior = $stmt->fetchColumn();

            $pdo->prepare('INSERT INTO relatorio_mensal_processos (ano, mes, tipo_vara, quantidade, usuario_id) VALUES (?, ?, ?, ?, ?)
                           ON DUPLICATE KEY UPDATE quantidade = VALUES(quantidade), usuario_id = VALUES(usuario_id)')
                ->execute([$a, $m, $vara, $qtd, $uid]);

            $det = "Registro salvo: Ano=$a, Mês=$m, Vara='$vara', Qtd=$qtd." . ($anterior !== false ? " (anterior: $anterior)" : ' (novo)');
            registrar_log($pdo, 'Salvar Registro (Totais Mensais)', $uid, $det);
            pendencias_invalidar();
            responder_json(['status' => 'success', 'message' => 'Registro salvo.', 'quantidade' => $qtd]);
        } catch (PDOException $e) {
            registrar_log($pdo, 'Falha ao Salvar (Totais Mensais)', $uid, "Erro ao salvar: Ano=$a, Mês=$m, Vara='$vara'. Erro: " . $e->getMessage());
            $ref = erro_interno('Salvar total mensal', $e);
            responder_json(['status' => 'error', 'message' => "Não foi possível salvar (ref. $ref)."], 500);
        }
    }

    if ($acao === 'excluir_vara') {
        $a = filter_input(INPUT_POST, 'ano', FILTER_VALIDATE_INT);
        $vara = trim((string) ($_POST['tipo_vara'] ?? ''));
        if (!$a || $vara === '') {
            responder_json(['status' => 'error', 'message' => 'Dados inválidos.'], 422);
        }
        try {
            $stmt = $pdo->prepare('DELETE FROM relatorio_mensal_processos WHERE ano = ? AND tipo_vara = ?');
            $stmt->execute([$a, $vara]);
            registrar_log($pdo, 'Exclusão em Massa (Totais Mensais)', $uid, "Excluídos todos os registros para a vara: '$vara' do ano: $a.");
            pendencias_invalidar();
            responder_json(['status' => 'success', 'message' => "Registros de \"$vara\" em $a excluídos."]);
        } catch (PDOException $e) {
            registrar_log($pdo, 'Falha Exclusão em Massa (Totais Mensais)', $uid, "Erro ao excluir vara: '$vara', Ano: $a. Erro: " . $e->getMessage());
            $ref = erro_interno('Excluir vara', $e);
            responder_json(['status' => 'error', 'message' => "Não foi possível excluir (ref. $ref)."], 500);
        }
    }

    responder_json(['status' => 'error', 'message' => 'Ação desconhecida.'], 400);
}

/* =========================================================================
 * Dados da página
 * ========================================================================= */
try {
    $stmt = $pdo->prepare('SELECT mes, tipo_vara, quantidade FROM relatorio_mensal_processos WHERE ano = ? ORDER BY tipo_vara');
    $stmt->execute([$ano]);
    $piv = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $piv[$r['tipo_vara']][(int) $r['mes']] = (int) $r['quantidade'];
    }

    // Varas do ano anterior que ainda não aparecem neste ano (sugestões, nada é gravado)
    $stmt = $pdo->prepare('SELECT DISTINCT tipo_vara FROM relatorio_mensal_processos WHERE ano = ? ORDER BY tipo_vara');
    $stmt->execute([$ano - 1]);
    $sugeridas = array_values(array_diff($stmt->fetchAll(PDO::FETCH_COLUMN), array_keys($piv)));

    $todasVaras = $pdo->query('SELECT DISTINCT tipo_vara FROM relatorio_mensal_processos ORDER BY tipo_vara')->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    $ref = erro_interno('Totais mensais', $e);
    pagina_mensagem('Não foi possível carregar os totais', "Erro ao consultar o banco de dados (ref. $ref).", 'fa-circle-exclamation', 'Tentar novamente', 'totais_mensais.php');
}

$mesAtual = ((int) date('Y') === $ano) ? (int) date('n') : ($ano > (int) date('Y') ? 0 : 13);
$totaisMes = array_fill(1, 12, 0);
foreach ($piv as $vara => $ms) {
    foreach ($ms as $m => $q) { $totaisMes[$m] += $q; }
}
$totalGeral = array_sum($totaisMes);
$mesesComDados = [];
foreach ($piv as $ms) { foreach ($ms as $m => $q) { $mesesComDados[$m] = true; } }

$anos = anos_disponiveis($pdo);
$acoes = '<a href="?action=export&ano=' . $ano . '" class="btn btn-secondary"><i class="fa-solid fa-download"></i>Exportar CSV</a>'
    . '<a href="importar_totais_mensais.php" class="btn btn-secondary"><i class="fa-solid fa-file-import"></i>Importar</a>'
    . '<button type="button" class="btn btn-primary" id="btn-nova-vara"><i class="fa-solid fa-plus"></i>Adicionar vara</button>';

layout_inicio('Totais Mensais', ['ativo' => 'totais']);
page_header('Totais mensais', 'Quantidade de novas ações recebidas por tipo de vara. Clique em uma célula para editar — salva automaticamente.', $acoes);
?>

<section class="card">
    <div class="toolbar" style="align-items:center">
        <form method="GET" class="row">
            <label for="ano" class="label">Ano</label>
            <select name="ano" id="ano" class="select select-sm" style="width:auto" data-autosubmit>
                <?php foreach ($anos as $a): ?><option value="<?php echo $a; ?>" <?php echo $a === $ano ? 'selected' : ''; ?>><?php echo $a; ?></option><?php endforeach; ?>
            </select>
        </form>
        <div class="row" style="margin-left:auto;gap:16px">
            <span class="hint"><strong class="num" style="color:var(--text)" id="total-geral"><?php echo numero($totalGeral); ?></strong> ações em <?php echo $ano; ?></span>
            <span class="hint hide-mobile"><i class="fa-regular fa-keyboard"></i> Enter desce · Tab avança · Esc desfaz</span>
        </div>
    </div>

    <?php if ($piv || $sugeridas): ?>
    <div class="table-wrap">
        <table class="table pivot" id="pivot" data-ano="<?php echo $ano; ?>">
            <thead><tr>
                <th>Tipo de vara</th>
                <?php foreach ($mesesCurto as $n => $m): ?>
                    <th class="<?php echo $n > $mesAtual ? 'text-muted' : ''; ?>" title="<?php echo e(meses_nomes()[$n]); ?>">
                        <?php echo $m; ?><?php if ($n < $mesAtual && empty($mesesComDados[$n])): ?> <i class="fa-solid fa-circle-exclamation text-warning" title="Sem lançamentos neste mês" style="font-size:10px"></i><?php endif; ?>
                    </th>
                <?php endforeach; ?>
                <th>Total</th>
                <th><span class="sr-only">Ações</span></th>
            </tr></thead>
            <tbody>
            <?php
            $linhas = [];
            foreach ($piv as $v => $ms) { $linhas[] = [$v, $ms, false]; }
            foreach ($sugeridas as $v) { $linhas[] = [$v, [], true]; }
            foreach ($linhas as $l):
                list($vara, $ms, $sug) = $l;
                $totalLinha = array_sum($ms); ?>
                <tr data-vara="<?php echo e($vara); ?>"<?php echo $sug ? ' class="is-suggested"' : ''; ?>>
                    <td>
                        <span class="cell-strong"><?php echo e($vara); ?></span>
                        <?php if ($sug): ?><span class="cell-sub">Do ano anterior — preencha para incluir</span><?php endif; ?>
                    </td>
                    <?php for ($m = 1; $m <= 12; $m++):
                        $tem = array_key_exists($m, $ms);
                        $q = $tem ? $ms[$m] : ''; ?>
                        <td class="<?php echo $m > $mesAtual ? 'is-future' : ''; ?>">
                            <input class="cell-edit<?php echo ($tem && $q === 0) ? ' is-zero' : ''; ?>" type="text" inputmode="numeric" pattern="[0-9]*"
                                   value="<?php echo $tem ? $q : ''; ?>" data-mes="<?php echo $m; ?>" data-original="<?php echo $tem ? $q : ''; ?>"
                                   placeholder="<?php echo $sug || $m > $mesAtual ? '' : '–'; ?>"
                                   aria-label="<?php echo e($vara . ' — ' . meses_nomes()[$m] . ' de ' . $ano); ?>">
                        </td>
                    <?php endfor; ?>
                    <td class="col-total num" data-total><?php echo $sug ? '–' : numero($totalLinha); ?></td>
                    <td class="actions">
                        <?php if (!$sug): ?>
                        <button type="button" class="btn btn-icon danger" data-excluir title="Excluir todos os registros desta vara em <?php echo $ano; ?>" aria-label="Excluir vara"><i class="fa-regular fa-trash-can"></i></button>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot><tr>
                <td>Total do mês</td>
                <?php for ($m = 1; $m <= 12; $m++): ?><td class="num" data-total-mes="<?php echo $m; ?>"><?php echo numero($totaisMes[$m]); ?></td><?php endfor; ?>
                <td class="num" data-total-geral><?php echo numero($totalGeral); ?></td>
                <td></td>
            </tr></tfoot>
        </table>
    </div>
    <?php else: ?>
        <?php echo estado_vazio('Nenhum total lançado em ' . $ano, 'Adicione uma vara para começar a lançar as quantidades mensais, ou importe a planilha do ano.', 'fa-table-cells',
            '<button type="button" class="btn btn-primary btn-sm" data-act-global="nova-vara">Adicionar vara</button> <a class="btn btn-secondary btn-sm" href="importar_totais_mensais.php">Importar planilha</a>'); ?>
    <?php endif; ?>
</section>

<p class="hint mt-4"><i class="fa-solid fa-circle-info"></i> Nos relatórios, varas com “CÍVEIS” no nome entram como Cíveis, com “TRABALHISTA” como Trabalhistas; as demais aparecem como Outros.</p>

<!-- Nova vara -->
<div class="modal-backdrop" id="modal-vara">
    <form class="modal modal-sm" id="form-vara" data-ajax="1" novalidate>
        <div class="modal-header">
            <div><h2>Adicionar vara</h2><p>Lança a quantidade de um mês de <?php echo $ano; ?>. Os demais meses você preenche direto na tabela.</p></div>
            <button type="button" class="btn btn-icon" data-action="modal-close" aria-label="Fechar"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-body stack">
            <input type="hidden" name="action" value="salvar_manual">
            <input type="hidden" name="ano" value="<?php echo $ano; ?>">
            <div class="field"><label for="tipo_vara">Tipo de vara<span class="req">*</span></label>
                <input class="input" id="tipo_vara" name="tipo_vara" list="lista-varas" maxlength="100" required autocomplete="off">
                <datalist id="lista-varas"><?php foreach ($todasVaras as $v): ?><option value="<?php echo e($v); ?>"><?php endforeach; ?></datalist>
                <span class="hint">Use o mesmo nome dos anos anteriores para manter os relatórios comparáveis.</span>
            </div>
            <div class="grid grid-2">
                <div class="field"><label for="mes">Mês</label>
                    <select class="select" id="mes" name="mes"><?php foreach (meses_nomes() as $n => $m): ?><option value="<?php echo $n; ?>" <?php echo $n === min(12, max(1, $mesAtual - 1)) ? 'selected' : ''; ?>><?php echo $m; ?></option><?php endforeach; ?></select></div>
                <div class="field"><label for="quantidade">Quantidade</label>
                    <input class="input" type="number" id="quantidade" name="quantidade" min="0" step="1" value="0" required></div>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-action="modal-close">Cancelar</button>
            <button type="submit" class="btn btn-primary">Adicionar</button>
        </div>
    </form>
</div>

<?php layout_fim(['scripts' => ['pages/totais.js']]);
