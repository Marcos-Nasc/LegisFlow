<?php
/**
 * Importação de processos a partir de planilha (Editor e Administrador).
 *
 * Mapeamento de colunas (mantido do sistema anterior):
 *   A Nº processo · B Autor · C Data de recebimento · D Matéria · F Valor da causa
 *   G Sentença 1ª instância · I Recurso · J Despesas processuais 2 · K Valor pago · L Economia
 * As duas primeiras linhas são cabeçalho. Ano e mês vêm do período de referência escolhido.
 * Processos com número já existente são ATUALIZADOS; os demais são incluídos.
 */
ini_set('max_execution_time', '300');
ini_set('memory_limit', '256M');

require_once __DIR__ . '/../includes/bootstrap.php';
exigir_login();
exigir_nivel(NIVEL_EDITOR, 'Importar Processos Detalhados');
require_once __DIR__ . '/../includes/importacao.php';

$uid = usuario_id();
$meses = meses_nomes();
$MAPA = [
    'A' => ['numero_processo', 'Nº processo'],
    'B' => ['autor', 'Autor'],
    'C' => ['data_recebimento', 'Recebido em'],
    'D' => ['materia', 'Matéria'],
    'F' => ['valor_causa', 'Valor da causa'],
    'G' => ['sentenca_1_instancia', 'Sentença'],
    'I' => ['recurso', 'Recurso'],
    'J' => ['despesas_processuais_2', 'Despesas 2'],
    'K' => ['valor_pago', 'Valor pago'],
    'L' => ['economia', 'Economia'],
];
$MOEDA = ['valor_causa', 'despesas_processuais_2', 'valor_pago', 'economia'];
$passo = 1;
$erro = '';
$preview = null;
$resultado = null;
$anoRef = (int) date('Y');
$mesRef = (int) date('n');

/* ---------- Passo 1 → 2: ler e validar ---------- */
if (is_post() && isset($_POST['preview'])) {
    $anoRef = filter_input(INPUT_POST, 'ano_referencia', FILTER_VALIDATE_INT) ?: 0;
    $mesRef = filter_input(INPUT_POST, 'mes_referencia', FILTER_VALIDATE_INT) ?: 0;
    if ($anoRef < 1990 || $anoRef > 2100 || $mesRef < 1 || $mesRef > 12) {
        $erro = 'Selecione um ano e um mês de referência válidos.';
    } else {
        list($dados, $erroLeitura) = ler_planilha_upload('import_file');
        if ($erroLeitura) {
            $erro = $erroLeitura;
        } elseif (count($dados) < 3) {
            $erro = 'A planilha não tem linhas de dados depois das duas linhas de cabeçalho.';
        } else {
            $existentes = array_flip(array_map(function ($n) { return mb_strtolower(trim($n)); }, $pdo->query('SELECT numero_processo FROM processos_judiciais')->fetchAll(PDO::FETCH_COLUMN)));
            $linhas = [];
            $vistos = [];
            $cont = ['novo' => 0, 'atualizar' => 0, 'ignorar' => 0, 'erro' => 0];
            $n = 0;
            foreach ($dados as $numLinha => $row) {
                $n++;
                if ($n <= 2) { continue; } // cabeçalho (2 linhas, como antes)
                $num = trim((string) ($row['A'] ?? ''));
                $item = ['linha' => $numLinha, 'bruto' => [], 'erros' => [], 'situacao' => ''];
                foreach ($MAPA as $col => $m) { $item['bruto'][$col] = trim((string) ($row[$col] ?? '')); }
                if ($num === '') {
                    if (implode('', $item['bruto']) === '') { continue; } // linha totalmente vazia
                    $item['situacao'] = 'ignorar';
                    $item['erros'][] = 'Sem número do processo — linha ignorada';
                    $cont['ignorar']++;
                    $linhas[] = $item;
                    continue;
                }
                $dataBruta = trim((string) ($row['C'] ?? ''));
                // Prefere o valor bruto da célula (número serial do Excel) para não depender do formato de exibição
                $cBruto = $GLOBALS['lf_planilha_bruta'][$numLinha]['C'] ?? null;
                $data = (is_numeric($cBruto) && (float) $cBruto > 1000) ? data_planilha($cBruto) : data_planilha($row['C'] ?? '');
                if ($dataBruta === '') { $item['erros'][] = 'Data de recebimento vazia (coluna C)'; }
                elseif ($data === null) { $item['erros'][] = 'Data inválida na coluna C: "' . $dataBruta . '"'; }
                if (mb_strlen($num) > 30) { $item['erros'][] = 'Número com mais de 30 caracteres'; }
                if (trim((string) ($row['B'] ?? '')) === '') { $item['erros'][] = 'Autor vazio (coluna B)'; }
                $chave = mb_strtolower($num);
                if (isset($vistos[$chave])) { $item['erros'][] = 'Número repetido na planilha (linha ' . $vistos[$chave] . ')'; }
                $vistos[$chave] = $numLinha;

                $item['registro'] = [
                    'numero_processo' => $num,
                    'autor' => mb_substr(trim((string) ($row['B'] ?? '')), 0, 150),
                    'data_recebimento' => $data,
                    'materia' => trim((string) ($row['D'] ?? '')),
                    'valor_causa' => decimal_br($row['F'] ?? '0'),
                    'sentenca_1_instancia' => trim((string) ($row['G'] ?? '')),
                    'recurso' => trim((string) ($row['I'] ?? '')),
                    'despesas_processuais_2' => decimal_br($row['J'] ?? '0'),
                    'valor_pago' => decimal_br($row['K'] ?? '0'),
                    'economia' => decimal_br($row['L'] ?? '0'),
                ];
                if ($item['erros']) {
                    $item['situacao'] = 'erro';
                    $cont['erro']++;
                } else {
                    $item['situacao'] = isset($existentes[$chave]) ? 'atualizar' : 'novo';
                    $cont[$item['situacao']]++;
                }
                $linhas[] = $item;
            }
            if (!$cont['novo'] && !$cont['atualizar'] && !$cont['erro']) {
                $erro = 'Nenhum processo encontrado na planilha. Confira se os números estão na coluna A, a partir da linha 3.';
            } else {
                $preview = ['linhas' => $linhas, 'cont' => $cont, 'ano' => $anoRef, 'mes' => $mesRef, 'arquivo' => $_FILES['import_file']['name']];
                $preview['token'] = import_guardar('processos', $preview);
                $passo = 2;
                registrar_log($pdo, 'Pré-visualização de Importação (Processos)', $uid, "Arquivo: '{$preview['arquivo']}', Referência: $mesRef/$anoRef. Novos: {$cont['novo']}, atualizações: {$cont['atualizar']}, com erro: {$cont['erro']}.");
            }
        }
    }
}

