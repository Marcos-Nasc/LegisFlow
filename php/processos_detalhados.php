<?php
/**
 * Gestão de processos judiciais (Editor e Administrador).
 */
require_once __DIR__ . '/../includes/bootstrap.php';
exigir_login();
exigir_nivel(NIVEL_EDITOR, 'Processos Detalhados');

$uid = usuario_id();

/* =========================================================================
 * Campos editáveis e rótulos (usados no formulário, histórico e exportação)
 * ========================================================================= */
$CAMPOS = [
    'numero_processo'        => 'Nº do processo',
    'autor'                  => 'Autor',
    'data_recebimento'       => 'Data de recebimento',
    'ano'                    => 'Ano de referência',
    'mes'                    => 'Mês de referência',
    'status'                 => 'Situação',
    'materia'                => 'Matéria',
    'central_custo'          => 'Central de custo',
    'valor_causa'            => 'Valor da causa',
    'sentenca_1_instancia'   => 'Sentença 1ª instância',
    'recurso'                => 'Recurso',
    'despesas_processuais_1' => 'Despesas processuais 1',
    'despesas_processuais_2' => 'Despesas processuais 2',
    'valor_pago'             => 'Valor pago',
    'economia'               => 'Economia',
];
$CAMPOS_MOEDA = ['valor_causa', 'despesas_processuais_1', 'despesas_processuais_2', 'valor_pago', 'economia'];

/* =========================================================================
 * Filtros (compartilhados por listagem e exportações)
 * ========================================================================= */
$f = [
    'busca'     => trim((string) ($_GET['busca'] ?? '')),
    'ano'       => get_int('ano', null, 1990, 2100),
    'mes'       => get_int('mes', null, 1, 12),
    'status'    => array_key_exists($_GET['status'] ?? '', status_processo_opcoes()) ? $_GET['status'] : '',
    'central'   => trim((string) ($_GET['central'] ?? '')),
    'pendencia' => ($_GET['pendencia'] ?? '') === 'incompletos' ? 'incompletos' : '',
];

function montar_where($f)
{
    $w = [];
    $p = [];
    if ($f['ano']) { $w[] = 'ano = :ano'; $p[':ano'] = $f['ano']; }
    if ($f['mes']) { $w[] = 'mes = :mes'; $p[':mes'] = $f['mes']; }
    if ($f['status'] !== '') { $w[] = 'status = :status'; $p[':status'] = $f['status']; }
    if ($f['central'] !== '') {
        if ($f['central'] === '__vazio__') { $w[] = "(central_custo IS NULL OR central_custo = '')"; }
        else { $w[] = 'central_custo = :central'; $p[':central'] = $f['central']; }
    }
    if ($f['pendencia'] === 'incompletos') { $w[] = sql_processo_incompleto(); }
    if ($f['busca'] !== '') {
        $w[] = '(numero_processo LIKE :busca OR autor LIKE :busca OR materia LIKE :busca OR central_custo LIKE :busca)';
        $p[':busca'] = '%' . $f['busca'] . '%';
    }
    return [$w ? ' WHERE ' . implode(' AND ', $w) : '', $p];
}
list($whereSql, $whereParams) = montar_where($f);

function descrever_filtros($f)
{
    $d = [];
    foreach (['ano' => 'Ano', 'mes' => 'Mês', 'status' => 'Situação', 'central' => 'Central', 'busca' => 'Busca', 'pendencia' => 'Pendência'] as $k => $r) {
        if (!empty($f[$k])) { $d[] = "$r: " . $f[$k]; }
    }
    return $d ? implode(', ', $d) : 'sem filtros';
}

/* =========================================================================
 * Exportação CSV (formato original: todas as colunas da tabela)
 * ========================================================================= */
$action = $_GET['action'] ?? '';
if ($action === 'export') {
    registrar_log($pdo, 'Exportação CSV (Processos)', $uid, 'Exportou CSV de Processos Detalhados. Filtros: ' . descrever_filtros($f) . '.');
    try {
        $stmt = $pdo->prepare('SELECT * FROM processos_judiciais' . $whereSql . ' ORDER BY data_recebimento DESC, id DESC');
        $stmt->execute($whereParams);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=relatorio_processos_detalhados.csv');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF"); // BOM: acentos corretos no Excel
        $primeira = true;
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if ($primeira) { fputcsv($out, array_keys($row)); $primeira = false; }
            fputcsv($out, $row);
        }
        fclose($out);
        exit();
    } catch (PDOException $e) {
        $ref = erro_interno('Exportar CSV processos', $e);
        pagina_mensagem('Falha na exportação', "Não foi possível gerar o arquivo (ref. $ref).", 'fa-circle-exclamation', 'Voltar', 'processos_detalhados.php');
    }
}

