<?php
/**
 * Configurações de e-mail (SMTP) — somente Administrador.
 * Mesmas chaves de antes na tabela `configuracoes`.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
exigir_login();
exigir_nivel(NIVEL_ADMIN, 'Configurações Gerais (SMTP)');
require_once __DIR__ . '/../includes/email.php';

$uid = usuario_id();
$cfg = smtp_config($pdo);
$erros = [];

if (is_post()) {
    $acao = $_POST['action'] ?? '';
    $novo = [
        'host' => trim((string) ($_POST['smtp_host'] ?? '')),
        'port' => trim((string) ($_POST['smtp_port'] ?? '')),
        'user' => trim((string) ($_POST['smtp_user'] ?? '')),
        'pass' => (string) ($_POST['smtp_pass'] ?? ''),
        'from_name' => trim((string) ($_POST['smtp_from_name'] ?? '')),
        'from_email' => trim((string) ($_POST['smtp_from_email'] ?? '')),
    ];
    if ($novo['host'] === '') { $erros['smtp_host'] = 'Informe o servidor SMTP.'; }
    if (!ctype_digit($novo['port']) || (int) $novo['port'] < 1 || (int) $novo['port'] > 65535) { $erros['smtp_port'] = 'Porta inválida.'; }
    if ($novo['from_name'] === '') { $erros['smtp_from_name'] = 'Informe o nome do remetente.'; }
    if (!filter_var($novo['from_email'], FILTER_VALIDATE_EMAIL)) { $erros['smtp_from_email'] = 'Informe um e-mail válido.'; }

    if (!$erros && $acao === 'save_email_settings') {
        try {
            foreach (['host', 'port', 'user', 'from_name', 'from_email'] as $c) { setSetting($pdo, 'smtp_' . $c, $novo[$c]); }
            $info = '(Mantida)';
            if ($novo['pass'] !== '') { setSetting($pdo, 'smtp_pass', $novo['pass']); $info = '(Alterada)'; }
            registrar_log($pdo, 'Configurações SMTP Salvas', $uid, "Configurações de SMTP atualizadas. Host: {$novo['host']}, Porta: {$novo['port']}, Usuário: {$novo['user']}, Senha: $info, Remetente: {$novo['from_name']} <{$novo['from_email']}>");
            flash('success', 'Configurações de e-mail salvas.');
            redirecionar('configuracoes_gerais.php');
        } catch (PDOException $e) {
            registrar_log($pdo, 'Falha ao Salvar SMTP', $uid, 'Erro PDO: ' . $e->getMessage());
            $ref = erro_interno('Salvar SMTP', $e);
            flash('error', "Não foi possível salvar (ref. $ref).");
        }
    }

    if (!$erros && $acao === 'test_email_settings') {
        // Testa com os valores do formulário (senha em branco = usa a salva), sem gravar
        $teste = $novo;
        if ($teste['pass'] === '') { $teste['pass'] = $cfg['pass']; }
        $stmt = $pdo->prepare('SELECT email, nome FROM usuarios WHERE id = ?');
        $stmt->execute([$uid]);
        $admin = $stmt->fetch(PDO::FETCH_ASSOC);
        try {
            $mail = criar_mailer($teste);
            $mail->addAddress($admin['email'], $admin['nome']);
            $mail->isHTML(true);
            $mail->Subject = 'Teste de configuração de e-mail — ' . nome_sistema();
            $mail->Body = email_template('Teste de e-mail', '<p>Se você recebeu esta mensagem, o envio de e-mails do sistema está funcionando.</p>');
            $mail->AltBody = 'Se você recebeu esta mensagem, o envio de e-mails do sistema está funcionando.';
            $mail->send();
            registrar_log($pdo, 'Teste SMTP (Sucesso)', $uid, 'E-mail de teste enviado com sucesso para: ' . $admin['email']);
            flash('success', 'E-mail de teste enviado para ' . $admin['email'] . '. Lembre-se de salvar as configurações.');
        } catch (\Throwable $e) {
            $msg = isset($mail) && $mail->ErrorInfo ? $mail->ErrorInfo : $e->getMessage();
            registrar_log($pdo, 'Teste SMTP (Falha)', $uid, 'Falha ao enviar e-mail de teste para: ' . $admin['email'] . '. Erro: ' . $msg);
            flash('error', 'O e-mail de teste não pôde ser enviado: ' . $msg);
        }
    }
    $cfg = array_merge($cfg, array_diff_key($novo, ['pass' => 1]));
}

$configurado = smtp_configurado($pdo);
layout_inicio('E-mail (SMTP)', ['ativo' => 'gerais']);
page_header('E-mail (SMTP)', 'Servidor usado para enviar e-mails do sistema, como a recuperação de senha.');
?>
<div class="grid grid-main-side">
    <section class="card">
        <form method="POST" novalidate>
            <?php echo csrf_field(); ?>
            <div class="card-body">
                <div class="form-grid">
                    <div class="form-section">Servidor</div>
                    <div class="field<?php echo isset($erros['smtp_host']) ? ' has-error' : ''; ?>"><label for="smtp_host">Servidor SMTP<span class="req">*</span></label><input class="input" id="smtp_host" name="smtp_host" value="<?php echo e($cfg['host']); ?>" placeholder="smtp.seudominio.com.br"><span class="field-error"><?php echo e($erros['smtp_host'] ?? ''); ?></span></div>
                    <div class="field<?php echo isset($erros['smtp_port']) ? ' has-error' : ''; ?>"><label for="smtp_port">Porta<span class="req">*</span></label><input class="input" id="smtp_port" name="smtp_port" inputmode="numeric" value="<?php echo e($cfg['port']); ?>" placeholder="587"><span class="hint">587 usa STARTTLS; 465 usa SSL/TLS.</span><span class="field-error"><?php echo e($erros['smtp_port'] ?? ''); ?></span></div>
                    <div class="field"><label for="smtp_user">Usuário</label><input class="input" id="smtp_user" name="smtp_user" value="<?php echo e($cfg['user']); ?>" autocomplete="off"></div>
                    <div class="field"><label for="smtp_pass">Senha</label>
                        <div class="input-group"><input class="input" type="password" id="smtp_pass" name="smtp_pass" autocomplete="new-password" placeholder="<?php echo $cfg['pass'] !== '' ? 'Manter a senha salva' : ''; ?>" style="padding-left:12px;padding-right:44px"><button type="button" class="btn btn-icon password-toggle" data-toggle-password="smtp_pass" aria-label="Mostrar senha"><i class="fa-regular fa-eye"></i></button></div>
                        <span class="hint">Deixe em branco para manter a atual.</span></div>
                    <div class="form-section">Remetente</div>
                    <div class="field<?php echo isset($erros['smtp_from_name']) ? ' has-error' : ''; ?>"><label for="smtp_from_name">Nome<span class="req">*</span></label><input class="input" id="smtp_from_name" name="smtp_from_name" value="<?php echo e($cfg['from_name']); ?>" placeholder="Jurídico Reviver"><span class="field-error"><?php echo e($erros['smtp_from_name'] ?? ''); ?></span></div>
                    <div class="field<?php echo isset($erros['smtp_from_email']) ? ' has-error' : ''; ?>"><label for="smtp_from_email">E-mail<span class="req">*</span></label><input class="input" type="email" id="smtp_from_email" name="smtp_from_email" value="<?php echo e($cfg['from_email']); ?>"><span class="field-error"><?php echo e($erros['smtp_from_email'] ?? ''); ?></span></div>
                </div>
            </div>
            <div class="card-footer row" style="justify-content:flex-end">
                <button type="submit" name="action" value="test_email_settings" class="btn btn-secondary" data-loading-text="Enviando teste…"><i class="fa-regular fa-paper-plane"></i>Enviar e-mail de teste</button>
                <button type="submit" name="action" value="save_email_settings" class="btn btn-primary">Salvar configurações</button>
            </div>
        </form>
    </section>
    <section class="card">
        <div class="card-body stack">
            <div class="row"><span class="status-dot <?php echo $configurado ? 'on' : 'off'; ?>"></span><strong><?php echo $configurado ? 'Envio de e-mails configurado' : 'Envio de e-mails não configurado'; ?></strong></div>
            <p class="text-2">O teste envia uma mensagem para o seu e-mail usando os dados do formulário, sem salvá-los.</p>
            <p class="text-2">Com o e-mail configurado, os usuários conseguem redefinir a própria senha pelo link “Esqueci minha senha” na tela de login.</p>
        </div>
    </section>
</div>
<?php layout_fim();
