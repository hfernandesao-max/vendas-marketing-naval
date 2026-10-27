<?php
// API central e JWT (RFC 7519 / RFC 8725): emissão, validação estrita, rotação de chaves, rotas e cache.
require_once dirname(__DIR__, 2) . '/nucleo/api/router.php';

/** Token com cabeçalho e corpo à escolha, assinado com $chave (para testar recusas). */
function jwt_teste(array $cab, array $corpo, string $chave): string
{
    $b = naval_b64url((string) json_encode($cab)) . '.' . naval_b64url((string) json_encode($corpo));
    return $b . '.' . naval_b64url(hash_hmac('sha256', $b, $chave, true));
}

function jwt_corpo(array $extra = []): array
{
    $t = time();
    return $extra + ['iss' => naval_jwt_emissor(), 'aud' => NAVAL_JWT_AUDIENCIA, 'sub' => 'teste', 'iat' => $t, 'nbf' => $t, 'exp' => $t + 600, 'jti' => 'x', 'ver' => 0, 'sid' => 'y'];
}

teste('JWT: emite e valida um token HS256 com as reivindicações da norma', function () {
    $t = naval_jwt_emitir(['login' => 'teste', 'versao' => 3], 'sessao-abc');
    igual('Bearer', $t['token_type']);
    igual(900, $t['expires_in']);
    $c = naval_jwt_validar($t['access_token']);
    igual('teste', $c['sub']);
    igual(3, $c['ver']);
    igual(NAVAL_JWT_AUDIENCIA, $c['aud']);
    igual(naval_jwt_sid('sessao-abc', $c['_chave']), $c['sid']);
    verdade(strlen($c['jti']) === 32, 'jti aleatório');
    $cab = json_decode(naval_b64url_ler(explode('.', $t['access_token'])[0]), true);
    igual('HS256', $cab['alg']);
});

teste('JWT: recusa alg none, outro algoritmo, assinatura alterada e chave errada', function () {
    $k = naval_jwt_chaves();
    $kid = (string) naval_primeira_chave($k);
    $chave = $k[$kid];
    igual('token_algoritmo', lanca(401, function () use ($kid) {
        naval_jwt_validar(naval_b64url('{"alg":"none","typ":"JWT","kid":"' . $kid . '"}') . '.' . naval_b64url((string) json_encode(jwt_corpo())) . '.' . naval_b64url('x'));
    })->extra()['codigo']);
    igual('token_algoritmo', lanca(401, function () use ($kid, $chave) { naval_jwt_validar(jwt_teste(['alg' => 'HS512', 'typ' => 'JWT', 'kid' => $kid], jwt_corpo(), $chave)); })->extra()['codigo']);
    igual('token_algoritmo', lanca(401, function () use ($kid, $chave) { naval_jwt_validar(jwt_teste(['alg' => 'HS256', 'kid' => $kid, 'jku' => 'https://x.test/k'], jwt_corpo(), $chave)); })->extra()['codigo']);
    igual('token_assinatura', lanca(401, function () use ($kid) { naval_jwt_validar(jwt_teste(['alg' => 'HS256', 'typ' => 'JWT', 'kid' => $kid], jwt_corpo(), str_repeat('z', 40))); })->extra()['codigo']);
    $bom = naval_jwt_emitir(['login' => 'teste'], 's')['access_token'];
    [$a, $b, $s] = explode('.', $bom);
    $outro = naval_b64url((string) json_encode(['sub' => 'admin'] + json_decode(naval_b64url_ler($b), true)));
    igual('token_assinatura', lanca(401, function () use ($a, $outro, $s) { naval_jwt_validar($a . '.' . $outro . '.' . $s); })->extra()['codigo']);
    igual('token_malformado', lanca(401, function () { naval_jwt_validar('abc.def'); })->extra()['codigo']);
    igual('token_malformado', lanca(401, function () { naval_jwt_validar(str_repeat('a', 5000) . '.b.c'); })->extra()['codigo']);
});

