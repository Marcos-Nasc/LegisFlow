<?php
/**
 * Salva a preferência de tema (claro/escuro) do usuário logado.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
exigir_login();

if (!is_post()) {
    responder_json(['status' => 'error', 'message' => 'Método não permitido.'], 405);
}

$novoTema = $_POST['tema'] ?? '';
if (!in_array($novoTema, ['light', 'dark'], true)) {
    responder_json(['status' => 'error', 'message' => 'Valor de tema inválido.'], 422);
}

try {
    $stmt = $pdo->prepare('UPDATE usuarios SET preferencia_tema = ? WHERE id = ?');
    $stmt->execute([$novoTema, usuario_id()]);
    $_SESSION['tema'] = $novoTema;
    registrar_log($pdo, 'Alteração de Tema', usuario_id(), 'Usuário alterou seu tema para: ' . $novoTema);
    responder_json(['status' => 'success', 'message' => 'Tema salvo com sucesso.']);
} catch (PDOException $e) {
    $ref = erro_interno('Salvar tema', $e);
    responder_json(['status' => 'error', 'message' => "Não foi possível salvar o tema (ref. $ref)."], 500);
}
