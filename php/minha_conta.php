<?php
/**
 * Minha conta: dados do usuário logado, troca de senha e preferência de tema.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
exigir_login();

$uid = usuario_id();
$erros = [];

if (is_post() && ($_POST['action'] ?? '') === 'alterar_senha') {
    $atual = (string) ($_POST['senha_atual'] ?? '');
    $nova = (string) ($_POST['senha_nova'] ?? '');
    $conf = (string) ($_POST['senha_confirmar'] ?? '');
    $stmt = $pdo->prepare('SELECT senha FROM usuarios WHERE id = ?');
    $stmt->execute([$uid]);
    $hash = (string) $stmt->fetchColumn();

    if (!password_verify($atual, $hash)) {
        $erros['senha_atual'] = 'Senha atual incorreta.';
    }
    if (($m = validar_senha_nova($nova)) !== null) {
        $erros['senha_nova'] = $m;
    } elseif ($nova !== $conf) {
        $erros['senha_confirmar'] = 'As senhas não conferem.';
    }
    if (!$erros) {
        $novoHash = password_hash($nova, PASSWORD_DEFAULT);
        $pdo->prepare('UPDATE usuarios SET senha = ? WHERE id = ?')->execute([$novoHash, $uid]);
        registrar_log($pdo, 'Alteração da Própria Senha', $uid, 'Usuário (ID: ' . $uid . ') alterou a própria senha.');
        session_regenerate_id(true);
        $_SESSION['_pw'] = substr(hash('sha256', $novoHash), 0, 16); // esta sessão continua; as demais são encerradas
        flash('success', 'Senha alterada. Outras sessões abertas serão encerradas.');
        redirecionar('minha_conta.php');
    }
}

$stmt = $pdo->prepare('SELECT nome, email, cargo, nivel, data_criacao, ultimo_login, preferencia_tema FROM usuarios WHERE id = ?');
$stmt->execute([$uid]);
$u = $stmt->fetch(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare('SELECT acao, data_ocorrencia, ip_usuario FROM logs WHERE id_usuario = ? ORDER BY data_ocorrencia DESC, id DESC LIMIT 8');
$stmt->execute([$uid]);
$atividades = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Último acesso ANTERIOR ao atual (gravado na sessão no momento do login)
$acessoAnterior = $_SESSION['ultimo_login'] ?? null;

layout_inicio('Minha conta', ['ativo' => '']);
page_header('Minha conta', 'Seus dados de acesso e preferências.');
?>
<div class="grid grid-main-side">
    <div class="stack">
        <section class="card">
            <div class="card-body row" style="gap:16px;align-items:center">
                <span class="avatar avatar-lg" style="background:<?php echo cor_avatar($u['nome']); ?>"><?php echo e(iniciais($u['nome'])); ?></span>
                <div class="grow">
                    <h2 style="font-size:18px"><?php echo e($u['nome']); ?></h2>
                    <div class="text-muted"><?php echo e($u['email']); ?></div>
                </div>
                <span class="badge badge-neutral badge-plain"><?php echo e(nome_nivel($u['nivel'])); ?></span>
            </div>
            <div class="card-footer">
                <dl class="details">
                    <div><dt>Cargo</dt><dd><?php echo e($u['cargo'] ?: '—'); ?></dd></div>
                    <div><dt>Conta criada em</dt><dd><?php echo data_br($u['data_criacao']); ?></dd></div>
                    <div><dt>Acesso anterior</dt><dd><?php echo $acessoAnterior ? data_br($acessoAnterior, true) : 'Primeiro acesso registrado'; ?></dd></div>
                    <div><dt>Permissões</dt><dd><?php
                        echo $u['nivel'] >= NIVEL_ADMIN ? 'Relatórios, gestão e administração'
                            : ($u['nivel'] >= NIVEL_EDITOR ? 'Relatórios e gestão de processos' : 'Consulta de relatórios'); ?></dd></div>
                </dl>
                <p class="hint mt-3">Para alterar nome, e-mail ou cargo, fale com um administrador.</p>
            </div>
        </section>

        <section class="card">
            <div class="card-header"><div><h2>Alterar senha</h2><p>Mínimo de 8 caracteres, com letras e números.</p></div></div>
            <form method="POST" class="card-body" autocomplete="off">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="alterar_senha">
                <div class="form-grid">
                    <div class="field full<?php echo isset($erros['senha_atual']) ? ' has-error' : ''; ?>">
                        <label for="senha_atual">Senha atual</label>
                        <input class="input" type="password" id="senha_atual" name="senha_atual" required autocomplete="current-password">
                        <span class="field-error"><?php echo e($erros['senha_atual'] ?? ''); ?></span>
                    </div>
                    <div class="field<?php echo isset($erros['senha_nova']) ? ' has-error' : ''; ?>">
                        <label for="senha_nova">Nova senha</label>
                        <input class="input" type="password" id="senha_nova" name="senha_nova" required minlength="8" autocomplete="new-password">
                        <span class="field-error"><?php echo e($erros['senha_nova'] ?? ''); ?></span>
                    </div>
                    <div class="field<?php echo isset($erros['senha_confirmar']) ? ' has-error' : ''; ?>">
                        <label for="senha_confirmar">Confirmar nova senha</label>
                        <input class="input" type="password" id="senha_confirmar" name="senha_confirmar" required autocomplete="new-password">
                        <span class="field-error"><?php echo e($erros['senha_confirmar'] ?? ''); ?></span>
                    </div>
                </div>
                <div class="row mt-4" style="justify-content:flex-end"><button type="submit" class="btn btn-primary">Alterar senha</button></div>
            </form>
        </section>
    </div>

    <div class="stack">
        <section class="card">
            <div class="setting-row">
                <div><h3>Tema escuro</h3><p>Fica salvo na sua conta e vale em qualquer dispositivo.</p></div>
                <label class="switch"><input type="checkbox" id="pref-tema" <?php echo $u['preferencia_tema'] === 'dark' ? 'checked' : ''; ?>><span class="slider"></span></label>
            </div>
        </section>
        <section class="card">
            <div class="card-header"><h2>Sua atividade recente</h2></div>
            <?php if ($atividades): ?>
            <ul class="list">
                <?php foreach ($atividades as $a): ?>
                <li class="list-item"><div class="list-body"><div class="list-title"><?php echo e($a['acao']); ?></div><div class="list-desc"><?php echo data_br($a['data_ocorrencia'], true); ?> · IP <?php echo e($a['ip_usuario']); ?></div></div></li>
                <?php endforeach; ?>
            </ul>
            <?php else: echo estado_vazio('Sem atividade registrada'); endif; ?>
        </section>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var t = document.getElementById('pref-tema');
    t.addEventListener('change', function () {
        if (t.checked !== document.documentElement.classList.contains('dark')) document.querySelector('[data-action="toggle-theme"]').click();
    });
});
</script>
<?php layout_fim();