/* =========================================================================
 * Exportação Excel (.xlsx) com cabeçalhos legíveis e formatação
 * ========================================================================= */
if ($action === 'export_xlsx') {
    registrar_log($pdo, 'Exportação Excel (Processos)', $uid, 'Exportou Excel de Processos Detalhados. Filtros: ' . descrever_filtros($f) . '.');
    require_once LF_ROOT . '/vendor/autoload.php';
    try {
        $stmt = $pdo->prepare('SELECT numero_processo, autor, data_recebimento, ano, mes, status, materia, central_custo, valor_causa, despesas_processuais_1, despesas_processuais_2, valor_pago, economia, sentenca_1_instancia, recurso FROM processos_judiciais' . $whereSql . ' ORDER BY data_recebimento DESC, id DESC');
        $stmt->execute($whereParams);
        $linhas = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $ss = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sh = $ss->getActiveSheet();
        $sh->setTitle('Processos');
        $cab = ['Nº do processo', 'Autor', 'Recebido em', 'Ano', 'Mês', 'Situação', 'Matéria', 'Central de custo', 'Valor da causa', 'Despesas 1', 'Despesas 2', 'Valor pago', 'Economia', 'Sentença 1ª instância', 'Recurso'];
        $sh->fromArray($cab, null, 'A1');
        $i = 2;
        $st = status_processo_opcoes();
        foreach ($linhas as $l) {
            $sh->setCellValueExplicit('A' . $i, $l['numero_processo'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $sh->setCellValue('B' . $i, $l['autor']);
            if ($l['data_recebimento']) {
                $sh->setCellValue('C' . $i, \PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel(new DateTime($l['data_recebimento'])));
            }
            $sh->setCellValue('D' . $i, $l['ano']);
            $sh->setCellValue('E' . $i, $l['mes']);
            $sh->setCellValue('F' . $i, $st[$l['status']] ?? $l['status']);
            $sh->setCellValue('G' . $i, $l['materia']);
            $sh->setCellValue('H' . $i, $l['central_custo']);
            $col = 'I';
            foreach (['valor_causa', 'despesas_processuais_1', 'despesas_processuais_2', 'valor_pago', 'economia'] as $c) {
                $sh->setCellValue($col . $i, (float) $l[$c]);
                $col++;
            }
            $sh->setCellValue('N' . $i, $l['sentenca_1_instancia']);
            $sh->setCellValue('O' . $i, $l['recurso']);
            $i++;
        }
        $ult = max(2, $i - 1);
        $sh->getStyle('A1:O1')->getFont()->setBold(true);
        $sh->getStyle('A1:O1')->getFill()->setFillType('solid')->getStartColor()->setRGB('E3E7ED');
        $sh->getStyle("C2:C$ult")->getNumberFormat()->setFormatCode('dd/mm/yyyy');
        $sh->getStyle("I2:M$ult")->getNumberFormat()->setFormatCode('"R$" #,##0.00');
        foreach (range('A', 'M') as $c) { $sh->getColumnDimension($c)->setAutoSize(true); }
        $sh->getColumnDimension('N')->setWidth(40);
        $sh->getColumnDimension('O')->setWidth(40);
        $sh->freezePane('A2');
        $sh->setAutoFilter("A1:O$ult");

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="processos_' . date('Y-m-d') . '.xlsx"');
        header('Cache-Control: max-age=0');
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($ss))->save('php://output');
        exit();
    } catch (Throwable $e) {
        $ref = erro_interno('Exportar XLSX processos', $e);
        pagina_mensagem('Falha na exportação', "Não foi possível gerar a planilha (ref. $ref).", 'fa-circle-exclamation', 'Voltar', 'processos_detalhados.php');
    }
}

/* =========================================================================
 * AJAX: dados de um processo + histórico de alterações
 * ========================================================================= */
if ($action === 'get_processo') {
    $id = get_int('id', 0, 1);
    $stmt = $pdo->prepare('SELECT p.*, u.nome AS cadastrado_por FROM processos_judiciais p LEFT JOIN usuarios u ON u.id = p.usuario_id WHERE p.id = ?');
    $stmt->execute([$id]);
    $proc = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$proc) {
        responder_json(['status' => 'error', 'message' => 'Processo não encontrado. Ele pode ter sido excluído.'], 404);
    }
    // Histórico: registros do log que citam este processo
    $stmt = $pdo->prepare(
        "SELECT l.acao, l.detalhes, l.data_ocorrencia, u.nome AS usuario
           FROM logs l LEFT JOIN usuarios u ON u.id = l.id_usuario
          WHERE l.acao LIKE '%Processo%' AND (l.detalhes LIKE :pid OR l.detalhes LIKE :num)
          ORDER BY l.data_ocorrencia DESC, l.id DESC LIMIT 30"
    );
    $stmt->execute([':pid' => '%(ID: ' . $id . ')%', ':num' => "%'" . $proc['numero_processo'] . "'%"]);
    $hist = $stmt->fetchAll(PDO::FETCH_ASSOC);
    responder_json(['status' => 'success', 'processo' => $proc, 'historico' => $hist]);
}