/* ---------- Passo 2 → 3: gravar (tudo ou nada) ---------- */
if (is_post() && isset($_POST['import'])) {
    $preview = import_recuperar('processos', $_POST['token'] ?? '');
    if (!$preview) {
        $erro = 'A conferência expirou ou já foi importada. Envie a planilha novamente.';
    } elseif ($preview['cont']['erro'] > 0) {
        $erro = 'Corrija as linhas com erro na planilha e envie novamente.';
        $passo = 2;
    } else {
        $sql = 'INSERT INTO processos_judiciais (numero_processo, autor, data_recebimento, ano, mes, materia, valor_causa, sentenca_1_instancia, recurso, despesas_processuais_2, valor_pago, economia, usuario_id)
                VALUES (:numero_processo, :autor, :data_recebimento, :ano, :mes, :materia, :valor_causa, :sentenca_1_instancia, :recurso, :despesas_processuais_2, :valor_pago, :economia, :usuario_id)
                ON DUPLICATE KEY UPDATE autor = VALUES(autor), data_recebimento = VALUES(data_recebimento), ano = VALUES(ano), mes = VALUES(mes), materia = VALUES(materia),
                    valor_causa = VALUES(valor_causa), sentenca_1_instancia = VALUES(sentenca_1_instancia), recurso = VALUES(recurso),
                    despesas_processuais_2 = VALUES(despesas_processuais_2), valor_pago = VALUES(valor_pago), economia = VALUES(economia), usuario_id = VALUES(usuario_id)';
        $linhaAtual = null;
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare($sql);
            foreach ($preview['linhas'] as $l) {
                if (!in_array($l['situacao'], ['novo', 'atualizar'], true)) { continue; }
                $linhaAtual = $l['linha'];
                $r = $l['registro'];
                $stmt->execute([
                    ':numero_processo' => $r['numero_processo'], ':autor' => $r['autor'], ':data_recebimento' => $r['data_recebimento'],
                    ':ano' => $preview['ano'], ':mes' => $preview['mes'], ':materia' => $r['materia'], ':valor_causa' => $r['valor_causa'],
                    ':sentenca_1_instancia' => $r['sentenca_1_instancia'], ':recurso' => $r['recurso'], ':despesas_processuais_2' => $r['despesas_processuais_2'],
                    ':valor_pago' => $r['valor_pago'], ':economia' => $r['economia'], ':usuario_id' => $uid,
                ]);
            }
            $pdo->commit();
            $resultado = $preview['cont'] + ['ano' => $preview['ano'], 'mes' => $preview['mes'], 'arquivo' => $preview['arquivo']];
            registrar_log($pdo, 'Importação de Processos (Sucesso)', $uid, "Arquivo: '{$preview['arquivo']}', Referência: {$preview['mes']}/{$preview['ano']}. Inseridos: {$resultado['novo']}, atualizados: {$resultado['atualizar']}, ignorados: {$resultado['ignorar']}.");
            import_limpar();
            pendencias_invalidar();
            $passo = 3;
        } catch (PDOException $e) {
            $pdo->rollBack();
            registrar_log($pdo, 'Importação de Processos (Falha)', $uid, "Arquivo: '{$preview['arquivo']}'. Erro na linha $linhaAtual: " . $e->getMessage());
            $ref = erro_interno('Importar processos', $e);
            $erro = "Nada foi gravado: erro ao importar a linha $linhaAtual da planilha (ref. $ref). Confira os dados dessa linha e tente novamente.";
            $passo = 2;
        }
    }
}

