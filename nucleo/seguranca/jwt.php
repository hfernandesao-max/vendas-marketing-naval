<?php
/**
 * JSON Web Tokens da API central (RFC 7519 / RFC 8725 «JWT Best Current Practices», OWASP JWT Cheat Sheet).
 *
 * - Algoritmo fixo HS256 (HMAC-SHA256): o cabeçalho «alg» nunca é escolhido pelo token (recusa «none»,
 *   RS/HS trocados e qualquer outro); chave de pelo menos 32 bytes só no .env (JWT_SEGREDO); rotação com
 *   JWT_SEGREDO_ANTERIOR (o «kid» indica qual das duas assinou).
 * - Vida curta (15 min) e reivindicações verificadas: iss, aud, sub, iat, nbf, exp (com 30 s de tolerância),
 *   jti, versão das credenciais e ligação à sessão (sid) — terminar a sessão, mudar a palavra-passe ou os dois
 *   passos invalida logo todos os tokens dessa pessoa (revogação sem lista negra).
 * - O token não leva dados sensíveis (só identificadores); o navegador guarda-o só em memória.
 */
defined('NAVAL_NUCLEO') || exit;

const NAVAL_JWT_DURACAO = 900;      // 15 minutos
const NAVAL_JWT_TOLERANCIA = 30;    // diferença de relógio aceite (s)
const NAVAL_JWT_AUDIENCIA = 'portal-rtm-api';

function naval_b64url(string $s): string
{
    return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}

function naval_b64url_ler(string $s): string
{
    if (!preg_match('/^[A-Za-z0-9_-]*$/', $s)) return '';
    $r = base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
    return is_string($r) ? $r : '';
}

/** Chaves de assinatura [kid => chave]: a actual e, durante uma rotação, a anterior. */
function naval_jwt_chaves(): array
{
    $out = [];
    foreach (['JWT_SEGREDO', 'JWT_SEGREDO_ANTERIOR'] as $k) {
        $v = naval_config($k);
        if ($v === '') continue;
        if (strlen($v) < 32) {
            if ($k === 'JWT_SEGREDO') throw new NavalErro('Configuração insegura no servidor: JWT_SEGREDO tem de ter pelo menos 32 caracteres.', 503);
            continue;
        }
        $out[substr(hash('sha256', $v), 0, 12)] = $v;
    }
    if (!$out) naval_exigir_config(['JWT_SEGREDO'], 'a API (tokens de acesso)');
    return $out;
}

/** Emissor: o endereço do portal (PORTAL_URL) ou, sem ele, o anfitrião do pedido. */
function naval_jwt_emissor(): string
{
    $u = rtrim(naval_config('PORTAL_URL'), '/');
    return $u !== '' ? $u : 'portal-rtm';
}

/** Ligação do token à sessão PHP (sem expor o identificador da sessão). */
function naval_jwt_sid(string $sessao, string $chave): string
{
    return substr(hash_hmac('sha256', 'sessao|' . $sessao, $chave), 0, 32);
}

/** Emite um token de acesso para a conta da sessão actual. */
function naval_jwt_emitir(array $u, string $sessao, int $duracao = NAVAL_JWT_DURACAO): array
{
    $chaves = naval_jwt_chaves();
    $kid = (string) naval_primeira_chave($chaves);
    $chave = $chaves[$kid];
    $agora = time();
    $cab = ['alg' => 'HS256', 'typ' => 'JWT', 'kid' => $kid];
    $corpo = [
        'iss' => naval_jwt_emissor(),
        'aud' => NAVAL_JWT_AUDIENCIA,
        'sub' => (string) $u['login'],
        'iat' => $agora,
        'nbf' => $agora,
        'exp' => $agora + $duracao,
        'jti' => bin2hex(random_bytes(16)),
        'ver' => (int) ($u['versao'] ?? 0),
        'sid' => naval_jwt_sid($sessao, $chave),
    ];
    $base = naval_b64url((string) json_encode($cab)) . '.' . naval_b64url((string) json_encode($corpo));
    $token = $base . '.' . naval_b64url(hash_hmac('sha256', $base, $chave, true));
    return ['access_token' => $token, 'token_type' => 'Bearer', 'expires_in' => $duracao, 'expira_em' => $corpo['exp']];
}

