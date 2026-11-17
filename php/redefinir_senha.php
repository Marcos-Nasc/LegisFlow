<?php
/**
 * Criação de nova senha a partir do link enviado por e-mail.
 */
require_once __DIR__ . '/../includes/bootstrap.php';

$token = (string) ($_POST['token'] ?? ($_GET['token'] ?? ''));
$usuario = $token !== '' ? validar_token_redefinicao($pdo, $token) : null;
$erro = '';

if ($usuario && is_post()) {
    $nova = (string) ($_POST['senha'] ?? '');
    $conf = (string) ($_POST['confirmar'] ?? '');
    if (!csrf_valido()) {
        $erro = 'A página ficou aberta por muito tempo. Tente novamente.';
    } elseif (($msg = validar_senha_nova($nova)) !== null) {
        $erro = $msg;
    } elseif ($nova !== $conf) {
        $erro = 'As senhas não conferem.';
    } else {
        try {
            $pdo->prepare('UPDATE usuarios SET senha = ? WHERE id = ?')->execute([password_hash($nova, PASSWORD_DEFAULT), $usuario['id']]);
            registrar_log($pdo, 'Senha Redefinida por E-mail', (int) $usuario['id'], "Usuário '" . $usuario['nome'] . "' (ID: " . $usuario['id'] . ') redefiniu a senha pelo link de recuperação.');
            redirecionar(BASE_URL . '/php/login.php?status=senha_redefinida');
        } catch (PDOException $e) {
            $ref = erro_interno('Redefinir senha', $e);
            $erro = "Erro no servidor (ref. $ref).";
        }
    }
}
$b = BASE_URL;
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Nova senha · <?php echo e(nome_sistema()); ?></title>
    <link rel="icon" type="image/png" href="<?php echo $b; ?>/images/logo_icone.png">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/vendor/fontawesome/css/all.min.css">
    <link rel="stylesheet" href="<?php echo $b; ?>/assets/css/app.css?v=<?php echo APP_VERSION; ?>">
</head>
<body class="page-center">
    <div class="message-card" style="text-align:left">
        <?php if (!$usuario): ?>
            <div class="message-icon"><i class="fa-solid fa-link-slash"></i></div>
            <h1>Link inválido ou expirado</h1>
            <p>Este link de redefinição não é mais válido. Ele expira em 1 hora e deixa de funcionar depois que a senha é alterada.</p>
            <div class="message-actions" style="justify-content:flex-start"><a href="esqueci_senha.php" class="btn btn-primary">Solicitar novo link</a><a href="login.php" class="btn btn-ghost">Voltar ao login</a></div>
        <?php else: ?>
            <h1>Criar nova senha</h1>
            <p class="mb-4">Conta: <strong><?php echo e($usuario['email']); ?></strong></p>
            <?php if ($erro !== ''): ?>
                <div class="alert alert-danger mb-4" role="alert"><i class="fa-solid fa-circle-exclamation"></i><div class="alert-body"><?php echo e($erro); ?></div></div>
            <?php endif; ?>
            <form method="POST">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="token" value="<?php echo e($token); ?>">
                <div class="field">
                    <label for="senha">Nova senha</label>
                    <input class="input" type="password" id="senha" name="senha" required minlength="8" autocomplete="new-password" autofocus>
                    <span class="hint">Mínimo de 8 caracteres, com letras e números.</span>
                </div>
                <div class="field mt-4">
                    <label for="confirmar">Confirmar nova senha</label>
                    <input class="input" type="password" id="confirmar" name="confirmar" required autocomplete="new-password">
                </div>
                <button type="submit" class="btn btn-primary btn-block mt-6">Salvar nova senha</button>
            </form>
        <?php endif; ?>
    </div>
<script src="<?php echo $b; ?>/assets/js/app.js?v=<?php echo APP_VERSION; ?>"></script>
</body>
</html>