layout_inicio('Importar processos', ['ativo' => 'processos']);
?>
<div class="breadcrumb"><a href="processos_detalhados.php">Processos</a><i class="fa-solid fa-chevron-right" style="font-size:9px"></i><span>Importar planilha</span></div>
<?php page_header('Importar processos', 'Inclua ou atualize vários processos de uma vez a partir de uma planilha.'); ?>

<?php echo import_passos($passo); ?>

<?php if ($erro !== ''): ?>
    <div class="alert alert-danger mb-4" role="alert"><i class="fa-solid fa-circle-exclamation"></i><div class="alert-body"><?php echo e($erro); ?></div></div>
<?php endif; ?>

<?php if ($passo === 1): ?>
<div class="grid grid-main-side">
    <section class="card">
        <form method="POST" enctype="multipart/form-data" class="card-body stack">
            <?php echo csrf_field(); ?>
            <div class="grid grid-2">
                <div class="field"><label for="mes_referencia">Mês de referência</label>
                    <select class="select" name="mes_referencia" id="mes_referencia"><?php foreach ($meses as $i => $m): ?><option value="<?php echo $i; ?>" <?php echo $i === $mesRef ? 'selected' : ''; ?>><?php echo $m; ?></option><?php endforeach; ?></select></div>
                <div class="field"><label for="ano_referencia">Ano de referência</label>
                    <select class="select" name="ano_referencia" id="ano_referencia"><?php foreach (anos_disponiveis($pdo) as $a): ?><option value="<?php echo $a; ?>" <?php echo $a === $anoRef ? 'selected' : ''; ?>><?php echo $a; ?></option><?php endforeach; ?></select></div>
            </div>
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
                <a href="processos_detalhados.php" class="btn btn-ghost">Cancelar</a>
                <button type="submit" name="preview" value="1" class="btn btn-primary" data-loading-text="Lendo planilha…">Conferir dados</button>
            </div>
        </form>
    </section>
    <section class="card">
        <div class="card-header"><h2>Formato esperado</h2></div>
        <div class="card-body">
            <p class="text-2 mb-4">As duas primeiras linhas são tratadas como cabeçalho. Os dados começam na linha 3.</p>
            <table class="table table-compact">
                <thead><tr><th>Coluna</th><th>Campo</th></tr></thead>
                <tbody>
                    <?php foreach ($MAPA as $col => $m): ?><tr><td><strong><?php echo $col; ?></strong></td><td><?php echo e($m[1]); ?></td></tr><?php endforeach; ?>
                </tbody>
            </table>
            <p class="hint mt-4">As colunas E e H não são importadas. Ano e mês vêm do período de referência escolhido. Processos com número já cadastrado são atualizados; a situação (em andamento, finalizado, arquivado) não é alterada pela importação.</p>
        </div>
    </section>
</div>

<?php elseif ($passo === 2 && $preview): $c = $preview['cont']; ?>
<div class="grid grid-4 grid-kpi-mobile mb-4">
    <section class="card kpi"><div class="kpi-label"><i class="fa-solid fa-plus"></i>Novos</div><div class="kpi-value text-success"><?php echo numero($c['novo']); ?></div></section>
    <section class="card kpi"><div class="kpi-label"><i class="fa-solid fa-rotate"></i>Serão atualizados</div><div class="kpi-value"><?php echo numero($c['atualizar']); ?></div></section>
    <section class="card kpi"><div class="kpi-label"><i class="fa-solid fa-forward"></i>Ignorados</div><div class="kpi-value text-muted"><?php echo numero($c['ignorar']); ?></div></section>
    <section class="card kpi"><div class="kpi-label"><i class="fa-solid fa-triangle-exclamation"></i>Com erro</div><div class="kpi-value <?php echo $c['erro'] ? 'text-danger' : 'text-muted'; ?>"><?php echo numero($c['erro']); ?></div></section>
