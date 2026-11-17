<?php
/**
 * Banco de dados: conexão, situação das tabelas e backup (somente Administrador).
 *
 * - As credenciais ficam em includes/config.local.php (gerado com var_export,
 *   sem risco de injeção de código).
 * - O backup é gerado em PHP puro (não depende do mysqldump.exe do XAMPP,
 *   que não existe no servidor Linux de produção).
 */
require_once __DIR__ . '/../includes/bootstrap.php';
exigir_login();
exigir_nivel(NIVEL_ADMIN, 'Configuração do Banco de Dados');

$uid = usuario_id();
$arquivoCfg = LF_ROOT . '/includes/config.local.php';
$cfgLocal = lf_config_local();
$atual = [
    'host' => $cfgLocal['db']['host'] ?? (getenv('LF_DB_HOST') ?: ''),
    'dbname' => $cfgLocal['db']['dbname'] ?? (getenv('LF_DB_NAME') ?: ''),
    'user' => $cfgLocal['db']['user'] ?? (getenv('LF_DB_USER') ?: ''),
];
$senhaAtual = $cfgLocal['db']['pass'] ?? (getenv('LF_DB_PASS') ?: '');

/** Tenta conectar; retorna [true, versão] ou [false, mensagem]. */
function testar_conexao($host, $db, $user, $pass)
{
    try {
        $t = new PDO("mysql:host={$host};dbname={$db};charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
        return [true, (string) $t->query('SELECT VERSION()')->fetchColumn()];
    } catch (PDOException $e) {
        return [false, $e->getMessage()];
    }
}

/* ---------- Backup (download .sql) ---------- */
if (is_post() && ($_POST['action'] ?? '') === 'criar_backup') {
    @set_time_limit(600);
    $nomeBanco = $atual['dbname'] !== '' ? $atual['dbname'] : 'reviverlegisflow';
    $arquivo = 'backup_' . preg_replace('/[^a-z0-9_]/i', '', $nomeBanco) . '_' . date('Y-m-d_H-i-s') . '.sql';
    try {
        $tabelas = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        while (ob_get_level()) { ob_end_clean(); }
        header('Content-Type: application/sql; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $arquivo . '"');
        header('Cache-Control: no-store');
        echo "-- Backup gerado por " . nome_sistema() . "\n-- Data: " . date('d/m/Y H:i:s') . "\n-- Banco: `$nomeBanco`\n\n";
        echo "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\nSET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n\n";
        foreach ($tabelas as $t) {
            $t = (string) $t;
            $create = $pdo->query('SHOW CREATE TABLE `' . str_replace('`', '', $t) . '`')->fetch(PDO::FETCH_NUM);
            echo "-- --------------------------------------------------------\n-- Tabela `$t`\n\nDROP TABLE IF EXISTS `$t`;\n" . $create[1] . ";\n\n";
            $stmt = $pdo->query('SELECT * FROM `' . str_replace('`', '', $t) . '`');
            $lote = [];
            $colunas = null;
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if ($colunas === null) { $colunas = '`' . implode('`, `', array_keys($row)) . '`'; }
                $vals = array_map(function ($v) use ($pdo) { return $v === null ? 'NULL' : $pdo->quote((string) $v); }, array_values($row));
                $lote[] = '(' . implode(', ', $vals) . ')';
                if (count($lote) >= 200) {
                    echo "INSERT INTO `$t` ($colunas) VALUES\n" . implode(",\n", $lote) . ";\n";
                    $lote = [];
                }
            }
            if ($lote) { echo "INSERT INTO `$t` ($colunas) VALUES\n" . implode(",\n", $lote) . ";\n"; }
            echo "\n";
        }
        echo "SET FOREIGN_KEY_CHECKS = 1;\n";
        registrar_log($pdo, 'Backup Criado (Sucesso)', $uid, "Download do backup '$arquivo' gerado (" . count($tabelas) . ' tabelas).');
        exit();
    } catch (PDOException $e) {
        registrar_log($pdo, 'Backup Criado (Falha)', $uid, 'Erro: ' . $e->getMessage());
        $ref = erro_interno('Backup', $e);
        if (!headers_sent()) {
            flash('error', "Não foi possível gerar o backup (ref. $ref).");
            redirecionar('config_banco.php');
        }
        echo "\n-- ERRO: backup incompleto (ref. $ref)\n";
        exit();
    }
}

/* ---------- Testar / salvar conexão ---------- */
if (is_post() && in_array($_POST['action'] ?? '', ['testar_conexao', 'salvar_conexao'], true)) {
    $acao = $_POST['action'];
    $n = [
        'host' => trim((string) ($_POST['host'] ?? '')),
        'dbname' => trim((string) ($_POST['dbname'] ?? '')),
        'user' => trim((string) ($_POST['user'] ?? '')),
        'pass' => (string) ($_POST['pass'] ?? ''),
    ];
    if ($n['pass'] === '') { $n['pass'] = $senhaAtual; } // em branco = manter a atual
    $det = "Host: {$n['host']}, DB: {$n['dbname']}, User: {$n['user']}";

    if ($n['host'] === '' || $n['dbname'] === '' || $n['user'] === '') {
        responder_json(['status' => 'error', 'message' => 'Preencha servidor, banco e usuário.'], 422);
    }
    list($ok, $info) = testar_conexao($n['host'], $n['dbname'], $n['user'], $n['pass']);
    if ($acao === 'testar_conexao') {
        registrar_log($pdo, $ok ? 'Teste de Conexão (Sucesso)' : 'Teste de Conexão (Falha)', $uid, $det . ($ok ? '' : '. Erro: ' . $info));
        $ok ? responder_json(['status' => 'success', 'message' => 'Conexão bem-sucedida (MySQL ' . $info . ').'])
            : responder_json(['status' => 'error', 'message' => 'Falha na conexão: ' . $info], 422);
    }
    if (!$ok) {
        registrar_log($pdo, 'Config. Banco Salva (Falha Conexão)', $uid, "Tentou salvar: $det. Erro: $info");
        responder_json(['status' => 'error', 'message' => 'As configurações NÃO foram salvas porque a conexão falhou: ' . $info], 422);
    }
    $novoCfg = $cfgLocal;
    unset($novoCfg['pdo_factory']);
    $novoCfg['db'] = $n;
    $conteudo = "<?php\n// Configuração local — gerada em " . date('d/m/Y H:i') . " pela tela Banco de Dados.\n// NÃO versionar este arquivo (contém credenciais).\nreturn " . var_export($novoCfg, true) . ";\n";
    if (file_exists($arquivoCfg)) { @copy($arquivoCfg, $arquivoCfg . '.bak'); }
    if (@file_put_contents($arquivoCfg, $conteudo, LOCK_EX) === false) {
        registrar_log($pdo, 'Config. Banco Salva (Falha Escrita)', $uid, "Tentou salvar: $det. Sem permissão de escrita em includes/config.local.php.");
        responder_json(['status' => 'error', 'message' => 'Não foi possível gravar includes/config.local.php. Verifique as permissões da pasta includes.'], 500);
    }
    @chmod($arquivoCfg, 0640);
    registrar_log($pdo, 'Config. Banco Salva (Sucesso)', $uid, "Arquivo config.local.php atualizado com: $det, Senha: " . (($_POST['pass'] ?? '') === '' ? '(Mantida)' : '(Alterada)'));
    responder_json(['status' => 'success', 'message' => 'Configurações salvas. As próximas páginas já usam a nova conexão.']);
}

/* ---------- Situação do banco ---------- */
$versao = '—';
$tabelas = [];
try {
    $versao = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
    $nomes = [
        'processos_judiciais' => 'Processos', 'relatorio_mensal_processos' => 'Totais mensais',
        'usuarios' => 'Usuários', 'logs' => 'Logs de atividade', 'configuracoes' => 'Configurações',
    ];
    foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) {
        $q = (int) $pdo->query('SELECT COUNT(*) FROM `' . str_replace('`', '', $t) . '`')->fetchColumn();
        $tabelas[] = ['nome' => $t, 'rotulo' => $nomes[$t] ?? $t, 'linhas' => $q];
    }
} catch (PDOException $e) {
    erro_interno('Situação do banco', $e);
}
$ultimoBackup = $pdo->query("SELECT data_ocorrencia FROM logs WHERE acao = 'Backup Criado (Sucesso)' ORDER BY id DESC LIMIT 1")->fetchColumn();
$credenciaisNoArquivo = is_file($arquivoCfg) && isset($cfgLocal['db']);

