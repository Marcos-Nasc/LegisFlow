<?php
/**
 * Importação dos totais mensais por tipo de vara (Editor e Administrador).
 *
 * Formato (mantido do sistema anterior): coluna A = tipo de vara; colunas B a M = Janeiro a Dezembro.
 * As duas primeiras linhas são cabeçalho. Linhas vazias ou contendo "TOTAL" são ignoradas.
 * Células não numéricas são gravadas como 0. Valores já existentes no ano são substituídos.
 */
ini_set('max_execution_time', '300');
ini_set('memory_limit', '256M');

require_once __DIR__ . '/../includes/bootstrap.php';
exigir_login();
exigir_nivel(NIVEL_EDITOR, 'Importar Totais Mensais');
require_once __DIR__ . '/../includes/importacao.php';

$uid = usuario_id();
$COLS = ['B' => 1, 'C' => 2, 'D' => 3, 'E' => 4, 'F' => 5, 'G' => 6, 'H' => 7, 'I' => 8, 'J' => 9, 'K' => 10, 'L' => 11, 'M' => 12];
$mesesCurto = meses_nomes(true);
$passo = 1;
$erro = '';
$preview = null;
$resultado = null;
$anoRef = (int) date('Y');

if (is_post() && isset($_POST['preview'])) {
    $anoRef = filter_input(INPUT_POST, 'ano_referencia', FILTER_VALIDATE_INT) ?: 0;
    if ($anoRef < 1990 || $anoRef > 2100) {
        $erro = 'Selecione um ano de referência válido.';
    } else {
        list($dados, $erroLeitura) = ler_planilha_upload('import_file');
        if ($erroLeitura) {
            $erro = $erroLeitura;
            registrar_log($pdo, 'Erro na pré-visualização de importação de totais mensais', $uid, 'Arquivo: ' . ($_FILES['import_file']['name'] ?? 'N/A') . ', Erro: ' . $erroLeitura);
        } else {
            $stmt = $pdo->prepare('SELECT DISTINCT tipo_vara FROM relatorio_mensal_processos WHERE ano = ?');
            $stmt->execute([$anoRef]);
            $existentes = array_flip(array_map(function ($v) { return mb_strtolower(trim($v)); }, $stmt->fetchAll(PDO::FETCH_COLUMN)));
            $linhas = [];
            $ignoradas = 0;
            $n = 0;
            foreach ($dados as $numLinha => $row) {
                $n++;
                if ($n <= 2) { continue; }
                $vara = trim((string) ($row['A'] ?? ''));
                if ($vara === '' || stripos($vara, 'TOTAL') !== false) {
                    if ($vara !== '') { $ignoradas++; }
                    continue;
                }
                $valores = [];
                $avisos = 0;
                foreach ($COLS as $col => $mes) {
                    $bruto = $row[$col] ?? null;
                    if (is_numeric($bruto)) { $valores[$mes] = (int) $bruto; }
                    else { $valores[$mes] = 0; if ($bruto !== null && trim((string) $bruto) !== '') { $avisos++; } }
                }
                $linhas[] = ['linha' => $numLinha, 'vara' => mb_substr($vara, 0, 100), 'valores' => $valores, 'existe' => isset($existentes[mb_strtolower($vara)]), 'avisos' => $avisos];
            }
            if (!$linhas) {
                $erro = 'Nenhuma vara encontrada. Confira se os nomes estão na coluna A, a partir da linha 3.';
            } else {
                $preview = ['linhas' => $linhas, 'ano' => $anoRef, 'ignoradas' => $ignoradas, 'arquivo' => $_FILES['import_file']['name']];
                $preview['token'] = import_guardar('totais', $preview);
                $passo = 2;
                registrar_log($pdo, 'Pré-visualização de importação de totais mensais', $uid, "Arquivo: '{$preview['arquivo']}', Ano: $anoRef, Varas: " . count($linhas) . '.');
            }
        }
    }
}

