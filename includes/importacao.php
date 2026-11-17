<?php
/**
 * Utilitários compartilhados pelas telas de importação de planilhas.
 */

require_once LF_ROOT . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as XlsDate;

/**
 * Valida o upload e lê a planilha ativa como array [linha => [A => valor, ...]].
 * Retorna [dados, null] ou [null, mensagemDeErro].
 */
function ler_planilha_upload($campo)
{
    if (!isset($_FILES[$campo]) || $_FILES[$campo]['error'] === UPLOAD_ERR_NO_FILE) {
        return [null, 'Selecione o arquivo da planilha.'];
    }
    $f = $_FILES[$campo];
    if ($f['error'] !== UPLOAD_ERR_OK) {
        $msgs = [UPLOAD_ERR_INI_SIZE => 'O arquivo excede o tamanho máximo permitido pelo servidor.', UPLOAD_ERR_FORM_SIZE => 'O arquivo é muito grande.', UPLOAD_ERR_PARTIAL => 'O envio foi interrompido. Tente novamente.'];
        return [null, $msgs[$f['error']] ?? ('Falha no envio do arquivo (código ' . $f['error'] . ').')];
    }
    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['xlsx', 'xls', 'csv', 'ods'], true)) {
        return [null, 'Formato não suportado. Envie uma planilha .xlsx, .xls, .ods ou .csv.'];
    }
    if ($f['size'] > 10 * 1024 * 1024) {
        return [null, 'A planilha tem mais de 10 MB. Divida o arquivo e importe em partes.'];
    }
    try {
        $reader = IOFactory::createReaderForFile($f['tmp_name']);
        if (method_exists($reader, 'setReadDataOnly')) {
            $reader->setReadDataOnly(false); // mantém formatos (datas e moeda)
        }
        $ss = $reader->load($f['tmp_name']);
        $sheet = $ss->getActiveSheet();
        $dados = $sheet->toArray(null, true, true, true);   // valores formatados (como antes)
        $GLOBALS['lf_planilha_bruta'] = $sheet->toArray(null, true, false, true); // valores brutos (datas como número serial)
        return [$dados, null];
    } catch (Throwable $e) {
        error_log('[LegisFlow] Erro ao ler planilha: ' . $e->getMessage());
        return [null, 'Não foi possível ler o arquivo. Verifique se é uma planilha válida e não está protegida por senha.'];
    }
}

/** Converte data de planilha (número serial, dd/mm/aaaa ou aaaa-mm-dd) para Y-m-d. */
function data_planilha($valor)
{
    if ($valor === null || $valor === '') {
        return null;
    }
    if (is_numeric($valor)) {
        try {
            return XlsDate::excelToDateTimeObject($valor)->format('Y-m-d');
        } catch (Throwable $e) {
            return null;
        }
    }
    $valor = trim((string) $valor);
    // Padrão brasileiro (dia/mês/ano), como no sistema anterior; também aceita ISO (aaaa-mm-dd)
    foreach (['d/m/Y', 'j/n/Y', 'd/m/y', 'j/n/y', 'd-m-Y', 'd.m.Y', 'Y-m-d'] as $fmt) {
        $d = DateTime::createFromFormat('!' . $fmt, $valor);
        $erros = DateTime::getLastErrors();
        if ($d && (!$erros || ($erros['warning_count'] === 0 && $erros['error_count'] === 0))) {
            return $d->format('Y-m-d');
        }
    }
    return null;
}

/** Guarda os dados processados na sessão e devolve um identificador. */
function import_guardar($tipo, $payload)
{
    $token = bin2hex(random_bytes(8));
    $_SESSION['_import'] = [$tipo => ['token' => $token, 'payload' => $payload, 't' => time()]];
    return $token;
}

function import_recuperar($tipo, $token)
{
    $i = $_SESSION['_import'][$tipo] ?? null;
    if (!$i || !hash_equals($i['token'], (string) $token) || time() - $i['t'] > 3600) {
        return null;
    }
    return $i['payload'];
}

function import_limpar()
{
    unset($_SESSION['_import']);
}

/** Indicador de passos (1 Arquivo · 2 Conferência · 3 Concluído). */
function import_passos($atual)
{
    $nomes = [1 => 'Enviar planilha', 2 => 'Conferir dados', 3 => 'Concluído'];
    $h = '<div class="steps" aria-label="Etapas da importação">';
    foreach ($nomes as $n => $nome) {
        $cls = $n < $atual ? 'is-done' : ($n === $atual ? 'is-active' : '');
        $h .= '<span class="step ' . $cls . '"' . ($n === $atual ? ' aria-current="step"' : '') . '><span class="step-n">' . ($n < $atual ? '<i class="fa-solid fa-check"></i>' : $n) . '</span>' . $nome . '</span>';
        if ($n < 3) {
            $h .= '<span class="step-sep"></span>';
        }
    }
    return $h . '</div>';
}
