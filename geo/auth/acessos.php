<?php
/**
 * Controlo de acessos da plataforma (REPORTS, GEO e administração).
 *
 * Aplica a «Matriz de acessos · Plataforma RTM e GeoMobilidade Naval» (26-10-2026):
 *   - 11 perfis (SADM de emergência + 10 perfis de trabalho);
 *   - nível por recurso: '' sem acesso, V ver, Vr ver só as suas rotas, E exportar,
 *     Er exportar só as suas rotas, G gerir, A administrar;
 *   - âmbito por rota (SUP e PRO), dados pessoais (NIF) só para quem precisa;
 *   - contas nominativas guardadas fora do GitHub, sessão com expiração e registos de actividade.
 *
 * Utilizadores: ficheiro JSON fora da pasta pública (UTILIZADORES_JSON no .env; por omissão
 * ../dados-privados/utilizadores.json ao lado da pasta do site). Enquanto não existir, usa-se a
 * lista antiga geo/auth/config_users.php, em modo de compatibilidade.
 * Gestão: php ferramentas/utilizadores.php (ver README).
 */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {
    http_response_code(403);
    exit('Acesso proibido.');
}

const NAVAL_PERFIS = [
    'SADM' => 'Super administrador',
    'TI'   => 'Administrador da plataforma',
    'DIR'  => 'Administração e Direcção',
    'COM'  => 'Direcção Comercial e Marketing',
    'SUP'  => 'Supervisor de rotas',
    'PRO'  => 'Promotor',
    'FIN'  => 'Financeiro e Tesouraria',
    'BO'   => 'Back-office RTM',
    'LOG'  => 'Logística e Armazém',
    'GEO'  => 'Planeamento de rotas',
    'AUD'  => 'Auditoria e Controlo',
];

// Ordem das colunas da matriz: TI DIR COM SUP PRO FIN BO LOG GEO AUD ('-' = sem acesso).
// O SADM tem 'A' em tudo.
const NAVAL_COLUNAS = ['TI', 'DIR', 'COM', 'SUP', 'PRO', 'FIN', 'BO', 'LOG', 'GEO', 'AUD'];
const NAVAL_MATRIZ_TXT = [
    // REPORTS
    'pagina-principal'     => 'V  V  V  V  V  V  V  V  V  V',
    'inicio'               => 'V  E  E  Vr Vr E  V  V  -  V',
    'vendas-promotor'      => 'V  E  E  Vr Vr E  -  -  -  E',
    'vendas-dia'           => 'V  E  E  Vr Vr E  -  -  -  E',
    'vendas-cliente'       => 'V  E  E  Er Vr E  -  -  -  E',
    'ranking'              => 'V  E  E  Vr Vr V  -  -  -  V',
    'documentos'           => 'V  E  E  Er Vr E  V  -  -  E',
    'pagamentos'           => 'V  E  V  Vr Vr E  -  -  -  E',
    'auditoria'            => 'V  V  E  Vr -  E  V  -  -  E',
    'stock-carrinha'       => 'V  V  E  Vr Vr V  -  E  -  V',
    'stock-armazem'        => 'V  V  E  -  -  V  -  E  -  V',
    'vendas-artigo'        => 'V  E  E  Vr Vr E  -  E  -  E',
    'vendas-marca'         => 'V  E  E  Vr Vr E  -  -  -  E',
    'cadastros'            => 'V  V  E  Vr Vr -  E  -  V  V',
    'cadastros-analise'    => 'V  V  E  Vr Vr -  E  -  -  V',
    'cadastros-clientes'   => 'V  -  E  Er Vr -  E  -  V  V',
    'clientes'             => 'V  -  E  Er Vr V  E  -  V  V',
    'plano-visitas'        => 'V  V  E  Vr Vr -  V  -  V  V',
    'excel-completo'       => '-  E  E  -  -  E  -  -  -  E',
    // GEO
    'geo-rotas'            => 'V  V  V  Vr Vr -  -  V  G  V',
    'geo-rotas-importar'   => 'G  -  -  -  -  -  G  -  G  -',
    'geo-rotas-descarregar' => 'V  -  E  Er Er -  -  E  E  -',
    'geo-zonas'            => 'V  V  V  Vr -  -  V  V  G  V',
    'geo-zonas-gerir'      => 'G  -  -  -  -  -  -  -  G  -',
    'geo-distribuidores'   => 'V  V  V  Vr -  -  V  V  G  V',
    // Administração
    'acessos'              => 'A  V  -  -  -  -  -  -  -  V',
    'admin-utilizadores'   => 'A  -  -  -  -  -  -  -  -  -',
    'admin-config'         => 'A  -  -  -  -  -  -  -  -  -',
    'registos'             => 'A  V  -  -  -  -  -  -  -  V',
];
// Quem vê o NIF dos clientes (secção «Dados pessoais» da matriz)
const NAVAL_VE_NIF = ['SADM', 'TI', 'COM', 'SUP', 'PRO', 'FIN', 'BO', 'AUD'];
// Perfis em que o âmbito é sempre limitado às rotas atribuídas
const NAVAL_POR_ROTA = ['SUP', 'PRO'];
// Perfis que têm de usar verificação em dois passos (aplicada quando o segredo está configurado)
const NAVAL_MFA = ['SADM', 'TI', 'DIR'];
const NAVAL_SESSAO_INACTIVA = 1800;   // 30 minutos sem actividade
const NAVAL_SESSAO_MAXIMA = 36000;    // 10 horas

