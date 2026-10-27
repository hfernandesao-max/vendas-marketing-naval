<?php
/**
 * Contas de utilizador: lista (fora da pasta pública), sessão, palavras-passe, verificação em
 * dois passos (TOTP, RFC 6238) e bloqueio à 3.ª tentativa falhada.
 *
 * Lista: UTILIZADORES_JSON no .env; por omissão dados-privados/utilizadores.json. Enquanto não
 * existir, usa-se a lista antiga geo/auth/config_users.php, em modo de compatibilidade.
 * Gestão no servidor: php ferramentas/utilizadores.php (ver README).
 */
defined('NAVAL_NUCLEO') || exit;

const NAVAL_SESSAO_INACTIVA = 1800;   // 30 minutos sem actividade
const NAVAL_SESSAO_MAXIMA = 36000;    // 10 horas
// Bloqueio: à 3.ª tentativa falhada a conta fica bloqueada até o super administrador a desbloquear
const NAVAL_MAX_TENTATIVAS = 3;

function naval_ficheiro_utilizadores(): string
{
    return naval_config('UTILIZADORES_JSON', naval_pasta_privada() . '/utilizadores.json');
}

/** Grava a lista de utilizadores (fora da pasta pública). */
function naval_gravar_utilizadores(array $d): void
{
    $f = naval_ficheiro_utilizadores();
    foreach ($d as $l => $u) unset($d[$l]['compatibilidade']);
    ksort($d);
    naval_json_gravar($f, $d);
    @chmod($f, 0660);
}

