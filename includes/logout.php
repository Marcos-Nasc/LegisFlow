<?php
/**
 * Encerra a sessão do usuário (com registro em log).
 */
require_once __DIR__ . '/bootstrap.php';

$id_usuario = usuario_id();
$nome_usuario = $_SESSION['nome_usuario'] ?? 'Usuário desconhecido';

if ($id_usuario) {
    registrar_log($pdo, 'Logout do Sistema', $id_usuario, "Usuário '" . $nome_usuario . "' (ID: " . $id_usuario . ') saiu do sistema.');
}

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}
session_destroy();

redirecionar(BASE_URL . '/php/login.php?status=logout_sucesso');