const NAVAL_ORDEM_NIVEIS = ['' => 0, 'Vr' => 1, 'V' => 2, 'Er' => 3, 'E' => 4, 'G' => 5, 'A' => 6];

function naval_env(): array
{
    static $env = null;
    if ($env !== null) return $env;
    $env = [];
    $f = dirname(__DIR__, 2) . '/.env';
    if (is_file($f)) {
        foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $l) {
            $l = trim($l);
            if ($l === '' || $l[0] === '#' || strpos($l, '=') === false) continue;
            [$k, $v] = explode('=', $l, 2);
            $env[trim($k)] = trim(trim($v), "\"'");
        }
    }
    return $env;
}

/** Pasta privada (fora da pasta pública) para utilizadores, registos e bloqueios. */
function naval_pasta_privada(): string
{
    $p = naval_env()['DADOS_PRIVADOS'] ?? (dirname(__DIR__, 3) . '/dados-privados');
    return rtrim($p, '/\\');
}

function naval_matriz(): array
{
    static $m = null;
    if ($m !== null) return $m;
    $m = [];
    foreach (NAVAL_MATRIZ_TXT as $rec => $txt) {
        $v = preg_split('/\s+/', trim($txt));
        foreach (NAVAL_COLUNAS as $i => $p) $m[$rec][$p] = ($v[$i] ?? '-') === '-' ? '' : $v[$i];
        $m[$rec]['SADM'] = 'A';
    }
    return $m;
}

/** Lista de utilizadores: ficheiro privado; sem ele, a lista antiga em modo de compatibilidade. */
function naval_utilizadores(): array
{
    $f = naval_env()['UTILIZADORES_JSON'] ?? (naval_pasta_privada() . '/utilizadores.json');
    if (is_file($f)) {
        $d = json_decode((string) file_get_contents($f), true);
        if (is_array($d)) return $d;
    }
    $antigo = __DIR__ . '/config_users.php';
    if (!is_file($antigo)) return [];
    $out = [];
    foreach ((require $antigo) as $login => $u) {
        $p = strtoupper((string) ($u['perfil'] ?? ''));
        // Compatibilidade: «admin» passa a TI; perfis desconhecidos ficam com leitura de Direcção até serem revistos
        $perfil = isset(NAVAL_PERFIS[$p]) ? $p : (strtolower($login) === 'admin' ? 'TI' : 'DIR');
        $out[$login] = ['nome' => $u['nome'] ?? $login, 'senha' => $u['senha'] ?? '', 'perfil' => $perfil, 'rotas' => [], 'activo' => true, 'compatibilidade' => true];
    }
    return $out;
}

/** Email associado à conta: obrigatório em todas as contas (excepto na lista antiga, em compatibilidade). */
function naval_email_valido($email): bool
{
    return is_string($email) && filter_var(trim($email), FILTER_VALIDATE_EMAIL) !== false;
}