/** Lista de utilizadores: ficheiro privado; sem ele, a lista antiga em modo de compatibilidade. */
function naval_utilizadores(): array
{
    $f = naval_ficheiro_utilizadores();
    if (is_file($f)) {
        $d = json_decode((string) file_get_contents($f), true);
        if (is_array($d)) return $d;
        // Falhar de forma segura: lista nova existente mas ilegível → ninguém entra (nunca volta à lista antiga)
        error_log('[contas] ' . basename($f) . ' ilegível: entradas recusadas até ser reposto.');
        return [];
    }
    $antigo = naval_raiz() . '/geo/auth/config_users.php';
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

function naval_conta_valida(?array $u): bool
{
    if (!$u || empty($u['activo']) || !isset(naval_perfis()[$u['perfil'] ?? ''])) return false;
    if (empty($u['compatibilidade']) && !naval_email_valido($u['email'] ?? null)) return false;
    if (!empty($u['valido_ate']) && date('Y-m-d') > $u['valido_ate']) return false;
    return true;
}

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

/** Utilizador da sessão actual (null se não houver sessão válida). */
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

/** Palavra-passe temporária (16 caracteres, sem os que se confundem). */
function naval_senha_temporaria(): string
{
    $c = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
    $s = '';
    for ($i = 0; $i < 16; $i++) $s .= $c[random_int(0, strlen($c) - 1)];
    return $s;
}

const NAVAL_SENHA_MIN = 12;   // ASVS 2.1.1 / NIST SP 800-63B
const NAVAL_SENHA_MAX_BYTES = 72;   // limite do bcrypt: acima disto os caracteres seriam ignorados sem aviso
const NAVAL_SENHA_TEMPORARIA_HORAS = 24;

/**
 * Regras da palavra-passe escolhida pelo utilizador (ASVS 2.1, NIST SP 800-63B): pelo menos 12 caracteres,
 * sem regras de composição obrigatórias, recusada se for comum ou divulgada em fugas de dados, ou se for
 * só o nome da conta, do email ou da empresa com números. Devolve o erro ou null.
 */
function naval_senha_fraca(string $s, string $login = '', string $email = ''): ?string
{
    if (naval_comprimento($s) < NAVAL_SENHA_MIN) return 'A palavra-passe tem de ter pelo menos ' . NAVAL_SENHA_MIN . ' caracteres (pode usar uma frase).';
    if (strlen($s) > NAVAL_SENHA_MAX_BYTES) return 'A palavra-passe é demasiado longa (máximo 64 caracteres).';
    $min = mb_strtolower($s, 'UTF-8');
    if (in_array($min, naval_senhas_comuns(), true)) return 'Esta palavra-passe é muito comum ou apareceu em fugas de dados. Escolha outra.';
    $letras = (string) preg_replace('/[^a-z]/', '', naval_comparavel($min));
    $contexto = ['naval', 'gruponaval', 'geomobilidade', 'geomobilidadenaval', 'portalrtm', 'luanda', 'angola', 'password', 'palavrapasse', 'senha'];
    foreach ([$login, strstr($email, '@', true) ?: ''] as $x) {
        $x = (string) preg_replace('/[^a-z]/', '', naval_comparavel(mb_strtolower((string) $x, 'UTF-8')));
        if (strlen($x) >= 4) $contexto[] = $x;
    }
    foreach ($contexto as $c) {
        if ($letras === $c || $letras === $c . $c) return 'A palavra-passe não pode ser o nome da conta, do email ou da empresa com números ou símbolos.';
    }
    if (preg_match('/^(.)\1+$/u', $s) || preg_match('/^(0123456789|1234567890|abcdefghijkl|qwertyuiop)/i', $s)) return 'A palavra-passe é demasiado previsível.';
    return null;
}

/** Lista local de palavras-passe comuns (nucleo/seguranca/senhas-comuns.txt), lida uma vez por pedido. */
function naval_senhas_comuns(): array
{
    static $l = null;
    if ($l !== null) return $l;
    $l = [];
    foreach (file(__DIR__ . '/senhas-comuns.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $x) {
        if ($x !== '' && $x[0] !== '#') $l[] = mb_strtolower(trim($x), 'UTF-8');
    }
    return $l;
}

/** Texto em minúsculas sem acentos (para comparar palavras-passe com nomes). */
function naval_comparavel(string $s): string
{
    $t = function_exists('iconv') ? @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) : false;
    return is_string($t) ? strtolower($t) : $s;
}

/** Palavra-passe temporária expirada (24 h depois de criada)? Contas antigas sem data não expiram já. */
function naval_temporaria_expirada(array $u): bool
{
    return !empty($u['trocar_senha']) && !empty($u['temporaria_ate']) && (int) $u['temporaria_ate'] < time();
}

/** Marca uma palavra-passe temporária na conta (obriga a escolher outra e expira em 24 h). */
function naval_marcar_temporaria(array $u, string $senha): array
{
    $u['senha'] = password_hash($senha, PASSWORD_DEFAULT);
    $u['trocar_senha'] = true;
    $u['temporaria_ate'] = time() + NAVAL_SENHA_TEMPORARIA_HORAS * 3600;
    return naval_nova_versao($u);
}

/**
 * Versão das credenciais da conta: muda quando a palavra-passe ou os dois passos mudam. As sessões
 * abertas com uma versão anterior terminam no pedido seguinte (ASVS 3.3.3).
 */
function naval_nova_versao(array $u): array
{
    $u['versao'] = (int) ($u['versao'] ?? 0) + 1;
    return $u;
}

/** A conta tem de configurar os dois passos antes de entrar (perfil com dois passos obrigatórios)? */
function naval_mfa_inscricao_obrigatoria(array $u): bool
{
    if (naval_config('MFA_OBRIGATORIA', '1') === '0' || !empty($u['mfa']) || !empty($u['compatibilidade'])) return false;
    return in_array($u['perfil'] ?? '', naval_perfis_mfa(), true);
}

/**
 * Endereço IP do cliente. Atrás de um proxy inverso de confiança (PROXY_CONFIAVEIS no .env, IP separados
 * por vírgula) usa o último endereço de X-Forwarded-For acrescentado por esse proxy; caso contrário, REMOTE_ADDR.
 */
function naval_ip_cliente(): string
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $proxies = array_filter(array_map('trim', explode(',', naval_config('PROXY_CONFIAVEIS'))));
    if ($ip !== '' && in_array($ip, $proxies, true) && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $l = array_map('trim', explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR']));
        $x = (string) end($l);
        if (filter_var($x, FILTER_VALIDATE_IP)) return $x;
    }
    return $ip;
}

/**
 * Nome a gravar nos registos para uma tentativa de entrada: o utilizador se a conta existir; senão um
 * identificador (sem guardar o texto escrito, que pode ser uma palavra-passe escrita no campo errado).
 */
function naval_id_registo(string $escrito): string
{
    if ($escrito === '' || isset(naval_utilizadores()[$escrito])) return $escrito;
    return 'desconhecido-' . substr(hash('sha256', strtolower(trim($escrito))), 0, 10);
}

/** Segredo novo para a verificação em dois passos (base32). */
function naval_novo_segredo(): string
{
    $alf = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $s = '';
    for ($i = 0; $i < 32; $i++) $s .= $alf[random_int(0, 31)];
    return $s;
}

function naval_otpauth(string $segredo, string $conta): string
{
    return 'otpauth://totp/' . rawurlencode('GeoMobilidade Naval:' . $conta) . '?secret=' . $segredo . '&issuer=' . rawurlencode('GeoMobilidade Naval') . '&digits=6&period=30';
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
    elseif ((int) (naval_utilizador()['versao'] ?? 0) !== (int) ($_SESSION['versao_credencial'] ?? 0)) $motivo = 'credenciais_alteradas';
    if ($motivo) {
        naval_registar('sessao_terminada', ['motivo' => $motivo]);
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
        return false;
    }
    $_SESSION['ultima_actividade'] = $agora;
    return true;
}

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
    return naval_totp_periodo($segredo, $codigo) !== null;
}