if (is_post() && isset($_POST['import'])) {
    $preview = import_recuperar('totais', $_POST['token'] ?? '');
    if (!$preview) {
        $erro = 'A conferência expirou ou já foi importada. Envie a planilha novamente.';
    } else {
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('INSERT INTO relatorio_mensal_processos (ano, mes, tipo_vara, quantidade, usuario_id) VALUES (:ano, :mes, :tipo_vara, :quantidade, :usuario_id)
                                   ON DUPLICATE KEY UPDATE quantidade = VALUES(quantidade), usuario_id = VALUES(usuario_id)');
            $afetados = 0;
            foreach ($preview['linhas'] as $l) {
                foreach ($l['valores'] as $mes => $q) {
                    $stmt->execute([':ano' => $preview['ano'], ':mes' => $mes, ':tipo_vara' => $l['vara'], ':quantidade' => $q, ':usuario_id' => $uid]);
                    $afetados++;
                }
            }
            $pdo->commit();
            $resultado = ['varas' => count($preview['linhas']), 'registros' => $afetados, 'ano' => $preview['ano']];
            registrar_log($pdo, 'Importação de totais mensais concluída', $uid, "Arquivo: '{$preview['arquivo']}', Ano: {$preview['ano']}, Varas: {$resultado['varas']}, Registros gravados: $afetados.");
            import_limpar();
            pendencias_invalidar();
            $passo = 3;
        } catch (PDOException $e) {
            $pdo->rollBack();
            registrar_log($pdo, 'Erro na importação de totais mensais', $uid, 'Ano: ' . $preview['ano'] . ', Erro: ' . $e->getMessage());
            $ref = erro_interno('Importar totais', $e);
            $erro = "Nada foi gravado: erro ao importar (ref. $ref).";
            $passo = 2;
        }
    }
}

layout_inicio('Importar totais mensais', ['ativo' => 'totais']);
?>
<div class="breadcrumb"><a href="totais_mensais.php">Totais mensais</a><i class="fa-solid fa-chevron-right" style="font-size:9px"></i><span>Importar planilha</span></div>
<?php page_header('Importar totais mensais', 'Lance de uma vez as quantidades de novas ações de um ano inteiro.'); ?>
<?php echo import_passos($passo); ?>

<?php if ($erro !== ''): ?>
    <div class="alert alert-danger mb-4" role="alert"><i class="fa-solid fa-circle-exclamation"></i><div class="alert-body"><?php echo e($erro); ?></div></div>
<?php endif; ?>

<?php if ($passo === 1): ?>
<div class="grid grid-main-side">
    <section class="card">
        <form method="POST" enctype="multipart/form-data" class="card-body stack">
            <?php echo csrf_field(); ?>
            <div class="field" style="max-width:240px"><label for="ano_referencia">Ano de referência</label>
                <select class="select" name="ano_referencia" id="ano_referencia"><?php foreach (anos_disponiveis($pdo) as $a): ?><option value="<?php echo $a; ?>" <?php echo $a === $anoRef ? 'selected' : ''; ?>><?php echo $a; ?></option><?php endforeach; ?></select></div>
            <div>
                <label class="file-drop" for="import_file">
                    <input type="file" name="import_file" id="import_file" accept=".xlsx,.xls,.ods,.csv" required>
                    <i class="fa-regular fa-file-excel"></i>
                    <strong>Clique para escolher a planilha</strong>
                    <span>ou arraste o arquivo para cá · .xlsx, .xls, .ods ou .csv até 10 MB</span>
                </label>
                <div class="file-name"></div>
            </div>
            <div class="row" style="justify-content:flex-end">
                <a href="totais_mensais.php" class="btn btn-ghost">Cancelar</a>
                <button type="submit" name="preview" value="1" class="btn btn-primary" data-loading-text="Lendo planilha…">Conferir dados</button>
            </div>
        </form>
    </section>
    <section class="card">
        <div class="card-header"><h2>Formato esperado</h2></div>
        <div class="card-body text-2 stack" style="gap:8px">
            <p>Varas nas linhas e meses nas colunas:</p>
            <p><strong>Coluna A</strong> — tipo de vara<br><strong>Colunas B a M</strong> — janeiro a dezembro</p>
            <p class="hint">As duas primeiras linhas são cabeçalho. Linhas com “TOTAL” são ignoradas. Células sem número viram 0. Valores já lançados no ano para a mesma vara e mês são substituídos.</p>
        </div>
    </section>