function naval_conta_valida(?array $u): bool
{
    if (!$u || empty($u['activo']) || !isset(NAVAL_PERFIS[$u['perfil'] ?? ''])) return false;
    if (empty($u['compatibilidade']) && !naval_email_valido($u['email'] ?? null)) return false;
    if (!empty($u['valido_ate']) && date('Y-m-d') > $u['valido_ate']) return false;
    return true;
}

/** Utilizador da sessão actual (null se não houver sessão válida). */
/** Procura a conta pelo utilizador ou pelo email associado. Devolve [login, conta] ou [null, null]. */
function naval_procurar_conta(string $id): array
{
    $todos = naval_utilizadores();
    if (isset($todos[$id])) return [$id, $todos[$id]];
    if (strpos($id, '@') !== false) {
        foreach ($todos as $login => $u) {
            if (strcasecmp(trim((string) ($u['email'] ?? '')), trim($id)) === 0) return [$login, $u];
        }
    }
    return [null, null];
}

function naval_utilizador(): ?array
{
    static $cache = false;
    if ($cache !== false) return $cache;
    $login = $_SESSION['usuario_login'] ?? null;
    $todos = $login ? naval_utilizadores() : [];
    $u = $login && isset($todos[$login]) ? $todos[$login] + ['login' => $login] : null;
    $cache = naval_conta_valida($u) ? $u : null;
    return $cache;
}

function naval_perfil(): string
{
    $u = naval_utilizador();
    return $u ? $u['perfil'] : '';
}

/** Nível do utilizador actual num recurso ('' = sem acesso). */
function naval_nivel(string $recurso): string
{
    $p = naval_perfil();
    if ($p === '') return '';
    return naval_matriz()[$recurso][$p] ?? '';
}

function naval_pode(string $recurso, string $minimo = 'Vr'): bool
{
    return NAVAL_ORDEM_NIVEIS[naval_nivel($recurso)] >= NAVAL_ORDEM_NIVEIS[$minimo];
}

/** Pode exportar este recurso (E, Er, G ou A). */
function naval_pode_exportar(string $recurso): bool
{
    return in_array(naval_nivel($recurso), ['E', 'Er', 'G', 'A'], true);
}

/** Rotas a que o utilizador tem acesso: null = todas. */
function naval_rotas(): ?array
{
    $u = naval_utilizador();
    if (!$u) return [];
    if (in_array($u['perfil'], NAVAL_POR_ROTA, true)) return array_values(array_map('strval', $u['rotas'] ?? []));
    return null;
}

function naval_rota_permitida($rota): bool
{
    $r = naval_rotas();
    return $r === null || in_array((string) $rota, $r, true);
}

function naval_ve_nif(): bool
{
    return in_array(naval_perfil(), NAVAL_VE_NIF, true);
}

/** Pelo menos um dos recursos com acesso; sem isso responde 403 em JSON. */
function naval_exigir(array $recursos): void
{
    foreach ($recursos as $r) if (naval_pode($r)) return;
    naval_registar('acesso_negado', ['recursos' => $recursos]);
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['erro' => 'Sem permissão para estes dados.', 'detalhe' => 'Perfil ' . naval_perfil()], JSON_UNESCAPED_UNICODE);
    exit;
}

