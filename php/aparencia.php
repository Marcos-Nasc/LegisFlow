<?php
/**
 * Identidade visual: nome do sistema e logotipos (somente Administrador).
 *
 * O nome agora é gravado na tabela `configuracoes` (chave app_name) em vez de
 * reescrever o arquivo includes/config.php — isso eliminava o risco de um nome
 * com aspas derrubar o sistema ou injetar código.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
exigir_login();
exigir_nivel(NIVEL_ADMIN, 'Aparência');

$uid = usuario_id();
$erros = [];

/** Valida e grava uma imagem PNG enviada no campo informado. */
function salvar_png($campo, $destino, &$erro)
{
    if (!isset($_FILES[$campo]) || $_FILES[$campo]['error'] === UPLOAD_ERR_NO_FILE) {
        return false;
    }
    $f = $_FILES[$campo];
    if ($f['error'] !== UPLOAD_ERR_OK) { $erro = 'Falha no envio do arquivo (código ' . $f['error'] . ').'; return false; }
    if ($f['size'] > 2 * 1024 * 1024) { $erro = 'A imagem deve ter no máximo 2 MB.'; return false; }
    $info = @getimagesize($f['tmp_name']);
    if (!$info || $info[2] !== IMAGETYPE_PNG) { $erro = 'Envie uma imagem PNG válida.'; return false; }
    if (!is_writable(dirname($destino)) && !(file_exists($destino) && is_writable($destino))) { $erro = 'Sem permissão para gravar na pasta /images do servidor.'; return false; }
    // Mantém cópia da versão anterior
    if (file_exists($destino)) { @copy($destino, $destino . '.anterior.png'); }
    if (!move_uploaded_file($f['tmp_name'], $destino)) { $erro = 'Não foi possível salvar o arquivo. Verifique as permissões da pasta /images.'; return false; }
    return true;
}

if (is_post() && ($_POST['action'] ?? '') === 'salvar_identidade') {
    $nome = trim((string) ($_POST['app_name'] ?? ''));
    if ($nome === '') { $erros['app_name'] = 'Informe o nome do sistema.'; }
    elseif (mb_strlen($nome) > 80) { $erros['app_name'] = 'Use no máximo 80 caracteres.'; }

    if (!$erros) {
        $mudou = [];
        if ($nome !== nome_sistema()) {
            $anterior = nome_sistema();
            setSetting($pdo, 'app_name', $nome);
            registrar_log($pdo, 'Alteração Identidade (Nome)', $uid, "Nome do sistema alterado de '$anterior' para '$nome'.");
            $mudou[] = 'nome';
        }
        $erroLogo = '';
        if (salvar_png('logo', LF_ROOT . '/images/logo.png', $erroLogo)) {
            registrar_log($pdo, 'Alteração Identidade (Logo)', $uid, 'Nova logo (logo.png) salva com sucesso.');
            $mudou[] = 'logo';
        } elseif ($erroLogo) {
            $erros['logo'] = $erroLogo;
            registrar_log($pdo, 'Falha Alteração Identidade', $uid, 'Logo: ' . $erroLogo);
        }
        $erroIcone = '';
        if (salvar_png('logo_icone', LF_ROOT . '/images/logo_icone.png', $erroIcone)) {
            registrar_log($pdo, 'Alteração Identidade (Ícone)', $uid, 'Novo ícone (logo_icone.png) salvo com sucesso.');
            $mudou[] = 'ícone';
        } elseif ($erroIcone) {
            $erros['logo_icone'] = $erroIcone;
            registrar_log($pdo, 'Falha Alteração Identidade', $uid, 'Ícone: ' . $erroIcone);
        }
        if (!$erros) {
            flash('success', $mudou ? 'Identidade atualizada (' . implode(', ', $mudou) . ').' : 'Nenhuma alteração para salvar.');
            redirecionar('aparencia.php');
        }
    }
}

$v = '?t=' . @filemtime(LF_ROOT . '/images/logo.png');
$vi = '?t=' . @filemtime(LF_ROOT . '/images/logo_icone.png');
layout_inicio('Aparência', ['ativo' => 'aparencia']);
page_header('Aparência', 'Nome e logotipos exibidos no sistema, na tela de login e nos e-mails.');
?>
<section class="card" style="max-width:860px">
    <form method="POST" enctype="multipart/form-data" novalidate>
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="salvar_identidade">
        <div class="setting-row">
            <div><h3>Nome do sistema</h3><p>Aparece no título das abas, na tela de login e no cabeçalho mobile.</p></div>
            <div class="field<?php echo isset($erros['app_name']) ? ' has-error' : ''; ?>" style="min-width:min(320px,100%)">
                <input class="input" name="app_name" maxlength="80" value="<?php echo e($_POST['app_name'] ?? nome_sistema()); ?>" aria-label="Nome do sistema">
                <span class="field-error"><?php echo e($erros['app_name'] ?? ''); ?></span>
            </div>
        </div>
        <div class="setting-row">
            <div><h3>Logo completa</h3><p>PNG com fundo transparente e texto claro — é exibida sobre o fundo escuro do menu. Até 2 MB.</p>
                <div class="logo-preview mt-3"><img src="<?php echo BASE_URL; ?>/images/logo.png<?php echo $v; ?>" alt="Logo atual"></div></div>
            <div class="field<?php echo isset($erros['logo']) ? ' has-error' : ''; ?>" style="min-width:min(320px,100%)">
                <input class="input file-input" type="file" name="logo" accept="image/png" aria-label="Nova logo">
                <span class="field-error"><?php echo e($erros['logo'] ?? ''); ?></span>
            </div>
        </div>
        <div class="setting-row">
            <div><h3>Ícone</h3><p>Versão quadrada usada no menu recolhido, na aba do navegador e no celular.</p>
                <div class="logo-preview mt-3"><img src="<?php echo BASE_URL; ?>/images/logo_icone.png<?php echo $vi; ?>" alt="Ícone atual" style="height:40px"></div></div>
            <div class="field<?php echo isset($erros['logo_icone']) ? ' has-error' : ''; ?>" style="min-width:min(320px,100%)">
                <input class="input file-input" type="file" name="logo_icone" accept="image/png" aria-label="Novo ícone">
                <span class="field-error"><?php echo e($erros['logo_icone'] ?? ''); ?></span>
            </div>
        </div>
        <div class="card-footer row" style="justify-content:space-between">
            <span class="hint">O tema claro/escuro é escolhido por cada usuário no ícone de lua da barra superior.</span>
            <button type="submit" class="btn btn-primary">Salvar alterações</button>
        </div>
    </form>
</section>
<?php layout_fim();