</div>
<section class="card">
    <div class="card-header">
        <div><h2><?php echo e($preview['arquivo']); ?></h2><p>Referência: <?php echo $meses[$preview['mes']] . '/' . $preview['ano']; ?></p></div>
        <?php if ($c['erro']): ?><span class="badge badge-danger">Corrija <?php echo $c['erro']; ?> linha(s) para importar</span><?php endif; ?>
    </div>
    <div class="table-wrap" style="max-height:60vh;overflow:auto">
        <table class="table table-compact preview-table">
            <thead style="position:sticky;top:0;z-index:2"><tr><th>Linha</th><th>Resultado</th><?php foreach ($MAPA as $col => $m): ?><th><?php echo e($m[1]); ?> <span class="text-muted">(<?php echo $col; ?>)</span></th><?php endforeach; ?></tr></thead>
            <tbody>
            <?php foreach ($preview['linhas'] as $l):
                $cls = $l['situacao'] === 'erro' ? 'row-error' : ($l['situacao'] === 'ignorar' ? 'row-skip' : '');
                $badge = ['novo' => '<span class="badge badge-success">Novo</span>', 'atualizar' => '<span class="badge badge-info">Atualizar</span>', 'ignorar' => '<span class="badge badge-neutral">Ignorar</span>', 'erro' => '<span class="badge badge-danger">Erro</span>'][$l['situacao']]; ?>
                <tr class="<?php echo $cls; ?>">
                    <td class="num"><?php echo (int) $l['linha']; ?></td>
                    <td><?php echo $badge; ?><?php foreach ($l['erros'] as $er): ?><div class="hint text-danger"><?php echo e($er); ?></div><?php endforeach; ?></td>
                    <?php foreach ($MAPA as $col => $m):
                        $v = $l['bruto'][$col];
                        $ehMoeda = in_array($m[0], $MOEDA, true);
                        if (isset($l['registro']) && $ehMoeda) { $v = moeda($l['registro'][$m[0]]); }
                        if (isset($l['registro']) && $m[0] === 'data_recebimento' && $l['registro']['data_recebimento']) { $v = data_br($l['registro']['data_recebimento']); } ?>
                        <td class="<?php echo $ehMoeda ? 'num' : ''; ?>"><span class="truncate" style="display:inline-block;max-width:220px" title="<?php echo e($l['bruto'][$col]); ?>"><?php echo e($v); ?></span></td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <form method="POST" class="card-footer row" style="justify-content:flex-end" data-confirm="<?php echo e('Serão incluídos ' . $c['novo'] . ' e atualizados ' . $c['atualizar'] . ' processos. Os dados atuais dos processos atualizados serão substituídos pelos da planilha.'); ?>" data-confirm-title="Confirmar importação?" data-confirm-button="Importar agora" data-confirm-tone="warning">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="token" value="<?php echo e($preview['token']); ?>">
        <input type="hidden" name="import" value="1">
        <a href="importar_processos_detalhados.php" class="btn btn-ghost">Enviar outra planilha</a>
        <button type="submit" class="btn btn-primary" <?php echo $c['erro'] ? 'disabled' : ''; ?> data-loading-text="Importando…">
            Importar <?php echo numero($c['novo'] + $c['atualizar']); ?> processos</button>
    </form>
</section>

<?php elseif ($passo === 3 && $resultado): ?>
<section class="card">
    <div class="empty-state" style="max-width:520px">
        <div class="empty-icon" style="background:var(--success-soft);color:var(--success);border:0"><i class="fa-solid fa-check"></i></div>
        <h3>Importação concluída</h3>
        <p><?php echo numero($resultado['novo']); ?> processo(s) incluído(s) e <?php echo numero($resultado['atualizar']); ?> atualizado(s) com referência <?php echo $meses[$resultado['mes']] . '/' . $resultado['ano']; ?>.<?php echo $resultado['ignorar'] ? ' ' . numero($resultado['ignorar']) . ' linha(s) sem número foram ignoradas.' : ''; ?></p>
        <div class="empty-action row" style="justify-content:center">
            <a href="processos_detalhados.php?ano=<?php echo (int) $resultado['ano']; ?>&mes=<?php echo (int) $resultado['mes']; ?>" class="btn btn-primary">Ver processos importados</a>
            <a href="importar_processos_detalhados.php" class="btn btn-secondary">Importar outra planilha</a>
        </div>
    </div>
</section>
<?php endif; ?>

<?php layout_fim();