/** Registo de actividade (JSON por linha, um ficheiro por mês, fora da pasta pública). */
function naval_registar(string $accao, array $detalhe = [], ?string $login = null): void
{
    $dir = naval_pasta_privada() . '/registos';
    if (!is_dir($dir)) @mkdir($dir, 0770, true);
    $u = naval_utilizador();
    $linha = [
        'data' => date('Y-m-d H:i:s'),
        'utilizador' => $login ?? ($u['login'] ?? ($_SESSION['usuario_login'] ?? '')),
        'perfil' => $u['perfil'] ?? '',
        'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
        'accao' => $accao,
        'detalhe' => $detalhe,
    ];
    @file_put_contents($dir . '/acessos-' . date('Y-m') . '.jsonl', json_encode($linha, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
}

function naval_ler_registos(int $max = 300): array
{
    $dir = naval_pasta_privada() . '/registos';
    $fich = glob($dir . '/acessos-*.jsonl') ?: [];
    rsort($fich);
    $out = [];
    foreach ($fich as $f) {
        $linhas = file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        for ($i = count($linhas) - 1; $i >= 0 && count($out) < $max; $i--) {
            $j = json_decode($linhas[$i], true);
            if ($j) $out[] = $j;
        }
        if (count($out) >= $max) break;
    }
    return $out;
}

/** Sessão: termina após 30 minutos sem actividade ou 10 horas, e se a conta deixar de ser válida. */
function naval_sessao_valida(): bool
{
    if (empty($_SESSION['usuario_autenticado'])) return false;
    $agora = time();
    $inicio = (int) ($_SESSION['login_time'] ?? $agora);
    $ultima = (int) ($_SESSION['ultima_actividade'] ?? $agora);
    $motivo = null;
    if ($agora - $ultima > NAVAL_SESSAO_INACTIVA) $motivo = 'inactividade';
    elseif ($agora - $inicio > NAVAL_SESSAO_MAXIMA) $motivo = 'duracao_maxima';
    elseif (!naval_utilizador()) $motivo = 'conta_invalida';
    if ($motivo) {
        naval_registar('sessao_terminada', ['motivo' => $motivo]);
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
        return false;
    }
    $_SESSION['ultima_actividade'] = $agora;
    return true;
}

// ── Verificação em dois passos (TOTP, RFC 6238: códigos de 6 dígitos a cada 30 s) ──
function naval_base32_decode(string $s): string
{
    $alf = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $s = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $s));
    $bits = '';
    foreach (str_split($s) as $c) $bits .= str_pad(decbin(strpos($alf, $c)), 5, '0', STR_PAD_LEFT);
    $out = '';
    foreach (str_split($bits, 8) as $b) if (strlen($b) === 8) $out .= chr(bindec($b));
    return $out;
}

function naval_totp(string $segredo, int $passo): string
{
    $h = hash_hmac('sha1', pack('N*', 0, $passo), naval_base32_decode($segredo), true);
    $o = ord($h[19]) & 0xf;
    $n = ((ord($h[$o]) & 0x7f) << 24) | (ord($h[$o + 1]) << 16) | (ord($h[$o + 2]) << 8) | ord($h[$o + 3]);
    return str_pad((string) ($n % 1000000), 6, '0', STR_PAD_LEFT);
}

function naval_totp_valido(string $segredo, string $codigo): bool
{
    $codigo = preg_replace('/\D/', '', $codigo);
    if (strlen($codigo) !== 6) return false;
    $p = (int) floor(time() / 30);
    foreach ([-1, 0, 1] as $d) if (hash_equals(naval_totp($segredo, $p + $d), $codigo)) return true;
    return false;
}

// ── Bloqueio: à 3.ª tentativa falhada a conta fica bloqueada até o super administrador a desbloquear ──
const NAVAL_MAX_TENTATIVAS = 3;

function naval_bloqueio_ficheiro(string $login): string
{
    $dir = naval_pasta_privada() . '/bloqueios';
    if (!is_dir($dir)) @mkdir($dir, 0770, true);
    return $dir . '/' . hash('sha256', strtolower(trim($login))) . '.json';
}

function naval_estado_bloqueio(string $login): array
{
    $f = naval_bloqueio_ficheiro($login);
    $d = is_file($f) ? json_decode((string) file_get_contents($f), true) : null;
    return is_array($d) ? $d + ['falhas' => 0, 'bloqueado' => false] : ['falhas' => 0, 'bloqueado' => false];
}

function naval_bloqueado(string $login): bool
{
    return !empty(naval_estado_bloqueio($login)['bloqueado']);
}