/* =========================================================================
 * AJAX POST: salvar / excluir
 * ========================================================================= */
if (is_post()) {
    $acao = $_POST['action'] ?? '';

    if ($acao === 'salvar_processo') {
        $id = filter_input(INPUT_POST, 'processo_id', FILTER_VALIDATE_INT) ?: null;
        $d = [];
        foreach (['numero_processo', 'autor', 'data_recebimento', 'materia', 'central_custo', 'sentenca_1_instancia', 'recurso', 'status'] as $c) {
            $d[$c] = trim((string) ($_POST[$c] ?? ''));
        }
        $d['ano'] = filter_input(INPUT_POST, 'ano', FILTER_VALIDATE_INT);
        $d['mes'] = filter_input(INPUT_POST, 'mes', FILTER_VALIDATE_INT);
        foreach ($CAMPOS_MOEDA as $c) {
            $d[$c] = decimal_br($_POST[$c] ?? '');
        }

        // Validação
        $erros = [];
        if ($d['numero_processo'] === '') { $erros['numero_processo'] = 'Informe o número do processo.'; }
        elseif (mb_strlen($d['numero_processo']) > 30) { $erros['numero_processo'] = 'Máximo de 30 caracteres.'; }
        if ($d['autor'] === '') { $erros['autor'] = 'Informe o autor.'; }
        elseif (mb_strlen($d['autor']) > 150) { $erros['autor'] = 'Máximo de 150 caracteres.'; }
        $dt = DateTime::createFromFormat('Y-m-d', $d['data_recebimento']);
        if (!$dt || $dt->format('Y-m-d') !== $d['data_recebimento']) { $erros['data_recebimento'] = 'Informe uma data válida.'; }
        if (!$d['ano'] || $d['ano'] < 1990 || $d['ano'] > 2100) { $erros['ano'] = 'Ano inválido.'; }
        if (!$d['mes'] || $d['mes'] < 1 || $d['mes'] > 12) { $erros['mes'] = 'Mês inválido.'; }
        if (!array_key_exists($d['status'], status_processo_opcoes())) { $d['status'] = 'em andamento'; }
        if (mb_strlen($d['central_custo']) > 100) { $erros['central_custo'] = 'Máximo de 100 caracteres.'; }
        foreach ($CAMPOS_MOEDA as $c) {
            if (abs($d[$c]) >= 1e13) { $erros[$c] = 'Valor muito alto.'; }
        }
        if ($erros) {
            responder_json(['status' => 'error', 'message' => 'Revise os campos destacados.', 'errors' => $erros], 422);
        }
        foreach (['materia', 'central_custo', 'sentenca_1_instancia', 'recurso'] as $c) {
            if ($d[$c] === '') { $d[$c] = null; }
        }

        try {
            if ($id) {
                $stmt = $pdo->prepare('SELECT * FROM processos_judiciais WHERE id = ?');
                $stmt->execute([$id]);
                $antes = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$antes) {
                    responder_json(['status' => 'error', 'message' => 'Processo não encontrado. Ele pode ter sido excluído por outro usuário.'], 404);
                }
                $sql = 'UPDATE processos_judiciais SET numero_processo=?, autor=?, data_recebimento=?, ano=?, mes=?, materia=?, central_custo=?, valor_causa=?, sentenca_1_instancia=?, recurso=?, despesas_processuais_1=?, despesas_processuais_2=?, valor_pago=?, economia=?, status=? WHERE id=?';
                $pdo->prepare($sql)->execute([$d['numero_processo'], $d['autor'], $d['data_recebimento'], $d['ano'], $d['mes'], $d['materia'], $d['central_custo'], $d['valor_causa'], $d['sentenca_1_instancia'], $d['recurso'], $d['despesas_processuais_1'], $d['despesas_processuais_2'], $d['valor_pago'], $d['economia'], $d['status'], $id]);

                // Registro detalhado do que mudou (antes → depois)
                $mudancas = [];
                foreach ($CAMPOS as $c => $rot) {
                    $va = $antes[$c];
                    $vd = $d[$c];
                    $igual = in_array($c, $CAMPOS_MOEDA, true) ? abs((float) $va - (float) $vd) < 0.005 : (string) ($va ?? '') === (string) ($vd ?? '');
                    if (!$igual) {
                        $fa = in_array($c, $CAMPOS_MOEDA, true) ? moeda($va) : mb_strimwidth((string) ($va ?? ''), 0, 80, '…');
                        $fd = in_array($c, $CAMPOS_MOEDA, true) ? moeda($vd) : mb_strimwidth((string) ($vd ?? ''), 0, 80, '…');
                        $mudancas[] = "$rot: \"" . ($fa === '' ? '(vazio)' : $fa) . '" → "' . ($fd === '' ? '(vazio)' : $fd) . '"';
                    }
                }
                $det = "Atualizou o processo: '{$d['numero_processo']}' (ID: $id).";
                if ($mudancas) { $det .= ' Alterações: ' . implode('; ', $mudancas) . '.'; }
                else { $det .= ' Nenhum campo alterado.'; }
                registrar_log($pdo, 'Edição de Processo (Sucesso)', $uid, $det);
                $msg = 'Processo atualizado.';
                $novoId = $id;
            } else {
                $sql = 'INSERT INTO processos_judiciais (numero_processo, autor, data_recebimento, ano, mes, materia, central_custo, valor_causa, sentenca_1_instancia, recurso, despesas_processuais_1, despesas_processuais_2, valor_pago, economia, status, usuario_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)';
                $pdo->prepare($sql)->execute([$d['numero_processo'], $d['autor'], $d['data_recebimento'], $d['ano'], $d['mes'], $d['materia'], $d['central_custo'], $d['valor_causa'], $d['sentenca_1_instancia'], $d['recurso'], $d['despesas_processuais_1'], $d['despesas_processuais_2'], $d['valor_pago'], $d['economia'], $d['status'], $uid]);
                $novoId = (int) $pdo->lastInsertId();
                registrar_log($pdo, 'Criação de Processo (Sucesso)', $uid, "Criou o processo: '{$d['numero_processo']}' (ID: $novoId).");
                $msg = 'Processo cadastrado.';
            }
            pendencias_invalidar();
            responder_json(['status' => 'success', 'message' => $msg, 'id' => $novoId]);
        } catch (PDOException $e) {
            $tipo = $id ? 'Edição' : 'Criação';
            registrar_log($pdo, "Falha $tipo Processo", $uid, "Falha ao [$tipo] o processo: '{$d['numero_processo']}' (ID: " . ($id ?: '(novo)') . '). Erro: ' . $e->getMessage());
            if (erro_duplicidade($e)) {
                responder_json(['status' => 'error', 'message' => 'Já existe um processo com este número.', 'errors' => ['numero_processo' => 'Este número já está cadastrado.']], 422);
            }
            $ref = erro_interno('Salvar processo', $e);
            responder_json(['status' => 'error', 'message' => "Não foi possível salvar (ref. $ref)."], 500);
        }
    }

    if ($acao === 'excluir_processo') {
        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        if (!$id) {
            responder_json(['status' => 'error', 'message' => 'Processo inválido.'], 422);
        }
        try {
            $stmt = $pdo->prepare('SELECT numero_processo, autor FROM processos_judiciais WHERE id = ?');
            $stmt->execute([$id]);
            $info = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$info) {
                responder_json(['status' => 'error', 'message' => 'Processo não encontrado. Ele pode já ter sido excluído.'], 404);
            }
            $pdo->prepare('DELETE FROM processos_judiciais WHERE id = ?')->execute([$id]);
            registrar_log($pdo, 'Exclusão de Processo (Sucesso)', $uid, "Excluiu o processo: {$info['numero_processo']} (Autor: {$info['autor']}, ID: $id).");
            pendencias_invalidar();
            responder_json(['status' => 'success', 'message' => 'Processo excluído.']);
        } catch (PDOException $e) {
            registrar_log($pdo, 'Exclusão de Processo (Falha)', $uid, "Falha ao excluir o processo ID: $id. Erro: " . $e->getMessage());
            $ref = erro_interno('Excluir processo', $e);
            responder_json(['status' => 'error', 'message' => "Não foi possível excluir (ref. $ref)."], 500);
        }
    }

    responder_json(['status' => 'error', 'message' => 'Ação desconhecida.'], 400);
}

