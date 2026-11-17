<?php
/**
 * Configurações globais da aplicação.
 *
 * O nome exibido do sistema agora é gerenciado em Configuração > Aparência e fica
 * gravado na tabela `configuracoes` (chave `app_name`). A constante abaixo é usada
 * apenas como valor padrão, caso essa chave ainda não exista.
 */
if (!defined('APP_NAME')) {
    define('APP_NAME', 'Sistema Jurídico Reviver');
}

// Versão da interface (usada para invalidar cache de CSS/JS após atualizações)
if (!defined('APP_VERSION')) {
    define('APP_VERSION', '2.0.0');
}

// Níveis de acesso (mesmos valores já gravados na coluna usuarios.nivel)
if (!defined('NIVEL_USUARIO')) {
    define('NIVEL_USUARIO', 1);
    define('NIVEL_EDITOR', 2);
    define('NIVEL_ADMIN', 3);
}

if (!function_exists('lf_config_local')) {
    /**
     * Lê (uma única vez) o array de configuração local em includes/config.local.php.
     * Esse arquivo guarda credenciais e não deve ir para o controle de versão.
     */
    function lf_config_local()
    {
        static $cfg = null;
        if ($cfg !== null) {
            return $cfg;
        }
        $cfg = [];
        $arquivo = __DIR__ . '/config.local.php';
        if (is_file($arquivo)) {
            $lido = require $arquivo;
            if (is_array($lido)) {
                $cfg = $lido;
            }
        }
        return $cfg;
    }
}