/** Regista uma tentativa falhada; devolve as tentativas que restam (0 = bloqueada agora). */
function naval_falha_login(string $login): int
{
    $d = naval_estado_bloqueio($login);
    $d['login'] = $login;
    $d['falhas'] = (int) $d['falhas'] + 1;
    $d['ultima'] = date('Y-m-d H:i:s');
    if ($d['falhas'] >= NAVAL_MAX_TENTATIVAS && empty($d['bloqueado'])) {
        $d['bloqueado'] = true;
        $d['bloqueado_em'] = date('Y-m-d H:i:s');
        naval_registar('conta_bloqueada', ['tentativas' => $d['falhas']], $login);
    }
    @file_put_contents(naval_bloqueio_ficheiro($login), json_encode($d, JSON_UNESCAPED_UNICODE), LOCK_EX);
    return max(0, NAVAL_MAX_TENTATIVAS - $d['falhas']);
}

/** Entrada com sucesso: limpa as tentativas falhadas (uma conta bloqueada nunca chega aqui). */
function naval_limpar_falhas(string $login): void
{
    $f = naval_bloqueio_ficheiro($login);
    if (is_file($f) && !naval_bloqueado($login)) @unlink($f);
}

/** Desbloqueio: só o super administrador (no portal) ou a ferramenta do servidor. */
function naval_desbloquear(string $login, string $por): bool
{
    $f = naval_bloqueio_ficheiro($login);
    if (!is_file($f)) return false;
    @unlink($f);
    naval_registar('conta_desbloqueada', ['conta' => $login, 'por' => $por]);
    return true;
}

function naval_contas_bloqueadas(): array
{
    $out = [];
    foreach (glob(naval_pasta_privada() . '/bloqueios/*.json') ?: [] as $f) {
        $d = json_decode((string) file_get_contents($f), true);
        if (!empty($d['bloqueado']) && !empty($d['login'])) $out[$d['login']] = $d['bloqueado_em'] ?? '';
    }
    return $out;
}

// ── Páginas do GEO ──
/** Página protegida: sem acesso ao recurso, mostra uma página 403 com ligação para a página principal. */
function naval_pagina_exigir(string $recurso, string $voltar): void
{
    if (naval_pode($recurso)) return;
    naval_registar('acesso_negado', ['recursos' => [$recurso]]);
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="pt-PT"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>Sem acesso · GeoMobilidade Naval</title><body style="margin:0;min-height:100vh;display:grid;place-items:center;background:#06080F;color:#F5F5F7;font:15px/1.5 -apple-system,Segoe UI,Roboto,Arial,sans-serif">'
        . '<div style="max-width:420px;padding:24px;text-align:center"><h1 style="font-size:22px;margin:0 0 8px">Sem acesso a esta página</h1>'
        . '<p style="color:#A1A1AA;margin:0 0 20px">O seu perfil (' . htmlspecialchars(NAVAL_PERFIS[naval_perfil()] ?? naval_perfil()) . ') não inclui esta área. Se precisa dela, peça o acesso ao responsável da sua área.</p>'
        . '<a href="' . htmlspecialchars($voltar) . '" style="display:inline-block;padding:10px 18px;border-radius:999px;background:#0A84FF;color:#fff;text-decoration:none;font-weight:600">Voltar à página principal</a></div></body></html>';
    exit;
}

/**
 * Funções de edição do GEO que o perfil não pode usar: esconde os botões e desactiva as funções
 * no navegador (mostram um aviso). Devolve o HTML a colocar antes de </body>.
 */
function naval_geo_restricoes(array $grupos): string
{
    $funcoes = [];
    foreach ($grupos as $recurso => $nomes) {
        $minimo = strpos($recurso, 'descarregar') !== false ? 'Er' : 'G';
        $ok = $minimo === 'G' ? naval_pode($recurso, 'G') : naval_pode_exportar($recurso);
        if (!$ok) $funcoes = array_merge($funcoes, $nomes);
    }
    if (!$funcoes) return '';
    $css = [];
    foreach ($funcoes as $f) {
        if ($f[0] === '#') $css[] = $f;
        else $css[] = '[onclick^="' . $f . '"]';
    }
    $js = array_values(array_filter($funcoes, function ($f) { return $f[0] !== '#'; }));
    return '<style>' . implode(',', $css) . '{display:none!important}</style>'
        . '<script>(function(){var n=' . json_encode($js) . ';n.forEach(function(f){window[f]=function(){alert("O seu perfil não permite esta acção.");};});})();</script>';
}