/* =========================================================================
 * Listagem
 * ========================================================================= */
$ordenaveis = [
    'data_recebimento' => 'data_recebimento',
    'numero_processo'  => 'numero_processo',
    'autor'            => 'autor',
    'valor_causa'      => 'valor_causa',
    'status'           => 'status',
    'central_custo'    => 'central_custo',
];
$ordem = array_key_exists($_GET['ordem'] ?? '', $ordenaveis) ? $_GET['ordem'] : 'data_recebimento';
$dir = (($_GET['dir'] ?? '') === 'asc') ? 'ASC' : 'DESC';
if (!isset($_GET['dir']) && in_array($ordem, ['numero_processo', 'autor', 'status', 'central_custo'], true)) {
    $dir = 'ASC';
}
$porPagina = in_array(get_int('por_pagina', 25), [15, 25, 50, 100], true) ? get_int('por_pagina', 25) : 25;
$pagina = get_int('page', 1, 1);

try {
    $stmt = $pdo->prepare('SELECT COUNT(*) AS qtd, COALESCE(SUM(valor_causa),0) AS soma FROM processos_judiciais' . $whereSql);
    $stmt->execute($whereParams);
    $resumo = $stmt->fetch(PDO::FETCH_ASSOC);
    $total = (int) $resumo['qtd'];
    $totalPaginas = max(1, (int) ceil($total / $porPagina));
    if ($pagina > $totalPaginas) { $pagina = $totalPaginas; }

    $sql = 'SELECT id, numero_processo, autor, data_recebimento, ano, mes, materia, central_custo, valor_causa, status FROM processos_judiciais'
        . $whereSql . " ORDER BY {$ordenaveis[$ordem]} $dir, id DESC LIMIT :limit OFFSET :offset";
    $stmt = $pdo->prepare($sql);
    foreach ($whereParams as $k => $v) { $stmt->bindValue($k, $v); }
    $stmt->bindValue(':limit', $porPagina, PDO::PARAM_INT);
    $stmt->bindValue(':offset', ($pagina - 1) * $porPagina, PDO::PARAM_INT);
    $stmt->execute();
    $registros = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $centrais = $pdo->query("SELECT DISTINCT central_custo FROM processos_judiciais WHERE central_custo IS NOT NULL AND central_custo <> '' ORDER BY central_custo")->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    $ref = erro_interno('Listar processos', $e);
    pagina_mensagem('Não foi possível carregar os processos', "Erro ao consultar o banco de dados (ref. $ref).", 'fa-circle-exclamation', 'Tentar novamente', 'processos_detalhados.php');
}

