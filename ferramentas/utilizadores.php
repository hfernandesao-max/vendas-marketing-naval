<?php
/**
 * Gestão de utilizadores da plataforma (linha de comandos, no servidor).
 *
 * As contas ficam em dados-privados/utilizadores.json, fora da pasta pública e fora do GitHub
 * (ou no caminho indicado em UTILIZADORES_JSON no .env). Cada operação fica nos registos de acesso.
 *
 * Uso (na pasta do site):
 *   php ferramentas/utilizadores.php listar
 *   php ferramentas/utilizadores.php importar-antigo                 (passa as contas de geo/auth/config_users.php para a lista nova)
 *   php ferramentas/utilizadores.php criar <utilizador> --nome "Nome" --email nome@naval.co.ao --perfil COM [--rotas 1001,1002] [--ate 2026-12-31]
 *   php ferramentas/utilizadores.php perfil <utilizador> <PERFIL> [--rotas 1001,1002]
 *   php ferramentas/utilizadores.php rotas <utilizador> 1001,1002
 *   php ferramentas/utilizadores.php email <utilizador> nome@naval.co.ao
 *   php ferramentas/utilizadores.php senha <utilizador>              (gera uma palavra-passe temporária)
 *   php ferramentas/utilizadores.php dois-passos <utilizador>        (gera o segredo para a aplicação de autenticação)
 *   php ferramentas/utilizadores.php desactivar|activar <utilizador>
 *   php ferramentas/utilizadores.php validade <utilizador> 2026-12-31|nenhuma
 *   php ferramentas/utilizadores.php desbloquear <utilizador>
 *
 * Contas de super administrador (SADM): criar, activar ou mudar o perfil para SADM exige
 *   --aprovado-por "Nome 1,Nome 2"   (duas pessoas da Administração), que fica registado.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Acesso proibido.');
}
require_once __DIR__ . '/../geo/auth/acessos.php';

function sair(string $msg, int $codigo = 1): void
{
    fwrite($codigo ? STDERR : STDOUT, $msg . PHP_EOL);
    exit($codigo);
}

function ficheiro(): string
{
    return naval_env()['UTILIZADORES_JSON'] ?? (naval_pasta_privada() . '/utilizadores.json');
}

function ler(): array
{
    $f = ficheiro();
    if (!is_file($f)) return [];
    $d = json_decode((string) file_get_contents($f), true);
    if (!is_array($d)) sair('O ficheiro de utilizadores está danificado: ' . $f);
    return $d;
}

function gravar(array $d): void
{
    $f = ficheiro();
    if (!is_dir(dirname($f))) mkdir(dirname($f), 0770, true);
    ksort($d);
    file_put_contents($f, json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    @chmod($f, 0660);
}

function opcoes(array $argv): array
{
    $o = ['_' => []];
    for ($i = 0; $i < count($argv); $i++) {
        if (substr($argv[$i], 0, 2) === '--') { $o[substr($argv[$i], 2)] = $argv[$i + 1] ?? ''; $i++; }
        else $o['_'][] = $argv[$i];
    }
    return $o;
}

function senha_temporaria(): string
{
    $c = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
    $s = '';
    for ($i = 0; $i < 16; $i++) $s .= $c[random_int(0, strlen($c) - 1)];
    return $s;
}

function rotas_lista(?string $txt): array
{
    if ($txt === null || $txt === '') return [];
    return array_values(array_filter(array_map('trim', explode(',', $txt))));
}

function exigir_aprovacao_sadm(array $o, string $login, string $accao): void
{
    $nomes = array_values(array_filter(array_map('trim', explode(',', (string) ($o['aprovado-por'] ?? '')))));
    if (count($nomes) < 2) sair("Contas SADM exigem --aprovado-por \"Nome 1,Nome 2\" (duas pessoas da Administração).");
    naval_registar('sadm_' . $accao, ['conta' => $login, 'aprovado_por' => $nomes], 'servidor');
}

$o = opcoes(array_slice($argv, 1));
$cmd = $o['_'][0] ?? 'ajuda';
$login = $o['_'][1] ?? '';
$d = ler();
$conta = function () use (&$d, $login) {
    if ($login === '' || !isset($d[$login])) sair('Conta não encontrada: ' . $login);
    return $d[$login];
};

switch ($cmd) {
    case 'listar':
        $bloq = naval_contas_bloqueadas();
        printf("%-22s %-5s %-30s %-28s %-14s %s\n", 'UTILIZADOR', 'PERFIL', 'NOME', 'EMAIL', 'ROTAS', 'ESTADO');
        foreach ($d as $l => $u) {
            $est = isset($bloq[$l]) ? 'bloqueada' : (naval_conta_valida($u) ? 'activa' : (naval_email_valido($u['email'] ?? null) ? 'inactiva' : 'sem email'));
            printf("%-22s %-5s %-30s %-28s %-14s %s%s\n", $l, $u['perfil'], mb_strimwidth_seguro($u['nome'] ?? '', 30), $u['email'] ?? '—',
                in_array($u['perfil'], NAVAL_POR_ROTA, true) ? implode(',', $u['rotas'] ?? []) : 'todas', $est, empty($u['mfa']) ? '' : ' · dois passos');
        }
        echo 'Ficheiro: ' . ficheiro() . PHP_EOL;
        break;

    case 'importar-antigo':
        if ($d) sair('A lista nova já existe (' . ficheiro() . '). Nada foi importado.');
        $antigo = require __DIR__ . '/../geo/auth/config_users.php';
        foreach ($antigo as $l => $u) {
            $p = strtoupper((string) ($u['perfil'] ?? ''));
            $d[$l] = ['nome' => $u['nome'] ?? $l, 'email' => '', 'senha' => $u['senha'], 'perfil' => isset(NAVAL_PERFIS[$p]) ? $p : (strtolower($l) === 'admin' ? 'TI' : 'DIR'),
                'rotas' => [], 'activo' => true, 'criado' => date('Y-m-d')];
        }
        gravar($d);
        naval_registar('importar_contas_antigas', ['contas' => array_keys($d)], 'servidor');
        echo count($d) . " contas importadas. Associe agora o email de cada uma (comando email): sem email, a conta não entra.\n";
        break;

    case 'criar':
        if ($login === '' || isset($d[$login])) sair('Indique um utilizador novo (este já existe ou está vazio).');
        $perfil = strtoupper((string) ($o['perfil'] ?? ''));
        if (!isset(NAVAL_PERFIS[$perfil])) sair('Perfil inválido. Use: ' . implode(', ', array_keys(NAVAL_PERFIS)));
        if (!naval_email_valido($o['email'] ?? null)) sair('O email é obrigatório e tem de ser válido (--email).');
        foreach ($d as $l => $u) if (strcasecmp($u['email'] ?? '', $o['email']) === 0) sair('Este email já está associado à conta ' . $l . '.');
        if (empty($o['nome'])) sair('Indique o nome (--nome "Nome Apelido").');
        $rotas = rotas_lista($o['rotas'] ?? null);
        if (in_array($perfil, NAVAL_POR_ROTA, true) && !$rotas) sair('Os perfis SUP e PRO precisam de rotas (--rotas 1001,1002).');
        if ($perfil === 'SADM') exigir_aprovacao_sadm($o, $login, 'criar');
        $s = senha_temporaria();
        $d[$login] = ['nome' => $o['nome'], 'email' => trim($o['email']), 'senha' => password_hash($s, PASSWORD_DEFAULT), 'perfil' => $perfil, 'rotas' => $rotas,
            'activo' => $perfil !== 'SADM', 'criado' => date('Y-m-d')] + (empty($o['ate']) ? [] : ['valido_ate' => $o['ate']]);
        gravar($d);
        naval_registar('conta_criada', ['conta' => $login, 'perfil' => $perfil, 'rotas' => $rotas], 'servidor');
        echo "Conta criada: $login ($perfil). Palavra-passe temporária: $s\n";
        if ($perfil === 'SADM') echo "Conta SADM criada desactivada. Active-a só em emergência (comando activar, com --aprovado-por).\n";
        if (in_array($perfil, NAVAL_MFA, true)) echo "Este perfil exige verificação em dois passos: corra o comando dois-passos.\n";
        break;

    case 'perfil':
        $u = $conta();
        $perfil = strtoupper($o['_'][2] ?? '');
        if (!isset(NAVAL_PERFIS[$perfil])) sair('Perfil inválido. Use: ' . implode(', ', array_keys(NAVAL_PERFIS)));
        if ($perfil === 'SADM' || $u['perfil'] === 'SADM') exigir_aprovacao_sadm($o, $login, 'perfil');
        $u['perfil'] = $perfil;
        if (isset($o['rotas'])) $u['rotas'] = rotas_lista($o['rotas']);
        if (in_array($perfil, NAVAL_POR_ROTA, true) && empty($u['rotas'])) sair('Os perfis SUP e PRO precisam de rotas (--rotas 1001,1002).');
        $d[$login] = $u; gravar($d);
        naval_registar('perfil_alterado', ['conta' => $login, 'perfil' => $perfil], 'servidor');
        echo "Perfil de $login: $perfil\n";
        break;

    case 'rotas':
        $u = $conta();
        $u['rotas'] = rotas_lista($o['_'][2] ?? '');
        $d[$login] = $u; gravar($d);
        naval_registar('rotas_alteradas', ['conta' => $login, 'rotas' => $u['rotas']], 'servidor');
        echo "Rotas de $login: " . (implode(', ', $u['rotas']) ?: 'nenhuma') . "\n";
        break;

    case 'email':
        $u = $conta();
        $e = trim($o['_'][2] ?? '');
        if (!naval_email_valido($e)) sair('Email inválido.');
        foreach ($d as $l => $x) if ($l !== $login && strcasecmp($x['email'] ?? '', $e) === 0) sair('Este email já está associado à conta ' . $l . '.');
        $u['email'] = $e; $d[$login] = $u; gravar($d);
        naval_registar('email_alterado', ['conta' => $login], 'servidor');
        echo "Email de $login: $e\n";
        break;

    case 'senha':
        $u = $conta();
        $s = senha_temporaria();
        $u['senha'] = password_hash($s, PASSWORD_DEFAULT); $d[$login] = $u; gravar($d);
        naval_registar('senha_reposta', ['conta' => $login], 'servidor');
        echo "Nova palavra-passe temporária de $login: $s\n";
        break;

    case 'dois-passos':
        $u = $conta();
        $alf = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $seg = '';
        for ($i = 0; $i < 32; $i++) $seg .= $alf[random_int(0, 31)];
        $u['mfa'] = $seg; $d[$login] = $u; gravar($d);
        naval_registar('dois_passos_configurado', ['conta' => $login], 'servidor');
        $uri = 'otpauth://totp/' . rawurlencode('GeoMobilidade Naval:' . ($u['email'] ?: $login)) . '?secret=' . $seg . '&issuer=' . rawurlencode('GeoMobilidade Naval') . '&digits=6&period=30';
        echo "Segredo para a aplicação de autenticação (Microsoft/Google Authenticator):\n  $seg\nOu crie um código QR com este endereço:\n  $uri\n";
        break;

    case 'desactivar':
    case 'activar':
        $u = $conta();
        if ($cmd === 'activar' && $u['perfil'] === 'SADM') exigir_aprovacao_sadm($o, $login, 'activar');
        $u['activo'] = $cmd === 'activar'; $d[$login] = $u; gravar($d);
        naval_registar('conta_' . ($cmd === 'activar' ? 'activada' : 'desactivada'), ['conta' => $login], 'servidor');
        echo "Conta $login " . ($cmd === 'activar' ? 'activada' : 'desactivada') . ".\n";
        break;

    case 'validade':
        $u = $conta();
        $v = $o['_'][2] ?? '';
        if ($v === 'nenhuma') unset($u['valido_ate']);
        elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) $u['valido_ate'] = $v;
        else sair('Use uma data AAAA-MM-DD ou «nenhuma».');
        $d[$login] = $u; gravar($d);
        echo "Validade de $login: " . ($u['valido_ate'] ?? 'sem data de fim') . "\n";
        break;

    case 'desbloquear':
        $conta();
        echo naval_desbloquear($login, 'servidor') ? "Conta $login desbloqueada.\n" : "A conta $login não estava bloqueada.\n";
        break;

    default:
        $doc = file_get_contents(__FILE__);
        preg_match('#/\*\*(.*?)\*/#s', $doc, $m);
        echo preg_replace('/^\s*\* ?/m', '', $m[1] ?? '') . PHP_EOL;
}

function mb_strimwidth_seguro(string $s, int $n): string
{
    return preg_replace('/^(.{0,' . $n . '}).*$/us', '$1', $s);
}
