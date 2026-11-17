<?php
/**
 * Solicitação de redefinição de senha por e-mail.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/email.php';

if (usuario_logado()) {
    redirecionar(BASE_URL . '/index.php');
}

$enviado = false;
$erro = '';
$email = '';
$smtpOk = smtp_configurado($pdo);

if (is_post()) {
    $email = trim((string) ($_POST['email'] ?? ''));
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'desconhecido';
    if (!csrf_valido()) {
        $erro = 'A página ficou aberta por muito tempo. Tente novamente.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $erro = 'Informe um e-mail válido.';
    } elseif (!$smtpOk) {
        $erro = 'A recuperação por e-mail não está disponível. Fale com um administrador para redefinir sua senha.';
    } else {
        try {
            $stmt = $pdo->prepare(
                "SELECT COUNT(*) FROM logs WHERE acao = 'Solicitação de Redefinição de Senha' AND ip_usuario = ?
                 AND data_ocorrencia > DATE_SUB(NOW(), INTERVAL 15 MINUTE)"
            );
            $stmt->execute([$ip]);
            if ((int) $stmt->fetchColumn() >= 5) {
                $erro = 'Muitas solicitações em pouco tempo. Aguarde alguns minutos.';
            } else {
                $stmt = $pdo->prepare("SELECT id, nome, email, senha, status FROM usuarios WHERE email = ? AND status = '1'");
                $stmt->execute([$email]);
                $u = $stmt->fetch(PDO::FETCH_ASSOC);
                registrar_log($pdo, 'Solicitação de Redefinição de Senha', $u ? (int) $u['id'] : null, "Solicitação para o e-mail: '$email'.");
                if ($u) {
                    $link = url_absoluta('/php/redefinir_senha.php?token=' . gerar_token_redefinicao($pdo, $u));
                    $html = email_template(
                        'Redefinição de senha',
                        '<p>Olá, ' . e($u['nome']) . '.</p><p>Recebemos uma solicitação para redefinir sua senha. O link abaixo vale por 1 hora e só pode ser usado uma vez.</p>',
                        'Criar nova senha',
                        $link
                    ) ;
                    list($ok, $msg) = enviar_email($pdo, $u['email'], $u['nome'], 'Redefinição de senha — ' . nome_sistema(), $html);
                    if (!$ok) {
                        registrar_log($pdo, 'Falha ao Enviar E-mail de Redefinição', (int) $u['id'], 'Erro: ' . $msg);
                    }
                }
                // Mesma resposta exista ou não a conta (não revela e-mails cadastrados)
                $enviado = true;
            }
        } catch (PDOException $e) {
            $ref = erro_interno('Esqueci senha', $e);
            $erro = "Erro no servidor (ref. $ref). Tente novamente.";
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
    <title>Recuperar senha · <?php echo e(nome_sistema()); ?></title>
    <link rel="icon" type="image/png" href="<?php echo $b; ?>/images/logo_icone.png">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/vendor/fontawesome/css/all.min.css">
    <link rel="stylesheet" href="<?php echo $b; ?>/assets/css/app.css?v=<?php echo APP_VERSION; ?>">
</head>
<body class="page-center">
    <div class="message-card" style="text-align:left">
        <?php if ($enviado): ?>
            <div class="message-icon"><i class="fa-regular fa-envelope"></i></div>
            <h1>Verifique seu e-mail</h1>
            <p>Se <strong><?php echo e($email); ?></strong> estiver cadastrado e ativo, você receberá um link para criar uma nova senha em alguns minutos. Confira também a caixa de spam.</p>
            <div class="message-actions" style="justify-content:flex-start"><a href="login.php" class="btn btn-secondary"><i class="fa-solid fa-arrow-left"></i>Voltar ao login</a></div>
        <?php else: ?>
            <h1>Recuperar senha</h1>
            <p class="mb-4">Informe seu e-mail. Enviaremos um link para você criar uma nova senha.</p>
            <?php if (!$smtpOk): ?>
                <div class="alert alert-warning mb-4"><i class="fa-solid fa-triangle-exclamation"></i><div class="alert-body">O envio de e-mails ainda não foi configurado. Peça a um administrador para redefinir sua senha em Usuários.</div></div>
            <?php endif; ?>
            <?php if ($erro !== ''): ?>
                <div class="alert alert-danger mb-4" role="alert"><i class="fa-solid fa-circle-exclamation"></i><div class="alert-body"><?php echo e($erro); ?></div></div>
            <?php endif; ?>
            <form method="POST">
                <?php echo csrf_field(); ?>
                <div class="field">
                    <label for="email">E-mail</label>
                    <input class="input" type="email" id="email" name="email" value="<?php echo e($email); ?>" required autofocus autocomplete="username">
                </div>
                <div class="row mt-6" style="justify-content:space-between">
                    <a href="login.php" class="btn btn-ghost"><i class="fa-solid fa-arrow-left"></i>Voltar</a>
                    <button type="submit" class="btn btn-primary" <?php echo $smtpOk ? '' : 'disabled'; ?>>Enviar link</button>
                </div>
            </form>
        <?php endif; ?>
    </div>
<script src="<?php echo $b; ?>/assets/js/app.js?v=<?php echo APP_VERSION; ?>"></script>
</body>
</html>
