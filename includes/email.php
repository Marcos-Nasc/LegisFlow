<?php
/**
 * Envio de e-mail com as configurações SMTP salvas em Configuração > E-mail.
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;


/** Lê as configurações de SMTP (mesmas chaves usadas anteriormente). */
function smtp_config($pdo)
{
    return [
        'host'       => getSetting($pdo, 'smtp_host'),
        'port'       => getSetting($pdo, 'smtp_port'),
        'user'       => getSetting($pdo, 'smtp_user'),
        'pass'       => getSetting($pdo, 'smtp_pass'),
        'from_name'  => getSetting($pdo, 'smtp_from_name'),
        'from_email' => getSetting($pdo, 'smtp_from_email'),
    ];
}

function smtp_configurado($pdo)
{
    $c = smtp_config($pdo);
    return $c['host'] !== '' && $c['port'] !== '' && $c['from_email'] !== '';
}

/**
 * Cria um PHPMailer configurado. A criptografia é escolhida pela porta:
 * 465 = SSL/TLS implícito; demais = STARTTLS (comportamento anterior).
 */
function criar_mailer($cfg)
{
    require_once LF_ROOT . '/vendor/autoload.php'; // carregado só quando um e-mail é enviado
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->CharSet = 'UTF-8';
    $mail->Host = $cfg['host'];
    $mail->Port = (int) $cfg['port'];
    $mail->SMTPAuth = $cfg['user'] !== '';
    $mail->Username = $cfg['user'];
    $mail->Password = $cfg['pass'];
    $mail->SMTPSecure = ((int) $cfg['port'] === 465) ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Timeout = 15;
    $mail->setFrom($cfg['from_email'], $cfg['from_name'] !== '' ? $cfg['from_name'] : nome_sistema());
    return $mail;
}

/** Corpo HTML padrão dos e-mails do sistema. */
function email_template($titulo, $paragrafosHtml, $botaoTexto = null, $botaoUrl = null)
{
    $botao = '';
    if ($botaoTexto && $botaoUrl) {
        $botao = '<p style="margin:24px 0"><a href="' . e($botaoUrl) . '" style="background:#A85F00;color:#ffffff;text-decoration:none;padding:12px 20px;border-radius:8px;font-weight:600;display:inline-block">' . e($botaoTexto) . '</a></p>';
    }
    return '<div style="font-family:Arial,Helvetica,sans-serif;background:#F2F4F7;padding:24px">'
        . '<div style="max-width:520px;margin:0 auto;background:#ffffff;border:1px solid #E3E7ED;border-radius:12px;overflow:hidden">'
        . '<div style="background:#15202E;color:#ffffff;padding:16px 24px;font-weight:600">' . e(nome_sistema()) . '</div>'
        . '<div style="padding:24px;color:#16202E;font-size:15px;line-height:1.6"><h2 style="font-size:18px;margin:0 0 12px">' . e($titulo) . '</h2>'
        . $paragrafosHtml . $botao . '</div></div></div>';
}

/**
 * Envia um e-mail. Retorna [true, null] ou [false, mensagemErro].
 */
function enviar_email($pdo, $para, $nome, $assunto, $html, $texto = '')
{
    if (!smtp_configurado($pdo)) {
        return [false, 'O envio de e-mails ainda não foi configurado.'];
    }
    $mail = null;
    try {
        $mail = criar_mailer(smtp_config($pdo));
        $mail->addAddress($para, $nome);
        $mail->isHTML(true);
        $mail->Subject = $assunto;
        $mail->Body = $html;
        $mail->AltBody = $texto !== '' ? $texto : trim(html_entity_decode(strip_tags(str_replace(['<br>', '</p>'], "\n", $html)), ENT_QUOTES, 'UTF-8'));
        $mail->send();
        return [true, null];
    } catch (\Throwable $e) {
        error_log('[LegisFlow] Falha no envio de e-mail: ' . $e->getMessage());
        return [false, ($mail && $mail->ErrorInfo) ? $mail->ErrorInfo : $e->getMessage()];
    }
}

/**
 * URL absoluta (para links em e-mails).
 * Usa 'app_url' do config.local.php (ex.: https://reviverlegisflow.com.br) para não depender
 * do cabeçalho Host da requisição, que pode ser forjado (ataque de envenenamento de link).
 */
function url_absoluta($caminho)
{
    $cfg = lf_config_local();
    if (!empty($cfg['app_url'])) {
        return rtrim($cfg['app_url'], '/') . $caminho;
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    $host = $_SERVER['SERVER_NAME'] ?? 'localhost';
    error_log('[LegisFlow] app_url não definido em config.local.php; usando SERVER_NAME para links de e-mail.');
    return ($https ? 'https' : 'http') . '://' . $host . BASE_URL . $caminho;
}