$anos = anos_disponiveis($pdo);
$meses = meses_nomes();
$filtrosAtivos = array_filter($f, function ($v) { return $v !== '' && $v !== null; });

function link_ordem($col, $rotulo, $ordemAtual, $dirAtual)
{
    $ativo = $ordemAtual === $col;
    $novoDir = $ativo && $dirAtual === 'ASC' ? 'desc' : ($ativo ? 'asc' : (in_array($col, ['valor_causa', 'data_recebimento'], true) ? 'desc' : 'asc'));
    $icone = !$ativo ? 'fa-sort' : ($dirAtual === 'ASC' ? 'fa-sort-up' : 'fa-sort-down');
    return '<a class="sort-link' . ($ativo ? ' is-sorted' : '') . '" href="' . e(url_com(['ordem' => $col, 'dir' => $novoDir, 'page' => null])) . '">' . e($rotulo) . '<i class="fa-solid ' . $icone . '"></i></a>';
}

$qsExport = http_build_query(array_filter([
    'busca' => $f['busca'], 'ano' => $f['ano'], 'mes' => $f['mes'], 'status' => $f['status'], 'central' => $f['central'], 'pendencia' => $f['pendencia'],
]));

$acoes = '<div class="dropdown"><button type="button" class="btn btn-secondary" data-action="dropdown" aria-haspopup="true"><i class="fa-solid fa-download"></i>Exportar<i class="fa-solid fa-chevron-down" style="font-size:10px"></i></button>'
    . '<div class="dropdown-menu dropdown-menu-right">'
    . '<a class="dropdown-item" href="?action=export_xlsx&' . e($qsExport) . '"><i class="fa-regular fa-file-excel"></i>Planilha Excel (.xlsx)</a>'
    . '<a class="dropdown-item" href="?action=export&' . e($qsExport) . '"><i class="fa-solid fa-file-csv"></i>CSV (formato original)</a>'
    . '<div class="dropdown-divider"></div><div class="dropdown-header" style="border:0;margin:0;padding:4px 10px"><span>Exporta os ' . numero($total) . ' processos do filtro atual</span></div>'
    . '</div></div>'
    . '<a href="importar_processos_detalhados.php" class="btn btn-secondary"><i class="fa-solid fa-file-import"></i>Importar</a>'
    . '<button type="button" class="btn btn-primary" id="btn-novo"><i class="fa-solid fa-plus"></i>Novo processo</button>';

