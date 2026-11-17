<?php
/**
 * Tela de login.
 */
require_once __DIR__ . '/../includes/bootstrap.php';

if (usuario_logado()) {
    redirecionar(BASE_URL . '/index.php');
}

$erro = '';
$sucesso = '';
$email = '';

$mensagensGet = [
    'acesso_restrito' => ['erro', 'Faça login para continuar.'],
    'conta_inativa'   => ['erro', 'Sua conta está inativa. Fale com um administrador.'],
];
if (isset($_GET['erro'], $mensagensGet[$_GET['erro']])) {
    $erro = $mensagensGet[$_GET['erro']][1];
}
if (($_GET['status'] ?? '') === 'logout_sucesso') {
    $sucesso = 'Você saiu do sistema.';
}
if (($_GET['status'] ?? '') === 'senha_redefinida') {
    $sucesso = 'Senha redefinida. Entre com a nova senha.';
}

/** Aceita apenas caminhos internos como destino após o login. */
function destino_seguro($url)
{
    $url = (string) $url;
    $padrao = BASE_URL . '/index.php';
    // Apenas caminhos internos: sem caracteres de controle/espaço, sem "//", sem "\\", dentro do sistema
    if ($url === '' || preg_match('/[\x00-\x20\x7f\\\\]/', $url) || strpos($url, '//') !== false
        || strpos($url, BASE_URL . '/') !== 0 || $url[0] !== '/') {
        return $padrao;
    }
    if (preg_match('#/(login|logout|esqueci_senha|redefinir_senha)\.php#', $url)) {
        return $padrao;
    }
    return $url;
}

$redirect = $_POST['redirect_url'] ?? ($_GET['redirect_url'] ?? '');

if (is_post()) {
    $email = trim((string) ($_POST['email'] ?? ''));
    $senha = (string) ($_POST['senha'] ?? '');
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'desconhecido';

    if (!csrf_valido()) {
        $erro = 'A página ficou aberta por muito tempo. Tente entrar novamente.';
    } elseif ($email === '' || $senha === '') {
        $erro = 'Informe o e-mail e a senha.';
    } else {
        try {
            // Proteção contra tentativas repetidas (usa a própria tabela de logs)
            $stmt = $pdo->prepare(
                "SELECT COUNT(*) FROM logs WHERE acao = 'Falha no Login' AND ip_usuario = ?
                 AND data_ocorrencia > DATE_SUB(NOW(), INTERVAL 15 MINUTE)"
            );
            $stmt->execute([$ip]);
            $falhasIp = (int) $stmt->fetchColumn();

            if ($falhasIp >= 8) {
                $erro = 'Muitas tentativas sem sucesso. Aguarde 15 minutos e tente novamente.';
                registrar_log($pdo, 'Login Bloqueado (Tentativas)', null, "Login bloqueado temporariamente para o IP $ip (e-mail informado: '$email').");
            } else {
                $stmt = $pdo->prepare('SELECT id, nome, senha, cargo, nivel, status, ultimo_login, preferencia_tema FROM usuarios WHERE email = ?');
                $stmt->execute([$email]);
                $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($usuario && password_verify($senha, $usuario['senha'])) {
                    if ((string) $usuario['status'] !== '1') {
                        $erro = 'Sua conta está inativa. Fale com um administrador.';
                        registrar_log($pdo, 'Falha no Login', (int) $usuario['id'], "Tentativa de login de usuário inativo: '$email'.");
                    } else {
                        session_regenerate_id(true);
                        $_SESSION['user_id'] = (int) $usuario['id'];
                        $_SESSION['user_nivel'] = (int) $usuario['nivel'];
                        $_SESSION['nome_usuario'] = $usuario['nome'];
                        $_SESSION['cargo_usuario'] = $usuario['cargo'];
                        $_SESSION['ultimo_login'] = $usuario['ultimo_login'];
                        $_SESSION['tema'] = $usuario['preferencia_tema'] === 'dark' ? 'dark' : 'light';
                        $_SESSION['_revalidado'] = time();
                        unset($_SESSION['_csrf'], $_SESSION['_pendencias']);

                        // Atualiza o hash se o algoritmo padrão do PHP mudou
                        if (password_needs_rehash($usuario['senha'], PASSWORD_DEFAULT)) {
                            $usuario['senha'] = password_hash($senha, PASSWORD_DEFAULT);
                            $pdo->prepare('UPDATE usuarios SET senha = ? WHERE id = ?')
                                ->execute([$usuario['senha'], $usuario['id']]);
                        }
                        // Assinatura da senha: se ela mudar em outro lugar, esta sessão é encerrada
                        $_SESSION['_pw'] = substr(hash('sha256', (string) $usuario['senha']), 0, 16);
                        $pdo->prepare('UPDATE usuarios SET ultimo_login = ? WHERE id = ?')
                            ->execute([date('Y-m-d H:i:s'), $usuario['id']]);

                        registrar_log($pdo, 'Login Bem-Sucedido', (int) $usuario['id'], "Usuário '" . $usuario['nome'] . "' (ID: " . $usuario['id'] . ') logou com sucesso.');
                        redirecionar(destino_seguro($redirect));
                    }
                } else {
                    $erro = 'E-mail ou senha incorretos.';
                    registrar_log($pdo, 'Falha no Login', $usuario ? (int) $usuario['id'] : null, "Tentativa de login falha para o e-mail: '" . $email . "'.");
                }
            }
        } catch (PDOException $e) {
            $ref = erro_interno('Login', $e);
            $erro = "Erro no servidor. Tente novamente em instantes (ref. $ref).";
        }
    }
}