teste('JWT: expiração, início futuro, emissor/audiência e duração máxima', function () {
    $k = naval_jwt_chaves();
    $kid = (string) naval_primeira_chave($k);
    $cab = ['alg' => 'HS256', 'typ' => 'JWT', 'kid' => $kid];
    $t = time();
    igual('token_expirado', lanca(401, function () use ($cab, $k, $kid, $t) { naval_jwt_validar(jwt_teste($cab, jwt_corpo(['exp' => $t - 60, 'iat' => $t - 600, 'nbf' => $t - 600]), $k[$kid])); })->extra()['codigo']);
    igual('token_futuro', lanca(401, function () use ($cab, $k, $kid, $t) { naval_jwt_validar(jwt_teste($cab, jwt_corpo(['nbf' => $t + 300]), $k[$kid])); })->extra()['codigo']);
    igual('token_emissor', lanca(401, function () use ($cab, $k, $kid) { naval_jwt_validar(jwt_teste($cab, jwt_corpo(['aud' => 'outra-api']), $k[$kid])); })->extra()['codigo']);
    igual('token_duracao', lanca(401, function () use ($cab, $k, $kid, $t) { naval_jwt_validar(jwt_teste($cab, jwt_corpo(['exp' => $t + 86400]), $k[$kid])); })->extra()['codigo']);
    igual('token_incompleto', lanca(401, function () use ($cab, $k, $kid) { $c = jwt_corpo(); unset($c['sid']); naval_jwt_validar(jwt_teste($cab, $c, $k[$kid])); })->extra()['codigo']);
});

teste('JWT: tolerância de 30 s no relógio e Base64URL estrita', function () {
    $k = naval_jwt_chaves();
    $kid = (string) naval_primeira_chave($k);
    $t = time();
    $c = naval_jwt_validar(jwt_teste(['alg' => 'HS256', 'typ' => 'JWT', 'kid' => $kid], jwt_corpo(['exp' => $t - 10, 'iat' => $t - 800, 'nbf' => $t - 800]), $k[$kid]));
    igual('teste', $c['sub']);
    igual('', naval_b64url_ler('a+b/'));
    igual('ok', naval_b64url_ler(naval_b64url('ok')));
});

teste('rotas da API: lista fechada, versão e caracteres seguros', function () {
    $r = naval_api_rotas();
    verdade(isset($r['v1/vendas'], $r['v1/auth/token'], $r['v1/gestao']));
    igual(['POST'], $r['v1/auth/token'][0]);
    igual('sessao', $r['v1/auth/token'][1]);
    igual('publico', $r['v1/csp-relatorio'][1]);
    foreach ($r as $rota => $x) if (!in_array($rota, ['v1/auth/token', 'v1/csp-relatorio'], true)) igual('jwt', $x[1], $rota);
    foreach ($r as $x) verdade(is_file(dirname(__DIR__, 2) . '/nucleo/api/v1/' . $x[2]), $x[2]);
    $_SERVER['PATH_INFO'] = '/v1/vendas/estado';
    igual('v1/vendas/estado', naval_api_rota());
    foreach (['/v1/../config', '/v1/vendas.php', '/V1/vendas', '/v1/vendas%00'] as $mau) {
        $_SERVER['PATH_INFO'] = $mau;
        igual('', naval_api_rota(), $mau);
    }
    unset($_SERVER['PATH_INFO']);
});

teste('API: pedidos de outra origem recusados; mesma origem aceite', function () {
    $_SERVER['HTTP_HOST'] = 'geo.naval.co.ao:2051';
    $_SERVER['HTTP_ORIGIN'] = 'https://geo.naval.co.ao:2051';
    naval_api_origem();
    $_SERVER['HTTP_ORIGIN'] = 'https://atacante.test';
    lanca(403, function () { naval_api_origem(); });
    $_SERVER['HTTP_ORIGIN'] = 'null';
    lanca(403, function () { naval_api_origem(); });
    unset($_SERVER['HTTP_ORIGIN'], $_SERVER['HTTP_HOST']);
});

teste('cache do servidor: guarda, reutiliza, «fresco» recalcula e falhas (null) não ficam guardadas', function () {
    $n = 0;
    $f = function () use (&$n) { $n++; return ['n' => $n]; };
    igual(['n' => 1], naval_cache('teste-cache', 60, $f));
    igual(['n' => 1], naval_cache('teste-cache', 60, $f));
    igual(['n' => 2], naval_cache('teste-cache', 60, $f, true));
    $nulos = 0;
    naval_cache('teste-nulo', 60, function () use (&$nulos) { $nulos++; return null; });
    naval_cache('teste-nulo', 60, function () use (&$nulos) { $nulos++; return null; });
    igual(2, $nulos);
    igual(120, naval_cache_periodo(date('Ymd')));
    igual(1800, naval_cache_periodo('20250101'));
});