</div>

<?php elseif ($passo === 2 && $preview):
    $novas = count(array_filter($preview['linhas'], function ($l) { return !$l['existe']; }));
    $avisos = array_sum(array_column($preview['linhas'], 'avisos')); ?>
<?php if ($avisos): ?><div class="alert alert-warning mb-4"><i class="fa-solid fa-triangle-exclamation"></i><div class="alert-body"><?php echo numero($avisos); ?> célula(s) com texto em vez de número serão gravadas como 0.</div></div><?php endif; ?>
<section class="card">
    <div class="card-header">
        <div><h2><?php echo e($preview['arquivo']); ?></h2><p>Ano <?php echo (int) $preview['ano']; ?> · <?php echo count($preview['linhas']); ?> varas (<?php echo $novas; ?> novas, <?php echo count($preview['linhas']) - $novas; ?> já existentes serão substituídas)<?php echo $preview['ignoradas'] ? ' · ' . $preview['ignoradas'] . ' linha(s) de total ignoradas' : ''; ?></p></div>
    </div>
    <div class="table-wrap">
        <table class="table table-compact pivot">
            <thead><tr><th>Tipo de vara</th><?php foreach ($mesesCurto as $m): ?><th><?php echo $m; ?></th><?php endforeach; ?><th>Total</th></tr></thead>
            <tbody>
            <?php foreach ($preview['linhas'] as $l): ?>
                <tr><td><span class="cell-strong"><?php echo e($l['vara']); ?></span> <?php echo $l['existe'] ? '<span class="badge badge-info">Substituir</span>' : '<span class="badge badge-success">Nova</span>'; ?></td>
                <?php foreach ($l['valores'] as $q): ?><td class="num"><?php echo numero($q); ?></td><?php endforeach; ?>
                <td class="num col-total"><?php echo numero(array_sum($l['valores'])); ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <form method="POST" class="card-footer row" style="justify-content:flex-end"<?php if (count($preview['linhas']) - $novas > 0): ?> data-confirm="Os valores já lançados em <?php echo (int) $preview['ano']; ?> para as varas existentes serão substituídos pelos da planilha." data-confirm-title="Confirmar importação?" data-confirm-button="Importar agora" data-confirm-tone="warning"<?php endif; ?>>
        <?php echo csrf_field(); ?>
        <input type="hidden" name="token" value="<?php echo e($preview['token']); ?>">
        <input type="hidden" name="import" value="1">
        <a href="importar_totais_mensais.php" class="btn btn-ghost">Enviar outra planilha</a>
        <button type="submit" class="btn btn-primary" data-loading-text="Importando…">Importar <?php echo count($preview['linhas']); ?> varas</button>
    </form>
</section>

<?php elseif ($passo === 3 && $resultado): ?>
<section class="card">
    <div class="empty-state" style="max-width:520px">
        <div class="empty-icon" style="background:var(--success-soft);color:var(--success);border:0"><i class="fa-solid fa-check"></i></div>
        <h3>Importação concluída</h3>
        <p><?php echo numero($resultado['varas']); ?> varas e <?php echo numero($resultado['registros']); ?> lançamentos mensais gravados em <?php echo (int) $resultado['ano']; ?>.</p>
        <div class="empty-action row" style="justify-content:center">
            <a href="totais_mensais.php?ano=<?php echo (int) $resultado['ano']; ?>" class="btn btn-primary">Ver totais de <?php echo (int) $resultado['ano']; ?></a>
            <a href="importar_totais_mensais.php" class="btn btn-secondary">Importar outra planilha</a>
        </div>
    </div>
</section>
<?php endif; ?>

<?php layout_fim();
