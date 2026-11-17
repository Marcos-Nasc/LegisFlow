<?php
/**
 * Gerador de hash de senha (utilitário técnico).
 * Antes ficava público, sem login; agora é restrito a administradores.
 * Para definir senhas de usuários, prefira a tela Administração > Usuários.
 */
require_once __DIR__ . '/includes/bootstrap.php';
exigir_login();
exigir_nivel(NIVEL_ADMIN, 'Gerador de Hash');

$hash = '';
if (is_post()) {
    $senha = (string) ($_POST['senha'] ?? '');
    if ($senha !== '') {
        $hash = password_hash($senha, PASSWORD_DEFAULT);
    }
}
layout_inicio('Gerador de hash');
page_header('Gerador de hash de senha', 'Utilitário técnico. Para criar ou alterar senhas de usuários, use <a href="' . BASE_URL . '/php/configuracoes_usuarios.php">Usuários</a>.');
?>
<section class="card" style="max-width:720px">
    <form method="POST" class="card-body stack" autocomplete="off">
        <?php echo csrf_field(); ?>
        <div class="field"><label for="senha">Senha</label><input class="input" type="password" id="senha" name="senha" required></div>
        <div><button class="btn btn-primary">Gerar hash</button></div>
        <?php if ($hash): ?><div class="field"><label>Hash gerado</label><div class="code" style="padding:10px"><?php echo e($hash); ?></div></div><?php endif; ?>
    </form>
</section>
<?php layout_fim();
