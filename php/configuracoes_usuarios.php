<?php
/**
 * Gerenciamento de usuários (somente Administrador).
 */
require_once __DIR__ . '/../includes/bootstrap.php';
exigir_login();
exigir_nivel(NIVEL_ADMIN, 'Gerenciamento de Usuários');

$adminId = usuario_id();
$erros = [];
$form = null;

/** Quantos administradores ativos existem (exceto o ID informado). */
function admins_ativos($pdo, $excetoId = 0)
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM usuarios WHERE nivel = 3 AND status = '1' AND id <> ?");
    $stmt->execute([(int) $excetoId]);
    return (int) $stmt->fetchColumn();
}

if (is_post()) {
    $acao = $_POST['action'] ?? '';
    $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: null;

    try {
        if ($acao === 'add' || $acao === 'edit') {
            $form = [
                'id' => $id,
                'action' => $acao,
                'nome' => trim((string) ($_POST['nome'] ?? '')),
                'email' => mb_strtolower(trim((string) ($_POST['email'] ?? ''))),
                'cargo' => trim((string) ($_POST['cargo'] ?? '')),
                'nivel' => (int) ($_POST['nivel'] ?? 1),
                'status' => ($_POST['status'] ?? '1') === '0' ? '0' : '1',
            ];
            $senha = (string) ($_POST['senha'] ?? '');

            if ($form['nome'] === '') { $erros['nome'] = 'Informe o nome.'; }
            elseif (mb_strlen($form['nome']) > 150) { $erros['nome'] = 'Máximo de 150 caracteres.'; }
            if (!filter_var($form['email'], FILTER_VALIDATE_EMAIL)) { $erros['email'] = 'Informe um e-mail válido.'; }
            if (mb_strlen($form['cargo']) > 50) { $erros['cargo'] = 'Máximo de 50 caracteres.'; }
            if (!in_array($form['nivel'], [1, 2, 3], true)) { $erros['nivel'] = 'Nível inválido.'; }
            if ($acao === 'add' || $senha !== '') {
                if (($m = validar_senha_nova($senha)) !== null) { $erros['senha'] = $m; }
            }
            if (!isset($erros['email'])) {
                $stmt = $pdo->prepare('SELECT id FROM usuarios WHERE email = ? AND id <> ?');
                $stmt->execute([$form['email'], (int) $id]);
                if ($stmt->fetchColumn()) { $erros['email'] = 'Já existe um usuário com este e-mail.'; }
            }
            if ($acao === 'edit') {
                $stmt = $pdo->prepare('SELECT nivel, status FROM usuarios WHERE id = ?');
                $stmt->execute([$id]);
                $atual = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$atual) {
                    flash('error', 'Usuário não encontrado.');
                    redirecionar('configuracoes_usuarios.php');
                }
                // Proteções: o próprio admin e o último administrador ativo
                if ($id === $adminId && ($form['nivel'] !== NIVEL_ADMIN || $form['status'] !== '1')) {
                    $erros['nivel'] = 'Você não pode rebaixar nem desativar a própria conta.';
                }
                $eraAdminAtivo = (int) $atual['nivel'] === 3 && (string) $atual['status'] === '1';
                $continuaAdminAtivo = $form['nivel'] === 3 && $form['status'] === '1';
                if ($eraAdminAtivo && !$continuaAdminAtivo && admins_ativos($pdo, $id) === 0) {
                    $erros['nivel'] = 'Este é o único administrador ativo. Promova outro usuário antes.';
                }
            }

            if (!$erros) {
                if ($acao === 'add') {
                    $pdo->prepare('INSERT INTO usuarios (nome, email, senha, cargo, nivel, status) VALUES (?, ?, ?, ?, ?, ?)')
                        ->execute([$form['nome'], $form['email'], password_hash($senha, PASSWORD_DEFAULT), $form['cargo'], $form['nivel'], $form['status']]);
                    $novo = (int) $pdo->lastInsertId();
                    registrar_log($pdo, 'Criação de Usuário (Sucesso)', $adminId, "Admin (ID: $adminId) criou o usuário ID $novo: {$form['nome']} (Email: {$form['email']}, Nível: {$form['nivel']}, Status: {$form['status']}).");
                    flash('success', 'Usuário ' . $form['nome'] . ' criado.');
                } else {
                    if ($senha !== '') {
                        $pdo->prepare('UPDATE usuarios SET nome = ?, email = ?, senha = ?, cargo = ?, nivel = ?, status = ? WHERE id = ?')
                            ->execute([$form['nome'], $form['email'], password_hash($senha, PASSWORD_DEFAULT), $form['cargo'], $form['nivel'], $form['status'], $id]);
                    } else {
                        $pdo->prepare('UPDATE usuarios SET nome = ?, email = ?, cargo = ?, nivel = ?, status = ? WHERE id = ?')
                            ->execute([$form['nome'], $form['email'], $form['cargo'], $form['nivel'], $form['status'], $id]);
                    }
                    registrar_log($pdo, 'Edição de Usuário (Sucesso)', $adminId, "Admin (ID: $adminId) editou o usuário ID $id. Novos dados: Nome: {$form['nome']}, Email: {$form['email']}, Nível: {$form['nivel']}, Status: {$form['status']}. " . ($senha !== '' ? '(Senha Alterada)' : '(Senha Mantida)'));
                    if ($id === $adminId) {
                        $_SESSION['nome_usuario'] = $form['nome'];
                        $_SESSION['cargo_usuario'] = $form['cargo'];
                    }
                    flash('success', 'Usuário ' . $form['nome'] . ' atualizado.');
                }
                redirecionar('configuracoes_usuarios.php');
            }
        }

        if ($acao === 'toggle_status' && $id) {
            $stmt = $pdo->prepare('SELECT nome, nivel, status FROM usuarios WHERE id = ?');
            $stmt->execute([$id]);
            $u = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$u) {
                flash('error', 'Usuário não encontrado.');
            } elseif ($id === $adminId) {
                flash('error', 'Você não pode desativar a própria conta.');
            } elseif ((string) $u['status'] === '1' && (int) $u['nivel'] === 3 && admins_ativos($pdo, $id) === 0) {
                flash('error', 'Este é o único administrador ativo e não pode ser desativado.');
            } else {
                $novo = (string) $u['status'] === '1' ? '0' : '1';
                $pdo->prepare('UPDATE usuarios SET status = ? WHERE id = ?')->execute([$novo, $id]);
                registrar_log($pdo, 'Edição de Usuário (Sucesso)', $adminId, "Admin (ID: $adminId) " . ($novo === '1' ? 'ativou' : 'desativou') . " o usuário ID $id ({$u['nome']}).");
                flash('success', $u['nome'] . ($novo === '1' ? ' foi reativado.' : ' foi desativado e não consegue mais entrar.'));
            }
            redirecionar('configuracoes_usuarios.php?' . http_build_query(array_intersect_key($_GET, ['busca' => 1, 'nivel' => 1, 'status' => 1])));
        }

        if ($acao === 'delete' && $id) {
            $stmt = $pdo->prepare('SELECT nome, email, nivel, status FROM usuarios WHERE id = ?');
            $stmt->execute([$id]);
            $u = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$u) {
                flash('error', 'Usuário não encontrado.');
            } elseif ($id === $adminId) {
                flash('error', 'Você não pode excluir a própria conta.');
            } elseif ((int) $u['nivel'] === 3 && (string) $u['status'] === '1' && admins_ativos($pdo, $id) === 0) {
                flash('error', 'Este é o único administrador ativo e não pode ser excluído.');
            } else {
                $pdo->prepare('DELETE FROM usuarios WHERE id = ?')->execute([$id]);
                registrar_log($pdo, 'Exclusão de Usuário (Sucesso)', $adminId, "Admin (ID: $adminId) excluiu o usuário: {$u['nome']} (Email: {$u['email']}, ID: $id).");
                flash('success', 'Usuário ' . $u['nome'] . ' excluído.');
            }
            redirecionar('configuracoes_usuarios.php');
        }
    } catch (PDOException $e) {
        $tipo = $acao === 'add' ? 'Criação de Usuário (Falha)' : ($acao === 'delete' ? 'Exclusão de Usuário (Falha)' : 'Edição de Usuário (Falha)');
        registrar_log($pdo, $tipo, $adminId, "Admin (ID: $adminId) falhou ao executar '$acao' (usuário ID " . ($id ?: 'novo') . '). Erro: ' . $e->getMessage());
        $ref = erro_interno('Usuários', $e);
        flash('error', "Não foi possível concluir a operação (ref. $ref).");
        redirecionar('configuracoes_usuarios.php');
    }
}

