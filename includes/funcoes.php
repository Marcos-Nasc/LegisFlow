<?php
/**
 * Funções utilitárias compartilhadas por todo o sistema.
 */

if (defined('LF_FUNCOES_CARREGADAS')) {
    return;
}
define('LF_FUNCOES_CARREGADAS', true);

/* =========================================================================
 * LOG DE ATIVIDADES (assinatura original preservada)
 * ========================================================================= */

/**
 * Registra uma ação do usuário no log do sistema.
 *
 * @param PDO         $pdo
 * @param string      $acao       Descrição curta (ex.: "Falha no Login")
 * @param int|null    $id_usuario ID do usuário (null quando desconhecido)
 * @param string|null $detalhes   Detalhes adicionais
 */
function registrar_log($pdo, $acao, $id_usuario = null, $detalhes = null)
{
    if (!$pdo instanceof PDO) {
        return;
    }
    try {
        $ip_usuario = $_SERVER['REMOTE_ADDR'] ?? 'desconhecido';
        // Garante que o ID seja inteiro ou NULL (evita gravar texto na coluna)
        $id_usuario = (is_numeric($id_usuario) && (int) $id_usuario > 0) ? (int) $id_usuario : null;

        $stmt = $pdo->prepare(
            'INSERT INTO logs (id_usuario, acao, detalhes, ip_usuario) VALUES (:id_usuario, :acao, :detalhes, :ip_usuario)'
        );
        $stmt->bindValue(':id_usuario', $id_usuario, $id_usuario === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':acao', mb_substr((string) $acao, 0, 255), PDO::PARAM_STR);
        $stmt->bindValue(':detalhes', $detalhes, $detalhes === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':ip_usuario', mb_substr((string) $ip_usuario, 0, 45), PDO::PARAM_STR);
        $stmt->execute();
    } catch (PDOException $e) {
        error_log('[LegisFlow] Erro ao registrar log no BD: ' . $e->getMessage());
    }
}

/* =========================================================================
 * SAÍDA / FORMATAÇÃO
 * ========================================================================= */

