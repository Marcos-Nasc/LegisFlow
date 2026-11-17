<?php
/**
 * Autenticação, autorização, CSRF e modo de manutenção.
 */

if (defined('LF_AUTH_CARREGADO')) {
    return;
}
define('LF_AUTH_CARREGADO', true);

/* =========================================================================
 * CSRF
 * ========================================================================= */

function csrf_token()
{
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}

function csrf_field()
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_valido()
{
    $enviado = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    return is_string($enviado) && $enviado !== '' && !empty($_SESSION['_csrf'])
        && hash_equals($_SESSION['_csrf'], $enviado);
}

/** Interrompe a requisição POST se o token CSRF for inválido. */
function exigir_csrf()
{
    if (!is_post() || csrf_valido()) {
        return;
    }
    $msg = 'Sua sessão expirou ou o formulário ficou aberto por muito tempo. Recarregue a página e tente novamente.';
    if (is_ajax() || !empty($_SERVER['HTTP_X_CSRF_TOKEN'])) {
        responder_json(['status' => 'error', 'message' => $msg], 419);
    }
    http_response_code(419);
    pagina_mensagem('Sessão expirada', $msg, 'fa-clock-rotate-left', 'Voltar', $_SERVER['HTTP_REFERER'] ?? (BASE_URL . '/index.php'));
}

/* =========================================================================
 * USUÁRIO LOGADO
 * ========================================================================= */

function usuario_logado()
{
    return !empty($_SESSION['user_id']);
}

function usuario_id()
{
    return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
}

function usuario_nivel()
{
    return isset($_SESSION['user_nivel']) ? (int) $_SESSION['user_nivel'] : 0;
}

function pode($nivelMinimo)
{
    return usuario_nivel() >= (int) $nivelMinimo;
}

/**
 * Valida a sessão: modo de manutenção, login e usuário ainda ativo.
 * Comportamento equivalente ao antigo includes/verifica_login.php.
 */
function exigir_login()
{
    global $pdo;

    // --- Modo de manutenção: somente administradores navegam ---
    if (modo_manutencao_ativo() && usuario_nivel() !== NIVEL_ADMIN) {
        pagina_manutencao();
    }

    if (!usuario_logado()) {
        if (is_ajax()) {
            responder_json(['status' => 'error', 'message' => 'Sua sessão expirou. Faça login novamente.'], 401);
        }
        $destino = $_SERVER['REQUEST_URI'] ?? '';
        session_unset();
        redirecionar(BASE_URL . '/php/login.php?erro=acesso_restrito&redirect_url=' . urlencode($destino));
    }

    // Revalida o usuário no banco periodicamente (a cada 60s): se foi desativado,
    // excluído ou teve o nível alterado, a sessão é atualizada/encerrada.
    $agora = time();
    if (empty($_SESSION['_revalidado']) || $agora - (int) $_SESSION['_revalidado'] > 60) {
        try {
            $stmt = $pdo->prepare('SELECT nome, cargo, nivel, status, senha FROM usuarios WHERE id = ?');
            $stmt->execute([usuario_id()]);
            $u = $stmt->fetch(PDO::FETCH_ASSOC);
            $assinatura = substr(hash('sha256', (string) ($u['senha'] ?? '')), 0, 16);
            if ($u && isset($_SESSION['_pw']) && !hash_equals($_SESSION['_pw'], $assinatura)) {
                // Senha alterada em outro lugar: encerra esta sessão
                session_unset();
                session_destroy();
                redirecionar(BASE_URL . '/php/login.php?erro=acesso_restrito');
            }
            $_SESSION['_pw'] = $assinatura;
            if (!$u || (string) $u['status'] !== '1') {
                session_unset();
                session_destroy();
                redirecionar(BASE_URL . '/php/login.php?erro=conta_inativa');
            }
            $_SESSION['user_nivel'] = (int) $u['nivel'];
            $_SESSION['nome_usuario'] = $u['nome'];
            $_SESSION['cargo_usuario'] = $u['cargo'];
            $_SESSION['_revalidado'] = $agora;
        } catch (PDOException $e) {
            error_log('[LegisFlow] Falha ao revalidar sessão: ' . $e->getMessage());
        }
    }

    exigir_csrf();
}

/**
 * Exige nível mínimo de acesso. Registra a tentativa negada no log
 * (comportamento original) e exibe página de acesso negado.
 */
function exigir_nivel($nivelMinimo, $nomePagina)
{
    global $pdo;
    if (pode($nivelMinimo)) {
        return;
    }
    registrar_log($pdo, 'Tentativa de acesso não autorizado', usuario_id(), 'Página: ' . $nomePagina);
    if (is_ajax()) {
        responder_json(['status' => 'error', 'message' => 'Você não tem permissão para esta ação.'], 403);
    }
    http_response_code(403);
    $msg = $nivelMinimo >= NIVEL_ADMIN
        ? 'Esta área é restrita a administradores.'
        : 'Você não tem permissão para acessar esta página. Se precisar de acesso, fale com um administrador.';
    pagina_mensagem('Acesso negado', $msg, 'fa-lock', 'Ir para o Dashboard', BASE_URL . '/index.php');
}