/** Primeira chave de um array (array_key_first só existe no PHP 7.3+). */
function naval_primeira_chave(array $a)
{
    foreach ($a as $k => $_) return $k;
    return null;
}

/**
 * Valida um token e devolve as reivindicações. Lança NavalErro 401 (com «codigo») se não for válido.
 * A ligação à sessão e à conta é verificada por naval_jwt_autenticar().
 */
function naval_jwt_validar(string $token): array
{
    $recusar = function (string $codigo, string $msg = 'Token de acesso inválido.') {
        throw new NavalErro($msg, 401, ['codigo' => $codigo]);
    };
    if (strlen($token) > 4096 || substr_count($token, '.') !== 2) $recusar('token_malformado');
    [$c64, $p64, $s64] = explode('.', $token);
    $cab = json_decode(naval_b64url_ler($c64), true);
    $corpo = json_decode(naval_b64url_ler($p64), true);
    $assin = naval_b64url_ler($s64);
    if (!is_array($cab) || !is_array($corpo) || $assin === '') $recusar('token_malformado');
    // algoritmo fixo: nunca confiar no «alg» do token (RFC 8725 §3.1)
    if (($cab['alg'] ?? '') !== 'HS256' || ($cab['typ'] ?? 'JWT') !== 'JWT' || isset($cab['jku']) || isset($cab['jwk']) || isset($cab['x5u'])) $recusar('token_algoritmo');
    $chaves = naval_jwt_chaves();
    $chave = $chaves[(string) ($cab['kid'] ?? '')] ?? null;
    if ($chave === null || !hash_equals(hash_hmac('sha256', $c64 . '.' . $p64, $chave, true), $assin)) $recusar('token_assinatura');
    $agora = time();
    foreach (['iss', 'aud', 'sub', 'iat', 'nbf', 'exp', 'jti', 'sid'] as $k) if (!isset($corpo[$k])) $recusar('token_incompleto');
    if ($corpo['iss'] !== naval_jwt_emissor() || $corpo['aud'] !== NAVAL_JWT_AUDIENCIA) $recusar('token_emissor');
    if ((int) $corpo['nbf'] > $agora + NAVAL_JWT_TOLERANCIA || (int) $corpo['iat'] > $agora + NAVAL_JWT_TOLERANCIA) $recusar('token_futuro');
    if ((int) $corpo['exp'] <= $agora - NAVAL_JWT_TOLERANCIA) $recusar('token_expirado', 'O token de acesso expirou.');
    if ((int) $corpo['exp'] - (int) $corpo['iat'] > NAVAL_JWT_DURACAO + NAVAL_JWT_TOLERANCIA) $recusar('token_duracao');
    $corpo['_chave'] = $chave;
    return $corpo;
}

/**
 * Autenticação de um pedido à API: token Bearer válido, da conta da sessão aberta (mesma sessão, mesma
 * versão das credenciais, conta activa). Devolve as reivindicações. Lança NavalErro 401.
 */
function naval_jwt_autenticar(): array
{
    $h = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if ($h === '' && function_exists('apache_request_headers')) {
        foreach ((array) apache_request_headers() as $k => $v) if (strcasecmp((string) $k, 'Authorization') === 0) $h = (string) $v;
    }
    if (!preg_match('/^Bearer\s+([A-Za-z0-9_.-]+)$/', trim($h), $m)) {
        throw new NavalErro('Autenticação necessária.', 401, ['codigo' => 'token_em_falta']);
    }
    $c = naval_jwt_validar($m[1]);
    naval_iniciar_sessao();
    $u = naval_autenticado() ? naval_utilizador() : null;
    if (!$u || (string) $u['login'] !== (string) $c['sub'] || !hash_equals(naval_jwt_sid(session_id(), $c['_chave']), (string) $c['sid'])) {
        throw new NavalErro('A sessão terminou. Entre de novo.', 401, ['codigo' => 'sessao_terminada']);
    }
    if ((int) ($u['versao'] ?? 0) !== (int) $c['ver']) throw new NavalErro('As credenciais mudaram. Entre de novo.', 401, ['codigo' => 'credenciais_alteradas']);
    unset($c['_chave']);
    return $c;
}