layout_inicio('Processos', ['ativo' => 'processos']);
page_header('Processos', 'Cadastro e acompanhamento dos processos judiciais.', $acoes);
?>

<section class="card">
    <form method="GET" class="toolbar" id="filtros">
        <div class="field grow">
            <label for="busca">Buscar</label>
            <div class="input-group"><i class="fa-solid fa-magnifying-glass prefix"></i>
                <input type="search" name="busca" id="busca" class="input input-sm" value="<?php echo e($f['busca']); ?>" placeholder="Número, autor, matéria ou central">
            </div>
        </div>
        <div class="field">
            <label for="f-ano">Ano</label>
            <select name="ano" id="f-ano" class="select select-sm" data-autosubmit>
                <option value="">Todos</option>
                <?php foreach ($anos as $a): ?><option value="<?php echo $a; ?>" <?php echo $f['ano'] === $a ? 'selected' : ''; ?>><?php echo $a; ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="f-mes">Mês</label>
            <select name="mes" id="f-mes" class="select select-sm" data-autosubmit>
                <option value="">Todos</option>
                <?php foreach ($meses as $n => $m): ?><option value="<?php echo $n; ?>" <?php echo $f['mes'] === $n ? 'selected' : ''; ?>><?php echo $m; ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="f-status">Situação</label>
            <select name="status" id="f-status" class="select select-sm" data-autosubmit>
                <option value="">Todas</option>
                <?php foreach (status_processo_opcoes() as $k => $r): ?><option value="<?php echo e($k); ?>" <?php echo $f['status'] === $k ? 'selected' : ''; ?>><?php echo e($r); ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="f-central">Central de custo</label>
            <select name="central" id="f-central" class="select select-sm" data-autosubmit>
                <option value="">Todas</option>
                <?php foreach ($centrais as $c): ?><option value="<?php echo e($c); ?>" <?php echo $f['central'] === $c ? 'selected' : ''; ?>><?php echo e($c); ?></option><?php endforeach; ?>
                <option value="__vazio__" <?php echo $f['central'] === '__vazio__' ? 'selected' : ''; ?>>(Sem central)</option>
            </select>
        </div>
        <?php if ($f['pendencia']): ?><input type="hidden" name="pendencia" value="<?php echo e($f['pendencia']); ?>"><?php endif; ?>
        <input type="hidden" name="ordem" value="<?php echo e($ordem); ?>">
        <input type="hidden" name="dir" value="<?php echo strtolower($dir); ?>">
        <input type="hidden" name="por_pagina" value="<?php echo $porPagina; ?>">
        <div class="toolbar-actions">
            <button type="submit" class="btn btn-secondary btn-sm" data-no-loading="1">Filtrar</button>
            <?php if ($filtrosAtivos): ?><a href="processos_detalhados.php" class="btn btn-ghost btn-sm">Limpar</a><?php endif; ?>
        </div>
    </form>

    <?php if ($f['pendencia'] === 'incompletos'): ?>
        <div style="padding:12px 16px 0"><div class="alert alert-info"><i class="fa-solid fa-file-circle-exclamation"></i><div class="alert-body">Mostrando processos <strong>em andamento</strong> sem valor da causa, matéria ou central de custo. <a href="<?php echo e(url_com(['pendencia' => null, 'page' => null])); ?>">Remover este filtro</a></div></div></div>
    <?php endif; ?>

    <div class="result-meta">
        <span><strong class="num" style="color:var(--text)"><?php echo numero($total); ?></strong> <?php echo $total === 1 ? 'processo' : 'processos'; ?>
            · valor total das causas <strong class="num" style="color:var(--text)"><?php echo moeda($resumo['soma']); ?></strong></span>
        <form method="GET" class="row hide-mobile">
            <?php foreach ($_GET as $k => $v): if ($k === 'por_pagina' || $k === 'page' || is_array($v)) continue; ?><input type="hidden" name="<?php echo e($k); ?>" value="<?php echo e($v); ?>"><?php endforeach; ?>
            <label for="pp" class="hint">Por página</label>
            <select name="por_pagina" id="pp" class="select select-sm" style="width:auto" data-autosubmit>
                <?php foreach ([15, 25, 50, 100] as $n): ?><option <?php echo $porPagina === $n ? 'selected' : ''; ?>><?php echo $n; ?></option><?php endforeach; ?>
            </select>
        </form>
    </div>

    <?php if ($registros): ?>
    <div class="table-wrap">
        <table class="table table-cards" id="tabela-processos">
            <thead><tr>
                <th><?php echo link_ordem('numero_processo', 'Nº do processo', $ordem, $dir); ?></th>
                <th><?php echo link_ordem('autor', 'Autor', $ordem, $dir); ?></th>
                <th><?php echo link_ordem('data_recebimento', 'Recebido em', $ordem, $dir); ?></th>
                <th class="hide-mobile"><?php echo link_ordem('central_custo', 'Central de custo', $ordem, $dir); ?></th>
                <th><?php echo link_ordem('status', 'Situação', $ordem, $dir); ?></th>
                <th class="num"><?php echo link_ordem('valor_causa', 'Valor da causa', $ordem, $dir); ?></th>
                <th><span class="sr-only">Ações</span></th>
            </tr></thead>
            <tbody>
            <?php foreach ($registros as $r): ?>
                <tr class="is-clickable" data-id="<?php echo (int) $r['id']; ?>">
                    <td class="card-title"><span class="cell-strong nowrap"><?php echo e($r['numero_processo']); ?></span>
                        <?php if ($r['materia']): ?><span class="cell-sub truncate" title="<?php echo e($r['materia']); ?>"><?php echo e($r['materia']); ?></span><?php endif; ?></td>
                    <td data-label="Autor"><span class="truncate" style="display:inline-block;max-width:240px;vertical-align:bottom" title="<?php echo e($r['autor']); ?>"><?php echo e($r['autor']); ?></span></td>
                    <td data-label="Recebido em" class="nowrap"><?php echo data_br($r['data_recebimento']); ?></td>
                    <td data-label="Central" class="hide-mobile"><?php echo $r['central_custo'] ? e($r['central_custo']) : '<span class="text-muted">—</span>'; ?></td>
                    <td data-label="Situação"><?php echo badge_status($r['status']); ?></td>
                    <td data-label="Valor da causa" class="num<?php echo (float) $r['valor_causa'] == 0 ? ' text-muted' : ''; ?>"><?php echo moeda($r['valor_causa']); ?></td>
                    <td class="actions">
                        <button type="button" class="btn btn-icon" data-act="ver" title="Ver detalhes" aria-label="Ver detalhes"><i class="fa-regular fa-eye"></i></button>
                        <button type="button" class="btn btn-icon" data-act="editar" title="Editar" aria-label="Editar"><i class="fa-regular fa-pen-to-square"></i></button>
                        <button type="button" class="btn btn-icon danger" data-act="excluir" title="Excluir" aria-label="Excluir"><i class="fa-regular fa-trash-can"></i></button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php echo paginacao($pagina, $totalPaginas); ?>
    <?php elseif ($filtrosAtivos): ?>
        <?php echo estado_vazio('Nenhum processo encontrado', 'Nenhum processo corresponde aos filtros aplicados.', 'fa-magnifying-glass', '<a href="processos_detalhados.php" class="btn btn-secondary btn-sm">Limpar filtros</a>'); ?>
    <?php else: ?>
        <?php echo estado_vazio('Nenhum processo cadastrado', 'Cadastre o primeiro processo ou importe uma planilha.', 'fa-gavel', '<button type="button" class="btn btn-primary btn-sm" data-act-global="novo">Novo processo</button>'); ?>
    <?php endif; ?>
