<?php
/**
 * Modo de manutenção (somente Administrador).
 * Mecanismo original preservado: arquivo maintenance.flag na raiz do sistema.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
exigir_login();
exigir_nivel(NIVEL_ADMIN, 'Modo de Manutenção');

$flag = arquivo_manutencao();

if (is_post()) {
    $acao = $_POST['action'] ?? '';
    if ($acao === 'ativar') {
        if (!file_exists($flag) && @touch($flag) === false) {
            flash('error', 'Não foi possível ativar: sem permissão para criar o arquivo maintenance.flag na raiz do sistema.');
        } else {
            registrar_log($pdo, 'Modo Manutenção Ativado', usuario_id(), 'Sistema entrou em modo de manutenção.');
            flash('warning', 'Modo de manutenção ativado. Somente administradores conseguem usar o sistema.');
        }
    } elseif ($acao === 'desativar') {
        if (file_exists($flag) && @unlink($flag) === false) {
            flash('error', 'Não foi possível desativar: sem permissão para remover o arquivo maintenance.flag.');
        } else {
            registrar_log($pdo, 'Modo Manutenção Desativado', usuario_id(), 'Sistema saiu do modo de manutenção.');
            flash('success', 'Modo de manutenção desativado. O sistema voltou a ficar disponível para todos.');
        }
    }
    redirecionar('manutencao.php');
}

if (isset($_GET['previa'])) {
    pagina_manutencao(); // pré-visualização da página exibida aos usuários
}

$ativo = modo_manutencao_ativo();
$desde = $ativo ? @filemtime($flag) : null;

layout_inicio('Manutenção', ['ativo' => 'manutencao']);
page_header('Modo de manutenção', 'Bloqueia o acesso de usuários e editores enquanto você faz atualizações.');
?>
<section class="card" style="max-width:860px">
    <div class="setting-row">
        <div>
            <div class="row"><span class="status-dot <?php echo $ativo ? 'on' : 'off'; ?>" style="<?php echo $ativo ? 'background:var(--warning);box-shadow:0 0 0 3px var(--warning-soft)' : ''; ?>"></span>
                <h3><?php echo $ativo ? 'Manutenção ativa' : 'Sistema disponível para todos'; ?></h3></div>
            <p><?php echo $ativo
                ? 'Ativado em ' . date('d/m/Y \à\s H:i', $desde) . '. Usuários e editores veem a página “Sistema em manutenção”; administradores continuam acessando normalmente.'
                : 'Ao ativar, usuários e editores conectados verão a página de manutenção no próximo clique. Administradores continuam com acesso.'; ?></p>
        </div>
        <form method="POST" data-confirm="<?php echo $ativo ? 'Todos os usuários voltarão a ter acesso ao sistema.' : 'Usuários e editores perderão o acesso imediatamente, inclusive quem estiver com o sistema aberto.'; ?>"
              data-confirm-title="<?php echo $ativo ? 'Desativar manutenção?' : 'Ativar manutenção?'; ?>" data-confirm-button="<?php echo $ativo ? 'Desativar' : 'Ativar manutenção'; ?>" data-confirm-tone="warning">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="<?php echo $ativo ? 'desativar' : 'ativar'; ?>">
            <button type="submit" class="btn <?php echo $ativo ? 'btn-primary' : 'btn-secondary'; ?>"><?php echo $ativo ? '<i class="fa-solid fa-play"></i>Desativar manutenção' : '<i class="fa-solid fa-screwdriver-wrench"></i>Ativar manutenção'; ?></button>
        </form>
    </div>
    <div class="card-footer"><a href="<?php echo BASE_URL; ?>/php/manutencao.php?previa=1" target="_blank" rel="noopener" class="hint" id="link-previa"><i class="fa-regular fa-eye"></i> Ver a página que os usuários recebem</a></div>
</section>
<?php layout_fim();
