<?php
/**
 * Ponto único de inicialização. Toda página começa com:
 *
 *   require_once __DIR__ . '/../includes/bootstrap.php';
 *
 * Carrega configuração, sessão segura, conexão, funções e autenticação.
 */

if (defined('LF_BOOTSTRAP')) {
    return;
}
define('LF_BOOTSTRAP', true);
define('LF_ROOT', dirname(__DIR__));

// Erros vão para o log do servidor, nunca para a tela do usuário
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

if (!ini_get('date.timezone') || date_default_timezone_get() === 'UTC') {
    date_default_timezone_set('America/Sao_Paulo');
}
mb_internal_encoding('UTF-8');

require_once __DIR__ . '/config.php';

/* ---------------------------------------------------------------------------
 * URL base: detectada automaticamente (funciona na raiz do domínio ou em
 * subpasta, ex.: http://localhost/juridico). Pode ser fixada em
 * config.local.php com ['base_url' => '/minha-pasta'].
 * ------------------------------------------------------------------------- */
if (!defined('BASE_URL')) {
    $lf_base = '';
    $lf_cfg_tmp = lf_config_local();
    if (isset($lf_cfg_tmp['base_url'])) {
        $lf_base = rtrim((string) $lf_cfg_tmp['base_url'], '/');
    } else {
        $docRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? '');
        $raiz = realpath(LF_ROOT);
        if ($docRoot && $raiz && strpos($raiz, $docRoot) === 0) {
            $lf_base = str_replace('\\', '/', substr($raiz, strlen($docRoot)));
        } else {
            // Fallback: a partir do script atual (index.php na raiz, demais em /php)
            $dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
            $lf_base = preg_replace('#/(php|includes)$#', '', $dir);
        }
        $lf_base = rtrim($lf_base, '/');
    }
    define('BASE_URL', $lf_base);
    unset($lf_base, $lf_cfg_tmp);
}

/* ---------------------------------------------------------------------------
 * Sessão com cookies seguros
 * ------------------------------------------------------------------------- */
if (session_status() === PHP_SESSION_NONE && PHP_SAPI !== 'cli') {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    ini_set('session.use_strict_mode', '1');
    session_start();
}

/* ---------------------------------------------------------------------------
 * Cabeçalhos de segurança
 * ------------------------------------------------------------------------- */
if (!headers_sent() && PHP_SAPI !== 'cli') {
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/funcoes.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/pendencias.php';

/* ---------------------------------------------------------------------------
 * Tema do usuário (claro/escuro) — lido do banco uma vez por sessão
 * ------------------------------------------------------------------------- */
if (usuario_logado() && !isset($_SESSION['tema'])) {
    try {
        $stmt = $pdo->prepare('SELECT preferencia_tema FROM usuarios WHERE id = ?');
        $stmt->execute([usuario_id()]);
        $_SESSION['tema'] = $stmt->fetchColumn() === 'dark' ? 'dark' : 'light';
    } catch (PDOException $e) {
        $_SESSION['tema'] = 'light';
    }
}
$themeClass = (($_SESSION['tema'] ?? 'light') === 'dark') ? 'dark' : ''; // compatibilidade
