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

// Perfis que têm de usar verificação em dois passos (aplicada quando o segredo está configurado)
const NAVAL_MFA = ['SADM', 'TI', 'DIR'];
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
    if (!$u || empty($u['activo']) || !isset(NAVAL_PERFIS[$u['perfil'] ?? ''])) return false;
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

/** Regras da palavra-passe escolhida pelo utilizador. Devolve o erro ou null. */
function naval_senha_fraca(string $s): ?string
{
    if (strlen($s) < 10) return 'A palavra-passe tem de ter pelo menos 10 caracteres.';
    if (!preg_match('/[A-Za-z]/', $s) || !preg_match('/\d/', $s)) return 'A palavra-passe tem de ter letras e números.';
    return null;
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
    $codigo = preg_replace('/\D/', '', $codigo);
    if (strlen($codigo) !== 6) return false;
    $p = (int) floor(time() / 30);
    foreach ([-1, 0, 1] as $d) if (hash_equals(naval_totp($segredo, $p + $d), $codigo)) return true;
    return false;
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
    $d = naval_estado_bloqueio($login);
    $d['login'] = $login;
    $d['falhas'] = (int) $d['falhas'] + 1;
    $d['ultima'] = date('Y-m-d H:i:s');
    if ($d['falhas'] >= NAVAL_MAX_TENTATIVAS && empty($d['bloqueado'])) {
        $d['bloqueado'] = true;
        $d['bloqueado_em'] = date('Y-m-d H:i:s');
        naval_registar('conta_bloqueada', ['tentativas' => $d['falhas']], $login);
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