layout_inicio('Banco de Dados', ['ativo' => 'banco']);
page_header('Banco de dados', 'Conexão, situação das tabelas e cópia de segurança.');
?>
<div class="grid grid-main-side">
    <div class="stack">
        <section class="card">
            <div class="setting-row">
                <div><h3>Cópia de segurança</h3>
                    <p>Baixa um arquivo .sql com a estrutura e todos os dados, pronto para restaurar pelo phpMyAdmin. <?php echo $ultimoBackup ? 'Último backup: ' . data_br($ultimoBackup, true) . '.' : 'Nenhum backup registrado pelo sistema.'; ?></p></div>
                <form method="POST"><?php echo csrf_field(); ?><input type="hidden" name="action" value="criar_backup">
                    <button type="submit" class="btn btn-primary" data-no-loading="1"><i class="fa-solid fa-download"></i>Baixar backup</button></form>
            </div>
        </section>

        <section class="card">
            <div class="card-header"><div><h2>Conexão</h2><p>Use somente ao migrar de servidor ou trocar a senha do banco.</p></div></div>
            <form id="form-conexao" class="card-body" data-ajax="1" autocomplete="off" novalidate>
                <div class="form-grid">
                    <div class="field"><label for="host">Servidor</label><input class="input" id="host" name="host" value="<?php echo e($atual['host']); ?>"></div>
                    <div class="field"><label for="dbname">Banco</label><input class="input" id="dbname" name="dbname" value="<?php echo e($atual['dbname']); ?>"></div>
                    <div class="field"><label for="user">Usuário</label><input class="input" id="user" name="user" value="<?php echo e($atual['user']); ?>"></div>
                    <div class="field"><label for="pass">Senha</label><input class="input" type="password" id="pass" name="pass" placeholder="Manter a senha atual" autocomplete="new-password"></div>
                </div>
                <div class="alert alert-warning mt-4"><i class="fa-solid fa-triangle-exclamation"></i><div class="alert-body">Ao salvar, a conexão é testada antes. Se ela falhar, nada é alterado. Uma cópia da configuração anterior fica em <span class="code">includes/config.local.php.bak</span>.</div></div>
                <div class="row mt-4" style="justify-content:flex-end">
                    <button type="button" class="btn btn-secondary" data-conexao="testar_conexao">Testar conexão</button>
                    <button type="button" class="btn btn-primary" data-conexao="salvar_conexao">Salvar</button>
                </div>
            </form>
        </section>
    </div>
    <div class="stack">
        <section class="card">
            <div class="card-header"><div><h2>Situação</h2><p>MySQL <?php echo e($versao); ?></p></div><span class="row"><span class="status-dot on"></span><span class="hint">Conectado</span></span></div>
            <ul class="list">
                <?php foreach ($tabelas as $t): ?>
                    <li class="list-item"><div class="list-body"><div class="list-title"><?php echo e($t['rotulo']); ?></div><div class="list-desc"><span class="code" style="font-size:11.5px"><?php echo e($t['nome']); ?></span></div></div><span class="list-qty"><?php echo numero($t['linhas']); ?></span></li>
                <?php endforeach; ?>
            </ul>
        </section>
        <?php if (!$credenciaisNoArquivo): ?>
        <div class="alert alert-info"><i class="fa-solid fa-circle-info"></i><div class="alert-body">As credenciais estão vindo de variáveis de ambiente do servidor.</div></div>
        <?php endif; ?>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var f = document.getElementById('form-conexao');
    f.querySelectorAll('[data-conexao]').forEach(function (b) {
        b.addEventListener('click', async function () {
            var acao = b.dataset.conexao;
            if (acao === 'salvar_conexao') {
                var ok = await LF.confirm({ title: 'Salvar nova conexão?', text: 'Todo o sistema passará a usar este banco de dados imediatamente.', confirmText: 'Salvar conexão', tone: 'warning' });
                if (!ok) return;
            }
            var fd = new FormData(f); fd.append('action', acao);
            LF.loading(b, true);
            try { var r = await LF.request('config_banco.php', { body: fd }); LF.toast(r.message, 'success'); }
            catch (e) { LF.toast(e.message, 'error'); }
            finally { LF.loading(b, false); }
        });
    });
});
</script>
<?php layout_fim();