// Listagem
$busca = trim((string) ($_GET['busca'] ?? ''));
$fNivel = get_int('nivel', null, 1, 3);
$fStatus = in_array($_GET['status'] ?? '', ['0', '1'], true) ? $_GET['status'] : '';
$w = [];
$p = [];
if ($busca !== '') { $w[] = '(nome LIKE :b OR email LIKE :b OR cargo LIKE :b)'; $p[':b'] = "%$busca%"; }
if ($fNivel) { $w[] = 'nivel = :n'; $p[':n'] = $fNivel; }
if ($fStatus !== '') { $w[] = 'status = :s'; $p[':s'] = $fStatus; }
$stmt = $pdo->prepare('SELECT id, nome, email, cargo, nivel, status, ultimo_login, data_criacao FROM usuarios' . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY status DESC, nome');
$stmt->execute($p);
$usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
$contagem = $pdo->query("SELECT COUNT(*) AS total, SUM(CASE WHEN status='1' THEN 1 ELSE 0 END) AS ativos, SUM(CASE WHEN nivel=3 AND status='1' THEN 1 ELSE 0 END) AS admins FROM usuarios")->fetch(PDO::FETCH_ASSOC);

$nivelBadge = [1 => 'neutral', 2 => 'info', 3 => 'warning'];

layout_inicio('Usuários', ['ativo' => 'usuarios']);
page_header('Usuários', numero($contagem['ativos']) . ' ativos de ' . numero($contagem['total']) . ' cadastrados · ' . numero($contagem['admins']) . ' administrador(es).',
    '<button type="button" class="btn btn-primary" id="btn-novo-usuario"><i class="fa-solid fa-user-plus"></i>Novo usuário</button>');
?>
<section class="card">
    <form method="GET" class="toolbar">
        <div class="field grow"><label for="busca">Buscar</label>
            <div class="input-group"><i class="fa-solid fa-magnifying-glass prefix"></i><input type="search" class="input input-sm" id="busca" name="busca" value="<?php echo e($busca); ?>" placeholder="Nome, e-mail ou cargo"></div></div>
        <div class="field"><label for="f-nivel">Nível</label>
            <select class="select select-sm" id="f-nivel" name="nivel" data-autosubmit><option value="">Todos</option><?php foreach ([1, 2, 3] as $n): ?><option value="<?php echo $n; ?>" <?php echo $fNivel === $n ? 'selected' : ''; ?>><?php echo nome_nivel($n); ?></option><?php endforeach; ?></select></div>
        <div class="field"><label for="f-status">Status</label>
            <select class="select select-sm" id="f-status" name="status" data-autosubmit><option value="">Todos</option><option value="1" <?php echo $fStatus === '1' ? 'selected' : ''; ?>>Ativos</option><option value="0" <?php echo $fStatus === '0' ? 'selected' : ''; ?>>Inativos</option></select></div>
        <div class="toolbar-actions"><button class="btn btn-secondary btn-sm" data-no-loading="1">Filtrar</button><?php if ($busca || $fNivel || $fStatus !== ''): ?><a class="btn btn-ghost btn-sm" href="configuracoes_usuarios.php">Limpar</a><?php endif; ?></div>
    </form>
    <?php if ($usuarios): ?>
    <div class="table-wrap">
        <table class="table table-cards">
            <thead><tr><th>Usuário</th><th>Cargo</th><th>Nível</th><th>Status</th><th>Último acesso</th><th><span class="sr-only">Ações</span></th></tr></thead>
            <tbody>
            <?php foreach ($usuarios as $u): $proprio = (int) $u['id'] === $adminId; ?>
                <tr<?php echo (string) $u['status'] !== '1' ? ' style="opacity:.7"' : ''; ?>>
                    <td class="card-title">
                        <div class="row" style="flex-wrap:nowrap;gap:10px">
                            <span class="avatar" style="background:<?php echo cor_avatar($u['nome']); ?>"><?php echo e(iniciais($u['nome'])); ?></span>
                            <span style="min-width:0"><span class="cell-strong"><?php echo e($u['nome']); ?></span><?php echo $proprio ? ' <span class="badge badge-neutral badge-plain">você</span>' : ''; ?><span class="cell-sub"><?php echo e($u['email']); ?></span></span>
                        </div>
                    </td>
                    <td data-label="Cargo"><?php echo $u['cargo'] ? e($u['cargo']) : '<span class="text-muted">—</span>'; ?></td>
                    <td data-label="Nível"><span class="badge badge-plain badge-<?php echo $nivelBadge[(int) $u['nivel']] ?? 'neutral'; ?>"><?php echo e(nome_nivel($u['nivel'])); ?></span></td>
                    <td data-label="Status"><?php echo (string) $u['status'] === '1' ? '<span class="badge badge-success">Ativo</span>' : '<span class="badge badge-danger">Inativo</span>'; ?></td>
                    <td data-label="Último acesso" class="nowrap"><?php echo $u['ultimo_login'] ? data_br($u['ultimo_login'], true) : '<span class="text-muted">Nunca registrado</span>'; ?></td>
                    <td class="actions">
                        <button type="button" class="btn btn-icon" data-editar="<?php echo e(json_encode(['id' => (int) $u['id'], 'nome' => $u['nome'], 'email' => $u['email'], 'cargo' => $u['cargo'], 'nivel' => (int) $u['nivel'], 'status' => (string) $u['status'], 'proprio' => $proprio], JSON_UNESCAPED_UNICODE)); ?>" title="Editar" aria-label="Editar <?php echo e($u['nome']); ?>"><i class="fa-regular fa-pen-to-square"></i></button>
                        <?php if (!$proprio): ?>
                        <form method="POST" style="display:inline" data-confirm="<?php echo e((string) $u['status'] === '1' ? $u['nome'] . ' não conseguirá mais entrar no sistema. O histórico é mantido.' : $u['nome'] . ' voltará a ter acesso ao sistema.'); ?>" data-confirm-title="<?php echo (string) $u['status'] === '1' ? 'Desativar usuário?' : 'Reativar usuário?'; ?>" data-confirm-button="<?php echo (string) $u['status'] === '1' ? 'Desativar' : 'Reativar'; ?>" data-confirm-tone="warning">
                            <?php echo csrf_field(); ?><input type="hidden" name="action" value="toggle_status"><input type="hidden" name="id" value="<?php echo (int) $u['id']; ?>">
                            <button class="btn btn-icon" data-no-loading="1" title="<?php echo (string) $u['status'] === '1' ? 'Desativar' : 'Reativar'; ?>" aria-label="<?php echo (string) $u['status'] === '1' ? 'Desativar' : 'Reativar'; ?>"><i class="fa-solid <?php echo (string) $u['status'] === '1' ? 'fa-user-slash' : 'fa-user-check'; ?>"></i></button>
                        </form>
                        <form method="POST" style="display:inline" data-confirm="<?php echo e('A conta de ' . $u['nome'] . ' será removida definitivamente. Os registros de log ficarão sem autor. Para apenas bloquear o acesso, prefira desativar.'); ?>" data-confirm-title="Excluir usuário?" data-confirm-button="Excluir">
                            <?php echo csrf_field(); ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo (int) $u['id']; ?>">
                            <button class="btn btn-icon danger" data-no-loading="1" title="Excluir" aria-label="Excluir"><i class="fa-regular fa-trash-can"></i></button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: echo estado_vazio('Nenhum usuário encontrado', 'Ajuste os filtros de busca.', 'fa-users'); endif; ?>
</section>

<section class="card mt-6">
    <div class="card-header"><h2>O que cada nível pode fazer</h2></div>
    <div class="table-wrap"><table class="table table-compact">
        <thead><tr><th>Nível</th><th>Acesso</th></tr></thead>
        <tbody>
            <tr><td><strong>Usuário</strong></td><td>Dashboard e relatórios (Performance Financeira, Provisão de Risco, Volume de Entrada).</td></tr>
            <tr><td><strong>Editor</strong></td><td>Tudo do Usuário + cadastro, edição, exclusão, importação e exportação de processos e totais mensais.</td></tr>
            <tr><td><strong>Administrador</strong></td><td>Tudo do Editor + usuários, logs, e-mail, aparência, manutenção e banco de dados. Único nível que acessa o sistema em modo manutenção.</td></tr>
        </tbody>
    </table></div>
</section>

<div class="modal-backdrop" id="modal-usuario" data-dismissable="false">
    <form class="modal" method="POST" id="form-usuario" novalidate>
        <div class="modal-header">
            <div><h2 id="titulo-usuario">Novo usuário</h2><p id="sub-usuario">O usuário entra com o e-mail e a senha definidos aqui.</p></div>
            <button type="button" class="btn btn-icon" data-action="modal-close" aria-label="Fechar"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-body">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" id="u-action" value="<?php echo e($form['action'] ?? 'add'); ?>">
            <input type="hidden" name="id" id="u-id" value="<?php echo e($form['id'] ?? ''); ?>">
            <div class="form-grid">
                <div class="field full<?php echo isset($erros['nome']) ? ' has-error' : ''; ?>"><label for="u-nome">Nome<span class="req">*</span></label><input class="input" id="u-nome" name="nome" maxlength="150" required value="<?php echo e($form['nome'] ?? ''); ?>"><span class="field-error"><?php echo e($erros['nome'] ?? ''); ?></span></div>
                <div class="field full<?php echo isset($erros['email']) ? ' has-error' : ''; ?>"><label for="u-email">E-mail<span class="req">*</span></label><input class="input" type="email" id="u-email" name="email" required autocomplete="off" value="<?php echo e($form['email'] ?? ''); ?>"><span class="field-error"><?php echo e($erros['email'] ?? ''); ?></span></div>
                <div class="field full<?php echo isset($erros['senha']) ? ' has-error' : ''; ?>"><label for="u-senha">Senha<span class="req" id="senha-req">*</span></label>
                    <div class="input-group"><input class="input" type="password" id="u-senha" name="senha" autocomplete="new-password" style="padding-left:12px;padding-right:44px"><button type="button" class="btn btn-icon password-toggle" data-toggle-password="u-senha" aria-label="Mostrar senha"><i class="fa-regular fa-eye"></i></button></div>
                    <span class="hint" id="senha-hint">Mínimo de 8 caracteres, com letras e números.</span><span class="field-error"><?php echo e($erros['senha'] ?? ''); ?></span></div>
                <div class="field full<?php echo isset($erros['cargo']) ? ' has-error' : ''; ?>"><label for="u-cargo">Cargo</label><input class="input" id="u-cargo" name="cargo" maxlength="50" value="<?php echo e($form['cargo'] ?? ''); ?>"><span class="field-error"><?php echo e($erros['cargo'] ?? ''); ?></span></div>
                <div class="field<?php echo isset($erros['nivel']) ? ' has-error' : ''; ?>"><label for="u-nivel">Nível de acesso</label>
                    <select class="select" id="u-nivel" name="nivel"><?php foreach ([1, 2, 3] as $n): ?><option value="<?php echo $n; ?>" <?php echo (int) ($form['nivel'] ?? 1) === $n ? 'selected' : ''; ?>><?php echo nome_nivel($n); ?></option><?php endforeach; ?></select><span class="field-error"><?php echo e($erros['nivel'] ?? ''); ?></span></div>
                <div class="field"><label for="u-status">Status</label>
                    <select class="select" id="u-status" name="status"><option value="1" <?php echo ($form['status'] ?? '1') === '1' ? 'selected' : ''; ?>>Ativo</option><option value="0" <?php echo ($form['status'] ?? '1') === '0' ? 'selected' : ''; ?>>Inativo</option></select></div>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-action="modal-close">Cancelar</button>
            <button type="submit" class="btn btn-primary" id="u-salvar">Salvar</button>
        </div>
    </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var f = document.getElementById('form-usuario');
    function preparar(u) {
        var edit = !!u;
        if (!f.dataset.keep) {
            f.reset();
            f.querySelectorAll('.has-error').forEach(function (x) { x.classList.remove('has-error'); });
        }
        document.getElementById('u-action').value = edit ? 'edit' : 'add';
        document.getElementById('u-id').value = edit ? u.id : '';
        document.getElementById('titulo-usuario').textContent = edit ? 'Editar usuário' : 'Novo usuário';
        document.getElementById('sub-usuario').textContent = edit ? 'Deixe a senha em branco para manter a atual.' : 'O usuário entra com o e-mail e a senha definidos aqui.';
        document.getElementById('senha-req').style.display = edit ? 'none' : '';
        document.getElementById('u-senha').placeholder = edit ? 'Manter senha atual' : '';
        if (edit && !f.dataset.keep) {
            f.nome.value = u.nome; f.email.value = u.email; f.cargo.value = u.cargo || ''; f.nivel.value = u.nivel; f.status.value = u.status;
        }
        f.nivel.disabled = edit && u.proprio; f.status.disabled = edit && u.proprio;
        delete f.dataset.keep;
        LF.openModal('modal-usuario');
    }
    document.getElementById('btn-novo-usuario').addEventListener('click', function () { preparar(null); });
    document.querySelectorAll('[data-editar]').forEach(function (b) {
        b.addEventListener('click', function () { preparar(JSON.parse(b.dataset.editar)); });
    });
    // Campos desabilitados não são enviados: reenvia o valor do próprio admin
    f.addEventListener('submit', function () {
        if (f.nivel.disabled) { f.nivel.disabled = false; f.status.disabled = false; }
    });
    <?php if ($erros): ?>
    f.dataset.keep = '1';
    preparar(<?php echo $form['action'] === 'edit' ? json_encode(['id' => $form['id'], 'proprio' => $form['id'] === $adminId], JSON_HEX_TAG) : 'null'; ?>);
    <?php endif; ?>
});
</script>
<?php layout_fim(['ativo' => 'usuarios']);