$sistema = nome_sistema();
$b = BASE_URL;
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Entrar · <?php echo e($sistema); ?></title>
    <link rel="icon" type="image/png" href="<?php echo $b; ?>/images/logo_icone.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/vendor/fontawesome/css/all.min.css">
    <link rel="stylesheet" href="<?php echo $b; ?>/assets/css/app.css?v=<?php echo APP_VERSION; ?>">
</head>
<body>
<div class="auth">
    <aside class="auth-aside">
        <img src="<?php echo $b; ?>/images/logo.png" alt="Concessionária Reviver">
        <div>
            <h2><?php echo e($sistema); ?></h2>
            <p>Acompanhamento de processos judiciais, provisão de risco e performance financeira do departamento jurídico.</p>
        </div>
        <small>Acesso restrito a colaboradores autorizados.</small>
    </aside>
    <main class="auth-main">
        <div class="auth-card">
            <img src="<?php echo $b; ?>/images/logo.png" alt="Concessionária Reviver" class="auth-logo-mobile">
            <h1>Entrar</h1>
            <p>Use seu e-mail corporativo e sua senha.</p>

            <?php if ($erro !== ''): ?>
                <div class="alert alert-danger mb-4" role="alert"><i class="fa-solid fa-circle-exclamation"></i><div class="alert-body"><?php echo e($erro); ?></div></div>
            <?php endif; ?>
            <?php if ($sucesso !== ''): ?>
                <div class="alert alert-success mb-4" role="status"><i class="fa-solid fa-circle-check"></i><div class="alert-body"><?php echo e($sucesso); ?></div></div>
            <?php endif; ?>

            <form method="POST" action="login.php" novalidate>
                <?php echo csrf_field(); ?>
                <input type="hidden" name="redirect_url" value="<?php echo e($redirect); ?>">
                <div class="field">
                    <label for="email">E-mail</label>
                    <input class="input" type="email" id="email" name="email" value="<?php echo e($email); ?>" autocomplete="username" required <?php echo $email === '' ? 'autofocus' : ''; ?>>
                </div>
                <div class="field">
                    <div class="row" style="justify-content:space-between">
                        <label for="senha" class="label">Senha</label>
                        <a href="esqueci_senha.php" class="hint">Esqueci minha senha</a>
                    </div>
                    <div class="input-group">
                        <input class="input" type="password" id="senha" name="senha" autocomplete="current-password" required style="padding-left:12px;padding-right:44px" <?php echo $email !== '' ? 'autofocus' : ''; ?>>
                        <button type="button" class="btn btn-icon password-toggle" aria-label="Mostrar senha" data-toggle-password="senha"><i class="fa-regular fa-eye"></i></button>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary btn-lg btn-block mt-6" data-loading-text="Entrando…">Entrar</button>
            </form>
        </div>
    </main>
</div>
<script src="<?php echo $b; ?>/assets/js/app.js?v=<?php echo APP_VERSION; ?>"></script>
</body>
</html>