/** Período de 30 s em que o código é válido (janela de ±1 período), ou null. */
function naval_totp_periodo(string $segredo, string $codigo): ?int
{
    $codigo = (string) preg_replace('/\D/', '', $codigo);
    if (strlen($codigo) !== 6 || $segredo === '') return null;
    $p = (int) floor(time() / 30);
    foreach ([-1, 0, 1] as $d) if (hash_equals(naval_totp($segredo, $p + $d), $codigo)) return $p + $d;
    return null;
}

/**
 * Código de dois passos na entrada: válido e ainda não usado (RFC 6238, §5.2: cada código só serve uma
 * vez, para que um código interceptado não possa ser reutilizado dentro da janela de validade).
 */
function naval_totp_aceitar(string $login, string $segredo, string $codigo): bool
{
    $p = naval_totp_periodo($segredo, $codigo);
    if ($p === null) return false;
    return naval_exclusivo('totp', function () use ($login, $p) {
        $f = naval_pasta('bloqueios') . '/totp-' . hash('sha256', strtolower(trim($login))) . '.json';
        $u = naval_json_ler($f);
        if ((int) ($u['periodo'] ?? 0) >= $p) return false;
        naval_json_gravar($f, ['periodo' => $p], false);
        return true;
    });
}

// ── Bloqueio de contas ──
function naval_bloqueio_ficheiro(string $login): string
{
    return naval_pasta('bloqueios') . '/' . hash('sha256', strtolower(trim($login))) . '.json';
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
    return naval_exclusivo('bloqueio-' . substr(hash('sha256', strtolower(trim($login))), 0, 16), function () use ($login) {
        return naval_falha_login_agora($login);
    });
}

function naval_falha_login_agora(string $login): int
{
    $d = naval_estado_bloqueio($login);
    // só guarda o nome de contas que existem (não o texto escrito numa conta inexistente)
    $d['login'] = isset(naval_utilizadores()[$login]) ? $login : '';
    $d['falhas'] = (int) $d['falhas'] + 1;
    $d['ultima'] = date('Y-m-d H:i:s');
    if ($d['falhas'] >= NAVAL_MAX_TENTATIVAS && empty($d['bloqueado'])) {
        $d['bloqueado'] = true;
        $d['bloqueado_em'] = date('Y-m-d H:i:s');
        naval_registar('conta_bloqueada', ['tentativas' => $d['falhas']], naval_id_registo($login));
    }
    naval_json_gravar(naval_bloqueio_ficheiro($login), $d, false);
    return max(0, NAVAL_MAX_TENTATIVAS - $d['falhas']);
}

/** Entrada com sucesso: limpa as tentativas falhadas (uma conta bloqueada nunca chega aqui). */
function naval_limpar_falhas(string $login): void
{
    $f = naval_bloqueio_ficheiro($login);
    if (is_file($f) && !naval_bloqueado($login)) @unlink($f);
}

// ── Limite de tentativas por endereço IP (força bruta e «password spraying») ──
// Complementa o bloqueio por conta: muitas falhas do mesmo IP em pouco tempo, mesmo em contas diferentes,
// suspendem as entradas a partir desse IP durante a janela (sem bloquear as contas).
const NAVAL_IP_JANELA = 900;   // 15 minutos

function naval_ip_max_falhas(): int
{
    return max(3, (int) naval_config('LOGIN_MAX_FALHAS_IP', '20'));
}

function naval_ip_ficheiro(string $ip): string
{
    return naval_pasta('limites') . '/' . hash('sha256', $ip) . '.json';
}

/** Falhas recentes (dentro da janela) de um IP. */
function naval_ip_falhas(string $ip): array
{
    $d = naval_json_ler(naval_ip_ficheiro($ip));
    $limite = time() - NAVAL_IP_JANELA;
    return array_values(array_filter((array) ($d['falhas'] ?? []), function ($t) use ($limite) {
        return (int) $t > $limite;
    }));
}

/** O IP excedeu as falhas permitidas na janela? */
function naval_ip_suspenso(string $ip): bool
{
    return $ip !== '' && count(naval_ip_falhas($ip)) >= naval_ip_max_falhas();
}

function naval_ip_falha(string $ip): void
{
    if ($ip === '') return;
    naval_exclusivo('limite-ip', function () use ($ip) {
        $f = naval_ip_falhas($ip);
        $f[] = time();
        naval_json_gravar(naval_ip_ficheiro($ip), ['falhas' => array_slice($f, -100)], false);
        if (count($f) === naval_ip_max_falhas()) naval_registar('ip_suspenso', ['ip' => $ip, 'falhas' => count($f)]);
    });
}

/** Hash fictício (bcrypt, custo 10) para verificar a palavra-passe de contas inexistentes no mesmo tempo. */
// nosemgrep: detected-bcrypt-hash -- hash de um valor aleatório descartado (não é a palavra-passe de ninguém)
const NAVAL_HASH_FICTICIO = '$2y$10$MjKGPMs.jY2pD91k9Lt9vuNY2o8UZJzgmn1D9h6hhNitzidO0.XjG';

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