</section>

<!-- Detalhes (drawer) -->
<div class="drawer-backdrop" data-drawer-backdrop="drawer-processo"></div>
<aside class="drawer" id="drawer-processo" aria-labelledby="drawer-titulo" role="dialog" aria-modal="true">
    <div class="drawer-header">
        <div><div class="hint">Processo</div><h2 id="drawer-titulo">—</h2><div id="drawer-status" class="mt-1"></div></div>
        <button type="button" class="btn btn-icon drawer-close" data-action="drawer-close" aria-label="Fechar"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="drawer-body" id="drawer-body"><div class="skeleton" style="width:60%"></div></div>
    <div class="drawer-footer">
        <button type="button" class="btn btn-danger-ghost" id="drawer-excluir" style="margin-right:auto"><i class="fa-regular fa-trash-can"></i>Excluir</button>
        <button type="button" class="btn btn-primary" id="drawer-editar"><i class="fa-regular fa-pen-to-square"></i>Editar</button>
    </div>
</aside>

<!-- Formulário (modal) -->
<div class="modal-backdrop" id="modal-processo" data-dismissable="false">
    <form class="modal modal-lg" id="form-processo" data-ajax="1" novalidate role="dialog" aria-modal="true" aria-labelledby="modal-titulo">
        <div class="modal-header">
            <div><h2 id="modal-titulo">Novo processo</h2><p>Campos com <span class="text-danger">*</span> são obrigatórios.</p></div>
            <button type="button" class="btn btn-icon modal-close" data-action="modal-close" aria-label="Fechar"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-body">
            <input type="hidden" name="action" value="salvar_processo">
            <input type="hidden" name="processo_id" id="processo_id">
            <div class="form-grid">
                <div class="form-section">Identificação</div>
                <div class="field"><label for="numero_processo">Nº do processo<span class="req">*</span></label>
                    <input class="input" id="numero_processo" name="numero_processo" maxlength="30" required autocomplete="off"><span class="field-error"></span></div>
                <div class="field"><label for="status">Situação</label>
                    <select class="select" id="status" name="status"><?php foreach (status_processo_opcoes() as $k => $r): ?><option value="<?php echo e($k); ?>"><?php echo e($r); ?></option><?php endforeach; ?></select></div>
                <div class="field full"><label for="autor">Autor<span class="req">*</span></label>
                    <input class="input" id="autor" name="autor" maxlength="150" required><span class="field-error"></span></div>
                <div class="field full"><label for="materia">Matéria</label>
                    <input class="input" id="materia" name="materia"><span class="field-error"></span></div>
                <div class="field full"><label for="central_custo">Central de custo</label>
                    <input class="input" id="central_custo" name="central_custo" maxlength="100" list="lista-centrais"><span class="field-error"></span>
                    <datalist id="lista-centrais"><?php foreach ($centrais as $c): ?><option value="<?php echo e($c); ?>"><?php endforeach; ?></datalist></div>

                <div class="form-section">Datas e período de referência</div>
                <div class="field full"><label for="data_recebimento">Data de recebimento<span class="req">*</span></label>
                    <input class="input" type="date" id="data_recebimento" name="data_recebimento" required style="max-width:240px"><span class="field-error"></span></div>
                <div class="field"><label for="mes">Mês de referência<span class="req">*</span></label>
                    <select class="select" id="mes" name="mes" required><?php foreach ($meses as $n => $m): ?><option value="<?php echo $n; ?>"><?php echo $m; ?></option><?php endforeach; ?></select><span class="field-error"></span></div>
                <div class="field"><label for="ano">Ano de referência<span class="req">*</span></label>
                    <input class="input" type="number" id="ano" name="ano" min="1990" max="2100" required><span class="field-error"></span>
                    <span class="hint">Preenchidos a partir da data de recebimento; ajuste se a referência for outra.</span></div>

                <div class="form-section">Valores</div>
                <?php foreach (['valor_causa' => 'Valor da causa', 'valor_pago' => 'Valor pago', 'despesas_processuais_1' => 'Despesas processuais 1', 'despesas_processuais_2' => 'Despesas processuais 2'] as $c => $r): ?>
                <div class="field"><label for="<?php echo $c; ?>"><?php echo $r; ?></label>
                    <div class="input-group"><span class="prefix">R$</span><input class="input input-money" id="<?php echo $c; ?>" name="<?php echo $c; ?>" placeholder="0,00" autocomplete="off"></div><span class="field-error"></span></div>
                <?php endforeach; ?>
                <div class="field full"><label for="economia">Economia</label>
                    <div class="row" style="flex-wrap:nowrap">
                        <div class="input-group grow" style="max-width:240px"><span class="prefix">R$</span><input class="input input-money" id="economia" name="economia" placeholder="0,00" autocomplete="off"></div>
                        <button type="button" class="btn btn-ghost btn-sm" id="usar-calculo" title="Valor da causa − despesas − valor pago">Usar cálculo: <strong class="num" id="economia-calc">R$ 0,00</strong></button>
                    </div>
                    <span class="hint">Cálculo sugerido = valor da causa − despesas − valor pago (mesma fórmula do relatório de Performance).</span>
                </div>

                <div class="form-section">Decisões</div>
                <div class="field full"><label for="sentenca_1_instancia">Sentença 1ª instância</label><textarea class="textarea" id="sentenca_1_instancia" name="sentenca_1_instancia" rows="3"></textarea></div>
                <div class="field full"><label for="recurso">Recurso</label><textarea class="textarea" id="recurso" name="recurso" rows="3"></textarea></div>
            </div>
        </div>
        <div class="modal-footer">
            <span class="hint left hide-mobile"><span class="kbd">Ctrl</span> + <span class="kbd">Enter</span> salva</span>
            <button type="button" class="btn btn-secondary" data-action="modal-close">Cancelar</button>
            <button type="submit" class="btn btn-primary" id="btn-salvar">Salvar processo</button>
        </div>
    </form>
</div>

<?php
layout_fim([
    'scripts' => ['pages/processos.js'],
    'dados' => [
        'meses' => array_values($meses),
        'status' => status_processo_opcoes(),
        'campos' => $CAMPOS,
        'abrir' => get_int('ver', null, 1),
        'novo' => isset($_GET['novo']),
    ],
]);