/* =========================================================================
 * MODO DE MANUTENÇÃO (arquivo maintenance.flag na raiz — mecanismo original)
 * ========================================================================= */

function arquivo_manutencao()
{
    return LF_ROOT . '/maintenance.flag';
}

function modo_manutencao_ativo()
{
    return file_exists(arquivo_manutencao());
}

function pagina_manutencao()
{
    http_response_code(503);
    header('Retry-After: 1800');
    pagina_mensagem(
        'Sistema em manutenção',
        'Estamos realizando atualizações no momento. O sistema estará disponível novamente em breve. Agradecemos a sua paciência.',
        'fa-screwdriver-wrench',
        null,
        null,
        true
    );
}

/* =========================================================================
 * PÁGINA DE MENSAGEM (erro, acesso negado, manutenção)
 * ========================================================================= */

function pagina_mensagem($titulo, $mensagem, $icone = 'fa-circle-info', $botaoTexto = null, $botaoUrl = null, $mostrarLogin = false)
{
    $tema = (($_SESSION['tema'] ?? 'light') === 'dark') ? 'dark' : '';
    $base = BASE_URL;
    $versao = APP_VERSION;
    ?><!DOCTYPE html>
<html lang="pt-br" class="<?php echo $tema; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo e($titulo); ?> · <?php echo e(nome_sistema()); ?></title>
    <link rel="icon" type="image/png" href="<?php echo $base; ?>/images/logo_icone.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/vendor/fontawesome/css/all.min.css">
    <link rel="stylesheet" href="<?php echo $base; ?>/assets/css/app.css?v=<?php echo $versao; ?>">
</head>
<body class="page-center">
    <div class="message-card">
        <div class="message-icon"><i class="fa-solid <?php echo e($icone); ?>"></i></div>
        <h1><?php echo e($titulo); ?></h1>
        <p><?php echo e($mensagem); ?></p>
        <div class="message-actions">
            <?php if ($botaoTexto && $botaoUrl): ?>
                <a class="btn btn-primary" href="<?php echo e($botaoUrl); ?>"><?php echo e($botaoTexto); ?></a>
            <?php endif; ?>
            <?php if ($mostrarLogin): ?>
                <a class="btn btn-ghost" href="<?php echo $base; ?>/php/login.php">Acesso de administrador</a>
            <?php endif; ?>
        </div>
    </div>
</body>
</html><?php
    exit();
}

/* =========================================================================
 * REDEFINIÇÃO DE SENHA (token assinado, sem tabela extra)
 *
 * O token carrega: id do usuário, validade e uma assinatura HMAC que inclui o
 * hash atual da senha. Assim que a senha é trocada, o link deixa de valer.
 * ========================================================================= */

function gerar_token_redefinicao($pdo, $usuario, $validadeSegundos = 3600)
{
    $expira = time() + $validadeSegundos;
    $dados = $usuario['id'] . '|' . $expira;
    $assinatura = hash_hmac('sha256', $dados . '|' . $usuario['senha'], app_secret($pdo));
    return rtrim(strtr(base64_encode($dados . '|' . $assinatura), '+/', '-_'), '=');
}

/** Retorna o usuário se o token for válido, ou null. */
function validar_token_redefinicao($pdo, $token)
{
    $bruto = base64_decode(strtr((string) $token, '-_', '+/'), true);
    if ($bruto === false) {
        return null;
    }
    $partes = explode('|', $bruto);
    if (count($partes) !== 3) {
        return null;
    }
    list($id, $expira, $assinatura) = $partes;
    if (!ctype_digit($id) || !ctype_digit($expira) || (int) $expira < time()) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT id, nome, email, senha, status FROM usuarios WHERE id = ?');
    $stmt->execute([(int) $id]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$u || (string) $u['status'] !== '1') {
        return null;
    }
    $esperado = hash_hmac('sha256', $id . '|' . $expira . '|' . $u['senha'], app_secret($pdo));
    return hash_equals($esperado, $assinatura) ? $u : null;
}

/** Regras mínimas de senha. Retorna mensagem de erro ou null. */
function validar_senha_nova($senha)
{
    if (mb_strlen($senha) < 8) {
        return 'A senha deve ter pelo menos 8 caracteres.';
    }
    if (!preg_match('/[A-Za-z]/', $senha) || !preg_match('/\d/', $senha)) {
        return 'A senha deve conter letras e números.';
    }
    return null;
}
