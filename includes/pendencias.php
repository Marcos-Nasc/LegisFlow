<?php
/**
 * Pendências operacionais calculadas a partir dos dados existentes
 * (sem tabelas novas). Exibidas no Dashboard e no sino da barra superior.
 */

if (defined('LF_PENDENCIAS_CARREGADAS')) {
    return;
}
define('LF_PENDENCIAS_CARREGADAS', true);

/** Condição SQL de "cadastro incompleto" para processos em andamento. */
function sql_processo_incompleto()
{
    return "(status = 'em andamento' AND (valor_causa IS NULL OR valor_causa = 0
              OR materia IS NULL OR TRIM(materia) = ''
              OR central_custo IS NULL OR TRIM(central_custo) = ''))";
}

/**
 * Lista de pendências. Cada item: tipo, titulo, descricao, quantidade, url, severidade.
 * Resultado em cache na sessão por 5 minutos.
 */
function pendencias_listar($pdo, $forcar = false)
{
    $cache = $_SESSION['_pendencias'] ?? null;
    if (!$forcar && $cache && (time() - $cache['t']) < 300) {
        return $cache['itens'];
    }

    $b = BASE_URL;
    $itens = [];
    $meses = meses_nomes();

    try {
        // 1) Totais mensais sem lançamento nos últimos 3 meses fechados
        $temDados = (int) $pdo->query('SELECT COUNT(*) FROM relatorio_mensal_processos')->fetchColumn() > 0;
        if ($temDados) {
            $faltando = [];
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM relatorio_mensal_processos WHERE ano = ? AND mes = ?');
            $ref = new DateTime('first day of this month');
            for ($i = 1; $i <= 3; $i++) {
                $d = (clone $ref)->modify("-$i month");
                $ano = (int) $d->format('Y');
                $mes = (int) $d->format('n');
                $stmt->execute([$ano, $mes]);
                if ((int) $stmt->fetchColumn() === 0) {
                    $faltando[] = ['ano' => $ano, 'mes' => $mes, 'rotulo' => $meses[$mes] . '/' . $ano];
                }
            }
            if ($faltando) {
                $rotulos = array_reverse(array_column($faltando, 'rotulo'));
                $itens[] = [
                    'tipo' => 'totais',
                    'titulo' => count($faltando) === 1 ? 'Totais mensais sem lançamento' : 'Meses sem totais lançados',
                    'descricao' => implode(', ', $rotulos),
                    'quantidade' => count($faltando),
                    'url' => $b . '/php/totais_mensais.php?ano=' . end($faltando)['ano'],
                    'severidade' => 'warning',
                    'icone' => 'fa-table-cells',
                ];
            }
        }

        // 2) Processos em andamento com cadastro incompleto
        $qtd = (int) $pdo->query('SELECT COUNT(*) FROM processos_judiciais WHERE ' . sql_processo_incompleto())->fetchColumn();
        if ($qtd > 0) {
            $itens[] = [
                'tipo' => 'incompletos',
                'titulo' => 'Processos com cadastro incompleto',
                'descricao' => 'Em andamento sem valor da causa, matéria ou central de custo.',
                'quantidade' => $qtd,
                'url' => $b . '/php/processos_detalhados.php?pendencia=incompletos',
                'severidade' => 'info',
                'icone' => 'fa-file-circle-exclamation',
            ];
        }
    } catch (PDOException $e) {
        error_log('[LegisFlow] Falha ao calcular pendências: ' . $e->getMessage());
    }

    $_SESSION['_pendencias'] = ['t' => time(), 'itens' => $itens];
    return $itens;
}

function pendencias_total($pdo)
{
    $total = 0;
    foreach (pendencias_listar($pdo) as $p) {
        $total += (int) $p['quantidade'];
    }
    return $total;
}

/** Descarta o cache (chamar após gravações que afetam pendências). */
function pendencias_invalidar()
{
    unset($_SESSION['_pendencias']);
}