/** Escapa texto para HTML. */
function e($valor)
{
    return htmlspecialchars((string) ($valor ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Formata número como moeda brasileira (R$ 1.234,56). */
function moeda($valor, $comSimbolo = true)
{
    $txt = number_format((float) ($valor ?? 0), 2, ',', '.');
    return $comSimbolo ? 'R$ ' . $txt : $txt;
}

/** Formata moeda de forma compacta para indicadores (R$ 1,2 mi). */
function moeda_compacta($valor)
{
    $v = (float) ($valor ?? 0);
    $abs = abs($v);
    if ($abs >= 1000000000) {
        return 'R$ ' . number_format($v / 1000000000, 1, ',', '.') . ' bi';
    }
    if ($abs >= 1000000) {
        return 'R$ ' . number_format($v / 1000000, 1, ',', '.') . ' mi';
    }
    if ($abs >= 10000) {
        return 'R$ ' . number_format($v / 1000, 1, ',', '.') . ' mil';
    }
    return moeda($v);
}

/** Formata inteiro com separador de milhar. */
function numero($valor)
{
    return number_format((float) ($valor ?? 0), 0, ',', '.');
}

/** Formata uma data (Y-m-d ou Y-m-d H:i:s) para o padrão brasileiro. */
function data_br($valor, $comHora = false)
{
    if (empty($valor) || strpos((string) $valor, '0000-00-00') === 0) {
        return '—';
    }
    $ts = strtotime((string) $valor);
    if ($ts === false) {
        return e($valor);
    }
    return date($comHora ? 'd/m/Y H:i' : 'd/m/Y', $ts);
}

/** Converte "1.500,50", "1500.50", "R$ 1.500" etc. em float. Retorna 0.0 quando vazio. */
function decimal_br($valor)
{
    if ($valor === null || $valor === '') {
        return 0.0;
    }
    if (is_int($valor) || is_float($valor)) {
        return (float) $valor;
    }
    $s = preg_replace('/[^\d,.\-]/', '', (string) $valor);
    if ($s === '' || $s === '-') {
        return 0.0;
    }
    $ultimoPonto = strrpos($s, '.');
    $ultimaVirgula = strrpos($s, ',');
    if ($ultimoPonto !== false && $ultimaVirgula !== false) {
        if ($ultimoPonto < $ultimaVirgula) {          // 1.234,56
            $s = str_replace('.', '', $s);
            $s = str_replace(',', '.', $s);
        } else {                                        // 1,234.56
            $s = str_replace(',', '', $s);
        }
    } elseif ($ultimaVirgula !== false) {               // 1234,56
        $s = str_replace(',', '.', $s);
    } elseif ($ultimoPonto !== false && (substr_count($s, '.') > 1 || preg_match('/^-?\d{1,3}\.\d{3}$/', $s))) {
        // 1.234.567 ou 1.000 (ponto como separador de milhar, padrão brasileiro)
        $s = str_replace('.', '', $s);
    }
    return round((float) $s, 2);
}

/** Valor decimal para preencher um campo de formulário (1234.5 -> "1.234,50"). */
function decimal_para_campo($valor)
{
    if ($valor === null || $valor === '') {
        return '';
    }
    return number_format((float) $valor, 2, ',', '.');
}

/** Nomes dos meses. */
function meses_nomes($curto = false)
{
    if ($curto) {
        return [1 => 'Jan', 2 => 'Fev', 3 => 'Mar', 4 => 'Abr', 5 => 'Mai', 6 => 'Jun', 7 => 'Jul', 8 => 'Ago', 9 => 'Set', 10 => 'Out', 11 => 'Nov', 12 => 'Dez'];
    }
    return [1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril', 5 => 'Maio', 6 => 'Junho', 7 => 'Julho', 8 => 'Agosto', 9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro'];
}

/** Lista de anos para filtros (do ano atual até 2020, mais anos existentes no banco). */
function anos_disponiveis($pdo = null)
{
    $anos = range((int) date('Y'), 2020);
    if ($pdo instanceof PDO) {
        try {
            $extras = $pdo->query(
                'SELECT DISTINCT ano FROM processos_judiciais WHERE ano IS NOT NULL
                 UNION SELECT DISTINCT ano FROM relatorio_mensal_processos'
            )->fetchAll(PDO::FETCH_COLUMN);
            foreach ($extras as $a) {
                $a = (int) $a;
                if ($a >= 1990 && $a <= (int) date('Y') + 1) {
                    $anos[] = $a;
                }
            }
        } catch (PDOException $e) {
            // mantém a lista padrão
        }
    }
    $anos = array_values(array_unique($anos));
    rsort($anos);
    return $anos;
}

/** Status possíveis de um processo (valores do ENUM no banco). */
function status_processo_opcoes()
{
    return [
        'em andamento' => 'Em andamento',
        'finalizado'   => 'Finalizado',
        'arquivado'    => 'Arquivado',
    ];
}

/** Badge HTML para o status do processo. */
function badge_status($status)
{
    $map = [
        'em andamento' => ['info', 'Em andamento'],
        'finalizado'   => ['success', 'Finalizado'],
        'arquivado'    => ['neutral', 'Arquivado'],
    ];
    $s = $map[$status ?? ''] ?? ['neutral', $status ? ucfirst($status) : '—'];
    return '<span class="badge badge-' . $s[0] . '">' . e($s[1]) . '</span>';
}

/** Nome do nível de acesso. */
function nome_nivel($nivel)
{
    $nomes = [1 => 'Usuário', 2 => 'Editor', 3 => 'Administrador'];
    return $nomes[(int) $nivel] ?? ('Nível ' . (int) $nivel);
}

/* =========================================================================
 * CONFIGURAÇÕES (tabela `configuracoes`)
 * ========================================================================= */

function getSetting($pdo, $key, $default = '')
{
    try {
        $stmt = $pdo->prepare('SELECT valor FROM configuracoes WHERE chave = ?');
        $stmt->execute([$key]);
        $valor = $stmt->fetchColumn();
        return ($valor === false || $valor === null) ? $default : $valor;
    } catch (PDOException $e) {
        return $default;
    }
}

function setSetting($pdo, $key, $value)
{
    $stmt = $pdo->prepare(
        'INSERT INTO configuracoes (chave, valor) VALUES (?, ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)'
    );
    return $stmt->execute([$key, $value]);
}

/** Nome do sistema exibido na interface. */
function nome_sistema()
{
    static $nome = null;
    if ($nome === null) {
        global $pdo;
        $nome = $pdo instanceof PDO ? (string) getSetting($pdo, 'app_name', '') : '';
        if (trim($nome) === '') {
            $nome = APP_NAME;
        }
    }
    return $nome;
}

/** Segredo da aplicação (usado para assinar links de redefinição de senha). */
function app_secret($pdo)
{
    $segredo = getSetting($pdo, 'app_secret', '');
    if (strlen($segredo) < 32) {
        $segredo = bin2hex(random_bytes(32));
        try {
            setSetting($pdo, 'app_secret', $segredo);
        } catch (PDOException $e) {
            error_log('[LegisFlow] Não foi possível gravar app_secret: ' . $e->getMessage());
        }
    }
    return $segredo;
}

/* =========================================================================
 * REQUISIÇÃO / RESPOSTA
 * ========================================================================= */

function is_post()
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function is_ajax()
{
    return (strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest')
        || (strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false);
}

/** Envia JSON e encerra. */
function responder_json($dados, $codigo = 200)
{
    if (!headers_sent()) {
        http_response_code($codigo);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode($dados, JSON_UNESCAPED_UNICODE);
    exit();
}

/** Redireciona e encerra. */
function redirecionar($url)
{
    header('Location: ' . $url);
    exit();
}

/** Mensagem "flash" exibida na próxima página (toast). */
function flash($tipo, $mensagem)
{
    $_SESSION['_flash'][] = ['type' => $tipo, 'message' => $mensagem];
}

function flashes_pendentes()
{
    $f = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return $f;
}

/** Inteiro vindo de GET, com faixa opcional. */
function get_int($chave, $padrao = null, $min = null, $max = null)
{
    $v = filter_input(INPUT_GET, $chave, FILTER_VALIDATE_INT);
    if ($v === false || $v === null) {
        return $padrao;
    }
    if ($min !== null && $v < $min) {
        return $padrao;
    }
    if ($max !== null && $v > $max) {
        return $padrao;
    }
    return $v;
}

/** Monta query string preservando os parâmetros atuais. */
function url_com($params, $base = '')
{
    $atual = $_GET;
    foreach ($params as $k => $v) {
        if ($v === null || $v === '') {
            unset($atual[$k]);
        } else {
            $atual[$k] = $v;
        }
    }
    $qs = http_build_query($atual);
    return $base . ($qs !== '' ? '?' . $qs : ($base === '' ? '?' : ''));
}

/** Indica se a exceção do PDO é de chave duplicada (MySQL 1062 / SQLSTATE 23000). */
function erro_duplicidade(PDOException $e)
{
    return (isset($e->errorInfo[1]) && (int) $e->errorInfo[1] === 1062)
        || ($e->getCode() === '23000' && stripos($e->getMessage(), 'unique') !== false)
        || stripos($e->getMessage(), 'Duplicate entry') !== false;
}

/** Registra erro técnico e devolve um código curto para o usuário informar ao suporte. */
function erro_interno($contexto, Throwable $e)
{
    $ref = strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
    error_log("[LegisFlow][$ref] $contexto: " . $e->getMessage());
    return $ref;
}

/* =========================================================================
 * PAGINAÇÃO
 * ========================================================================= */

/**
 * HTML de paginação.
 * @param int $pagina   Página atual
 * @param int $total    Total de páginas
 */
function paginacao($pagina, $total)
{
    if ($total <= 1) {
        return '';
    }
    $html = '<nav class="pagination" aria-label="Paginação">';
    $link = function ($p, $rotulo, $classe = '', $aria = '') {
        return '<a class="' . $classe . '" href="' . e(url_com(['page' => $p])) . '"' . ($aria ? ' aria-label="' . $aria . '"' : '') . '>' . $rotulo . '</a>';
    };
    if ($pagina > 1) {
        $html .= $link($pagina - 1, '<i class="fa-solid fa-chevron-left"></i>', 'page-nav', 'Página anterior');
    } else {
        $html .= '<span class="page-nav is-disabled" aria-hidden="true"><i class="fa-solid fa-chevron-left"></i></span>';
    }
    $janela = 1;
    $inicio = max(1, $pagina - $janela);
    $fim = min($total, $pagina + $janela);
    if ($inicio > 1) {
        $html .= $link(1, '1');
        if ($inicio > 2) {
            $html .= '<span class="page-gap">…</span>';
        }
    }
    for ($i = $inicio; $i <= $fim; $i++) {
        $html .= $i === $pagina
            ? '<span class="is-active" aria-current="page">' . $i . '</span>'
            : $link($i, (string) $i);
    }
    if ($fim < $total) {
        if ($fim < $total - 1) {
            $html .= '<span class="page-gap">…</span>';
        }
        $html .= $link($total, (string) $total);
    }
    if ($pagina < $total) {
        $html .= $link($pagina + 1, '<i class="fa-solid fa-chevron-right"></i>', 'page-nav', 'Próxima página');
    } else {
        $html .= '<span class="page-nav is-disabled" aria-hidden="true"><i class="fa-solid fa-chevron-right"></i></span>';
    }
    return $html . '</nav>';
}

/* =========================================================================
 * VARAS (classificação usada nos relatórios de volume — regra original)
 * ========================================================================= */

/** Classifica um tipo de vara em 'civeis', 'trabalhistas' ou 'outros'. */
function classificar_vara($tipo_vara)
{
    // Mesma regra do sistema anterior (comparação de bytes: "CÍVEIS"/"CIVEIS" em maiúsculas)
    if (stripos($tipo_vara, 'CÍVEIS') !== false || stripos($tipo_vara, 'CIVEIS') !== false) {
        return 'civeis';
    }
    if (stripos($tipo_vara, 'TRABALHISTA') !== false) {
        return 'trabalhistas';
    }
    return 'outros';
}
