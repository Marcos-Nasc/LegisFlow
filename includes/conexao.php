<?php
/**
 * Conexão com o banco de dados.
 *
 * As credenciais NÃO ficam mais neste arquivo. Elas são lidas de
 * includes/config.local.php (fora do controle de versão — veja
 * includes/config.local.example.php). Esse arquivo deve retornar um array:
 *
 *   return ['db' => ['host' => '...', 'dbname' => '...', 'user' => '...', 'pass' => '...']];
 *
 * Também é aceito definir as variáveis de ambiente LF_DB_HOST, LF_DB_NAME,
 * LF_DB_USER e LF_DB_PASS.
 */

if (isset($pdo) && $pdo instanceof PDO) {
    return; // Conexão já criada (ex.: ambiente de testes)
}

require_once __DIR__ . '/config.php';

$lf_cfg = lf_config_local();

// Permite que o config.local.php forneça um PDO pronto (usado em testes automatizados).
if (isset($lf_cfg['pdo_factory']) && is_callable($lf_cfg['pdo_factory'])) {
    $pdo = call_user_func($lf_cfg['pdo_factory']);
    return;
}

$host   = $lf_cfg['db']['host']   ?? (getenv('LF_DB_HOST') ?: 'localhost');
$dbname = $lf_cfg['db']['dbname'] ?? (getenv('LF_DB_NAME') ?: '');
$user   = $lf_cfg['db']['user']   ?? (getenv('LF_DB_USER') ?: '');
$pass   = $lf_cfg['db']['pass']   ?? (getenv('LF_DB_PASS') ?: '');

try {
    $pdo = new PDO(
        "mysql:host={$host};dbname={$dbname};charset=utf8mb4",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => true, // mantém o comportamento anterior (placeholders repetidos)
        ]
    );
} catch (PDOException $e) {
    error_log('[LegisFlow] Falha na conexão com o banco: ' . $e->getMessage());
    if (!headers_sent()) {
        http_response_code(503);
    }
    $lf_conexao_erro = true;
    // Página de erro amigável, sem expor detalhes técnicos
    echo '<!DOCTYPE html><html lang="pt-br"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>Serviço indisponível</title><style>body{font-family:system-ui,-apple-system,Segoe UI,sans-serif;background:#f5f6f8;color:#111827;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;padding:16px}'
        . '.c{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:32px;max-width:440px;text-align:center}h1{font-size:20px;margin:0 0 8px}p{color:#4b5563;margin:0;line-height:1.5}</style></head>'
        . '<body><div class="c"><h1>Não foi possível conectar ao banco de dados</h1><p>O sistema está temporariamente indisponível. Tente novamente em alguns minutos. Se o problema persistir, avise o administrador.</p></div></body></html>';
    exit();
}
