<?php
/**
 * App no telemóvel: instalação só pelo código QR do portal, registo do equipamento e entrada com Face ID ou impressão
 * digital (chaves de acesso FIDO2/WebAuthn, «passkeys»). Mesmo modelo de associação de equipamentos do WhatsApp e do
 * Microsoft Teams; recomendações NIST SP 800-63B (autenticador ligado ao equipamento, com verificação biométrica) e
 * OWASP ASVS 2.x.
 *
 * Regras:
 *  - o código QR é pessoal, de uso único, válido 10 minutos e só se gera numa sessão autenticada do portal;
 *  - a app só funciona depois de activada com esse código (servidor: a sessão da app fica ligada ao equipamento);
 *  - um equipamento activo por pessoa: activar noutro desactiva o anterior (registado e avisado por correio);
 *  - o equipamento activo renova-se sozinho com o uso e expira após 90 dias sem uso;
 *  - a biometria nunca sai do telemóvel: o servidor guarda só a chave pública e confirma a assinatura.
 */
defined('NAVAL_NUCLEO') || exit;

const NAVAL_APP_CODIGO_MIN = 10;        // validade do código QR
const NAVAL_APP_DIAS_SEM_USO = 90;      // o equipamento expira após 90 dias sem uso
const NAVAL_APP_NOMES = ['gestao' => 'Naval Gestão', 'portal' => 'Portal RTM'];
const NAVAL_APP_DESTINO = ['gestao' => '../gestao/', 'portal' => '../reports/'];

function naval_app_pasta(string $sub = ''): string
{
    $d = naval_pasta_privada() . '/apps' . ($sub !== '' ? '/' . $sub : '');
    if (!is_dir($d)) @mkdir($d, 0700, true);
    return $d;
}
require_once __DIR__ . '/../seguranca/jwt.php';   // naval_b64url e naval_b64url_ler

/** App de cada perfil: a app de gestão para quem a tem na matriz de acessos; as restantes pessoas, o portal. */
function naval_app_da_conta(): string
{
    return naval_pode('app-gestao') ? 'gestao' : 'portal';
}

// ───────────── páginas da app: rapidez ─────────────
/**
 * Páginas da app (instalar, activar, entrar) num só pedido: estilos, tema e funções dentro da página, letra do sistema
 * do telemóvel e tradução descarregada só quando o idioma escolhido não é o português. Na rede móvel cada pedido a
 * mais custa uma ida e volta ao servidor; assim a página aparece com a primeira resposta.
 */
function naval_app_cabeca(): string
{
    $r = dirname(__DIR__, 2);
    return '<style>' . file_get_contents($r . '/app/app.css') . '</style>' . "\n" . '<script>' . file_get_contents($r . '/reports/js/tema-arranque.js') . '</script>';
}
function naval_app_scripts(): string
{
    $r = dirname(__DIR__, 2);
    return '<script>' . file_get_contents($r . '/app/app.js') . '</script>' . "\n"
        . '<script>try{if((localStorage.getItem("vm-lang")||"pt")!=="pt"){var s=document.createElement("script");s.src="../geo/login-idioma.js";document.body.appendChild(s)}}catch(e){}</script>';
}
/** Sistema do telemóvel pelo navegador (para mostrar logo os passos certos, sem esperar pelo JavaScript). */
function naval_app_so(string $ua): string
{
    if (preg_match('/iPhone|iPad|iPod/', $ua)) return 'ios';
    if (stripos($ua, 'Android') !== false) return 'android';
    return 'outro';
}

// ───────────── código QR ─────────────
function naval_app_codigo_ficheiro(string $codigo): string
{
    return naval_app_pasta('codigos') . '/' . hash('sha256', $codigo) . '.json';
}
/** Novo código de instalação (o servidor guarda só o resumo SHA-256). */
function naval_app_codigo_novo(string $login, string $app): array
{
    foreach (glob(naval_app_pasta('codigos') . '/*.json') ?: [] as $f) if (filemtime($f) < time() - 86400) @unlink($f);   // nosemgrep: unlink-use -- códigos da própria pasta privada, nome derivado de hash
    $codigo = naval_b64url(random_bytes(24));
    $ate = time() + NAVAL_APP_CODIGO_MIN * 60;
    naval_json_gravar(naval_app_codigo_ficheiro($codigo), ['login' => $login, 'app' => $app, 'ate' => $ate, 'usado' => null], false);
    naval_registar('app_codigo_gerado', ['app' => $app], $login);
    return ['codigo' => $codigo, 'ate' => date('Y-m-d H:i:s', $ate), 'url' => naval_app_url('app/instalar.php?c=' . $codigo), 'app' => $app, 'nome' => NAVAL_APP_NOMES[$app]];
}
function naval_app_codigo_valido(string $c): bool
{
    return (bool) preg_match('/^[A-Za-z0-9_-]{32}$/', $c);
}
/** Código ainda por usar e dentro da validade (não o gasta). */
function naval_app_codigo(string $codigo): ?array
{
    if (!naval_app_codigo_valido($codigo)) return null;
    $x = naval_json_ler(naval_app_codigo_ficheiro($codigo));
    if (!$x || !empty($x['usado']) || (int) ($x['ate'] ?? 0) < time()) return null;
    return $x;
}
/** Registo do código, mesmo já usado ou fora de validade (para saber a app ao reabrir pelo ícone). */
function naval_app_codigo_registo(string $codigo): ?array
{
    if (!naval_app_codigo_valido($codigo)) return null;
    $x = naval_json_ler(naval_app_codigo_ficheiro($codigo));
    return $x ?: null;
}
/** Cookie do equipamento (só o identificador, não dá acesso): leva a app à entrada rápida com a biometria. */
function naval_app_cookie(string $id): void
{
    $vale = $id === '' ? time() - 3600 : time() + NAVAL_APP_DIAS_SEM_USO * 86400;
    header('Set-Cookie: naval_eq=' . ($id === '' ? 'x' : $id) . '; Expires=' . gmdate('D, d M Y H:i:s', $vale) . ' GMT; Path=/; HttpOnly; SameSite=Lax' . (naval_https() ? '; Secure' : ''), false);
}

/** Endereço público do portal (PORTAL_URL) ou o do próprio pedido. */
function naval_app_url(string $caminho): string
{
    $base = rtrim(naval_config('PORTAL_URL'), '/');
    if ($base === '') $base = (naval_https() ? 'https' : 'http') . '://' . preg_replace('/[^A-Za-z0-9.:-]/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    return $base . '/' . ltrim($caminho, '/');
}

/** Manifesto W3C da app instalada pelo código (já validado por naval_app_codigo): início em activar.php com o código. */
function naval_app_manifesto(array $c, string $codigo): void
{
    header('Content-Type: application/manifest+json; charset=utf-8');
    header('Cache-Control: no-store');
    $nome = NAVAL_APP_NOMES[$c['app']];
    echo json_encode([
        'id' => 'app-' . $c['app'],
        'name' => 'Grupo Naval · ' . $nome,
        'short_name' => $nome,
        'description' => $c['app'] === 'gestao' ? 'Vendas do dia, rotas, alertas e assistente de gestão do Grupo Naval.' : 'Portal RTM do Grupo Naval.',
        'lang' => 'pt-PT',
        'dir' => 'ltr',
        'start_url' => 'activar.php?c=' . rawurlencode($codigo),
        'scope' => '../',
        'display' => 'standalone',
        'orientation' => 'portrait',
        'background_color' => '#060d20',
        'theme_color' => '#273777',
        'icons' => [
            ['src' => 'icones/icone-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
            ['src' => 'icones/icone-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
            ['src' => 'icones/icone-maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

// ───────────── equipamentos ─────────────
function naval_app_ficheiro(): string
{
    return naval_app_pasta() . '/equipamentos.json';
}
function naval_app_lista(): array
{
    return (array) (naval_json_ler(naval_app_ficheiro())['equipamentos'] ?? []);
}
function naval_app_gravar(array $l): void
{
    naval_json_gravar(naval_app_ficheiro(), ['equipamentos' => $l]);
}
/** Sistema e modelo a partir da identificação do navegador (o iPhone não diz o modelo exacto). */
function naval_app_equipamento(string $ua): array
{
    if (preg_match('/iPhone OS (\d+)[_.](\d+)/', $ua, $m)) return ['so' => 'iOS ' . $m[1] . '.' . $m[2], 'modelo' => 'iPhone'];
    if (preg_match('/iPad.*OS (\d+)[_.](\d+)/', $ua, $m)) return ['so' => 'iPadOS ' . $m[1] . '.' . $m[2], 'modelo' => 'iPad'];
    if (preg_match('/Android ([\d.]+)(?:; ([^;)]+))?/', $ua, $m)) {
        $mod = trim((string) ($m[2] ?? ''));
        return ['so' => 'Android ' . $m[1], 'modelo' => $mod !== '' && $mod !== 'K' && stripos($mod, 'wv') === false ? $mod : 'Android'];
    }
    if (stripos($ua, 'Windows') !== false) return ['so' => 'Windows', 'modelo' => 'Computador'];
    if (stripos($ua, 'Mac OS X') !== false) return ['so' => 'macOS', 'modelo' => 'Computador'];
    return ['so' => 'Outro', 'modelo' => 'Desconhecido'];
}
/** Equipamento activo (e dentro dos 90 dias de uso) ou null. */
function naval_app_activo(string $id): ?array
{
    if (!preg_match('/^[a-f0-9]{24}$/', $id)) return null;
    $x = naval_app_lista()[$id] ?? null;
    if (!$x || ($x['estado'] ?? '') !== 'activo') return null;
    if (strtotime((string) $x['ultimo_uso']) < time() - NAVAL_APP_DIAS_SEM_USO * 86400) {
        naval_app_desactivar($id, 'sistema', 'expirado');
        return null;
    }
    return $x;
}
/** Renova o equipamento com o uso (grava no máximo uma vez por hora). */
function naval_app_usar(string $id): void
{
    $x = naval_app_lista()[$id] ?? null;
    if (!$x || strtotime((string) $x['ultimo_uso']) > time() - 3600) return;
    naval_exclusivo('apps', function () use ($id) {
        $l = naval_app_lista();
        if (!isset($l[$id])) return;
        $l[$id]['ultimo_uso'] = date('Y-m-d H:i:s');
        naval_app_gravar($l);
    });
}
/**
 * Activa a app neste equipamento com o código QR (gasta-o). Desactiva o equipamento anterior da mesma pessoa.
 * Devolve [equipamento, erro].
 */
function naval_app_activar(string $codigo, string $ua, string $ip): array
{
    $r = naval_exclusivo('apps', function () use ($codigo, $ua, $ip) {
        $c = naval_app_codigo($codigo);
        if (!$c) return [null, 'O código expirou ou já foi usado. No portal, abra «App no telemóvel» e leia um código novo.'];
        $u = naval_utilizadores()[$c['login']] ?? null;
        if (!$u || !naval_conta_valida($u)) return [null, 'Esta conta está desactivada ou expirou. Contacte o administrador da plataforma.'];
        $f = naval_app_codigo_ficheiro($codigo);
        naval_json_gravar($f, ['usado' => date('Y-m-d H:i:s')] + naval_json_ler($f), false);
        $l = naval_app_lista();
        $id = bin2hex(random_bytes(12));
        $anteriores = [];
        foreach ($l as $k => $x) {
            if (($x['login'] ?? '') === $c['login'] && ($x['estado'] ?? '') === 'activo') {
                $l[$k]['estado'] = 'substituido';
                $l[$k]['fim'] = date('Y-m-d H:i:s');
                $l[$k]['motivo'] = 'novo_equipamento';
                $l[$k]['credenciais'] = [];
                $anteriores[] = $l[$k];
            }
        }
        $agora = date('Y-m-d H:i:s');
        $l[$id] = ['id' => $id, 'login' => $c['login'], 'app' => $c['app']] + naval_app_equipamento($ua)
            + ['navegador' => substr($ua, 0, 300), 'activado' => $agora, 'ultimo_uso' => $agora, 'ip' => $ip, 'estado' => 'activo', 'credenciais' => []];
        naval_app_gravar($l);
        return [$l[$id], null, $anteriores, $u];
    });
    if (!$r[0]) return [null, $r[1]];
    [$eq, , $anteriores, $u] = $r;
    foreach ($anteriores as $a) naval_registar('app_substituida', ['equipamento' => $a['id'], 'modelo' => $a['modelo'], 'so' => $a['so'], 'por' => $eq['id']], $eq['login']);
    naval_registar('app_activada', ['equipamento' => $eq['id'], 'app' => $eq['app'], 'modelo' => $eq['modelo'], 'so' => $eq['so']], $eq['login']);
    naval_app_avisar($u, $eq, $anteriores);
    return [$eq, null];
}
function naval_app_desactivar(string $id, string $por, string $motivo): bool
{
    $x = naval_exclusivo('apps', function () use ($id, $motivo) {
        $l = naval_app_lista();
        if (!isset($l[$id]) || ($l[$id]['estado'] ?? '') !== 'activo') return null;
        $l[$id]['estado'] = $motivo === 'expirado' ? 'expirado' : 'desactivado';
        $l[$id]['fim'] = date('Y-m-d H:i:s');
        $l[$id]['motivo'] = $motivo;
        $l[$id]['credenciais'] = [];
        naval_app_gravar($l);
        return $l[$id];
    });
    if (!$x) return false;
    naval_registar('app_desactivada', ['equipamento' => $id, 'conta' => $x['login'], 'por' => $por, 'motivo' => $motivo], $por === 'sistema' ? $x['login'] : null);
    return true;
}
/** Resumo para o portal (sem a identificação completa do navegador nem as chaves). */
function naval_app_resumo(array $x): array
{
    return ['id' => $x['id'], 'login' => $x['login'], 'app' => $x['app'], 'app_nome' => NAVAL_APP_NOMES[$x['app']] ?? $x['app'], 'so' => $x['so'], 'modelo' => $x['modelo'],
        'activado' => $x['activado'], 'ultimo_uso' => $x['ultimo_uso'], 'estado' => $x['estado'], 'fim' => $x['fim'] ?? null, 'motivo' => $x['motivo'] ?? null,
        'biometria' => !empty($x['credenciais'])];
}
function naval_app_avisar(array $u, array $eq, array $anteriores): void
{
    if (!function_exists('naval_enviar_email')) naval_servico('email');
    if (!naval_email_valido($u['email'] ?? null)) return;
    $h = (int) date('G');
    $nome = htmlspecialchars((string) ($u['nome'] ?? $eq['login']), ENT_QUOTES, 'UTF-8');
    $equip = htmlspecialchars($eq['modelo'] . ' (' . $eq['so'] . ')', ENT_QUOTES, 'UTF-8');
    $ant = $anteriores ? ' O equipamento anterior (' . htmlspecialchars($anteriores[0]['modelo'] . ', ' . $anteriores[0]['so'], ENT_QUOTES, 'UTF-8') . ') foi desactivado.' : '';
    naval_enviar_email([['email' => $u['email'], 'nome' => $u['nome'] ?? $eq['login']]], 'Portal RTM: app activada num equipamento',
        '<div style="font-family:Segoe UI,Arial,sans-serif;font-size:14px;color:#0b1f3a;line-height:1.55;text-align:justify;max-width:560px">'
        . '<p>' . ($h < 12 ? 'Bom dia,' : ($h < 20 ? 'Boa tarde,' : 'Boa noite,')) . '</p><p>' . $nome . ', a app «' . htmlspecialchars(NAVAL_APP_NOMES[$eq['app']], ENT_QUOTES, 'UTF-8')
        . '» foi activada em ' . date('d-m-Y') . ' às ' . date('H:i') . ' no equipamento ' . $equip . '.' . $ant . '</p>'
        . '<p>Se não foi a sua pessoa, contacte de imediato o super administrador da plataforma.</p>'
        . '<p>Obrigado.</p><p>Cumps,<br>Portal RTM · Grupo Naval</p></div>');
}

// ───────────── chaves de acesso (WebAuthn) ─────────────
/** Domínio da chave (rpId) e origem esperada: os do próprio pedido (o navegador garante que coincidem com o site). */
function naval_wa_rp(): array
{
    $host = strtolower((string) preg_replace('/[^A-Za-z0-9.:-]/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost')));
    $rp = (string) preg_replace('/:\d+$/', '', $host);
    return [$rp, (naval_https() ? 'https' : 'http') . '://' . $host];
}
/** Descodificador CBOR mínimo (RFC 8949) para os objectos do WebAuthn: inteiros, bytes, texto, listas, mapas e simples. */
function naval_cbor(string $b, int &$p)
{
    if ($p >= strlen($b)) throw new RuntimeException('CBOR truncado');
    $ib = ord($b[$p++]);
    $mt = $ib >> 5;
    $ai = $ib & 31;
    if ($mt === 7) {
        if ($ai === 20) return false;
        if ($ai === 21) return true;
        if ($ai === 22 || $ai === 23) return null;
        throw new RuntimeException('CBOR: tipo simples não suportado');
    }
    if ($ai < 24) $v = $ai;
    elseif ($ai === 24) $v = ord($b[$p++]);
    elseif ($ai === 25) { $v = (int) unpack('n', substr($b, $p, 2))[1]; $p += 2; }
    elseif ($ai === 26) { $v = (int) unpack('N', substr($b, $p, 4))[1]; $p += 4; }
    elseif ($ai === 27) { $v = (int) unpack('J', substr($b, $p, 8))[1]; $p += 8; }
    else throw new RuntimeException('CBOR: comprimento indefinido não suportado');
    switch ($mt) {
        case 0: return $v;
        case 1: return -1 - $v;
        case 2:
        case 3:
            $v = (int) $v;
            $s = substr($b, $p, $v);
            $p += $v;
            return $s;
        case 4:
            $a = [];
            for ($i = 0; $i < $v; $i++) $a[] = naval_cbor($b, $p);
            return $a;
        case 5:
            $m = [];
            for ($i = 0; $i < $v; $i++) {
                $k = naval_cbor($b, $p);
                $m[$k] = naval_cbor($b, $p);
            }
            return $m;
    }
    throw new RuntimeException('CBOR: tipo não suportado');
}
function naval_der(int $tag, string $c): string
{
    $n = strlen($c);
    $len = $n < 128 ? chr($n) : ($n < 256 ? "\x81" . chr($n) : "\x82" . pack('n', $n));
    return chr($tag) . $len . $c;
}
/** Chave pública COSE (ES256 P-256 ou RS256) em PEM para o openssl. */
function naval_cose_pem(array $k): array
{
    $alg = (int) ($k[3] ?? 0);
    if (($k[1] ?? 0) === 2 && $alg === -7 && ($k[-1] ?? 0) === 1 && strlen((string) ($k[-2] ?? '')) === 32 && strlen((string) ($k[-3] ?? '')) === 32) {
        $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . "\x04" . $k[-2] . $k[-3];
    } elseif (($k[1] ?? 0) === 3 && $alg === -257 && !empty($k[-1]) && !empty($k[-2])) {
        $int = function (string $x) { return naval_der(0x02, (ord($x[0]) > 127 ? "\x00" : '') . $x); };
        $rsa = naval_der(0x30, $int((string) $k[-1]) . $int((string) $k[-2]));
        $der = naval_der(0x30, naval_der(0x30, hex2bin('06092a864886f70d0101010500')) . naval_der(0x03, "\x00" . $rsa));
    } else {
        throw new RuntimeException('Tipo de chave não suportado');
    }
    return [$alg, "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n"];
}
/** Dados do autenticador: domínio, presença e verificação da pessoa (biometria ou código do telemóvel). */
function naval_wa_auth_data(string $ad, string $rp): array
{
    if (strlen($ad) < 37) throw new RuntimeException('Dados do autenticador incompletos');
    if (!hash_equals(hash('sha256', $rp, true), substr($ad, 0, 32))) throw new RuntimeException('Domínio diferente');
    $flags = ord($ad[32]);
    if (!($flags & 0x01)) throw new RuntimeException('Sem presença da pessoa');
    if (!($flags & 0x04)) throw new RuntimeException('Sem verificação biométrica');
    return ['flags' => $flags, 'contador' => unpack('N', substr($ad, 33, 4))[1]];
}
function naval_wa_cliente(string $json, string $tipo, string $desafio, string $origem): void
{
    $c = json_decode($json, true);
    if (!is_array($c) || ($c['type'] ?? '') !== $tipo) throw new RuntimeException('Tipo de pedido errado');
    if (!hash_equals($desafio, (string) ($c['challenge'] ?? ''))) throw new RuntimeException('Desafio diferente');
    if (($c['origin'] ?? '') !== $origem) throw new RuntimeException('Origem diferente');
}
/** Novo desafio (guardado na sessão, válido 5 minutos). */
function naval_wa_desafio(string $para): string
{
    $d = naval_b64url(random_bytes(32));
    $_SESSION['wa_desafio'] = ['d' => $d, 'para' => $para, 'ate' => time() + 300];
    return $d;
}
function naval_wa_tirar_desafio(string $para): string
{
    $x = $_SESSION['wa_desafio'] ?? null;
    unset($_SESSION['wa_desafio']);
    if (!$x || $x['para'] !== $para || $x['ate'] < time()) throw new RuntimeException('O pedido expirou. Tente de novo.');
    return (string) $x['d'];
}
/** Opções para criar a chave de acesso no telemóvel (Face ID, Touch ID ou impressão digital). */
function naval_wa_opcoes_registo(array $eq, array $u): array
{
    [$rp] = naval_wa_rp();
    return [
        'challenge' => naval_wa_desafio('registo|' . $eq['id']),
        'rp' => ['id' => $rp, 'name' => 'Portal RTM · Grupo Naval'],
        'user' => ['id' => naval_b64url(hash('sha256', 'naval|' . $eq['login'], true)), 'name' => $eq['login'], 'displayName' => (string) ($u['nome'] ?? $eq['login'])],
        'pubKeyCredParams' => [['type' => 'public-key', 'alg' => -7], ['type' => 'public-key', 'alg' => -257]],
        'authenticatorSelection' => ['authenticatorAttachment' => 'platform', 'userVerification' => 'required', 'residentKey' => 'preferred'],
        'attestation' => 'none',
        'timeout' => 120000,
        'excludeCredentials' => array_map(function ($c) { return ['type' => 'public-key', 'id' => $c['id']]; }, (array) $eq['credenciais']),
    ];
}
function naval_wa_registar(string $idEq, array $r): void
{
    [$rp, $origem] = naval_wa_rp();
    $desafio = naval_wa_tirar_desafio('registo|' . $idEq);
    naval_wa_cliente(naval_b64url_ler((string) ($r['clientDataJSON'] ?? '')), 'webauthn.create', $desafio, $origem);
    $p = 0;
    $att = naval_cbor(naval_b64url_ler((string) ($r['attestationObject'] ?? '')), $p);
    $ad = (string) ($att['authData'] ?? '');
    $f = naval_wa_auth_data($ad, $rp);
    if (!($f['flags'] & 0x40)) throw new RuntimeException('Sem chave nova');
    $n = unpack('n', substr($ad, 53, 2))[1];
    $n = (int) $n;
    $credId = substr($ad, 55, $n);
    $q = 55 + (int) $n;
    $cose = naval_cbor($ad, $q);
    [$alg, $pem] = naval_cose_pem((array) $cose);
    $id = naval_b64url($credId);
    $ok = naval_exclusivo('apps', function () use ($idEq, $id, $alg, $pem, $f) {
        $l = naval_app_lista();
        if (($l[$idEq]['estado'] ?? '') !== 'activo') return false;
        $l[$idEq]['credenciais'] = [['id' => $id, 'alg' => $alg, 'pem' => $pem, 'contador' => $f['contador'], 'criada' => date('Y-m-d H:i:s')]];
        naval_app_gravar($l);
        return true;
    });
    if (!$ok) throw new RuntimeException('Este equipamento já não está activo.');
    naval_registar('app_biometria_activada', ['equipamento' => $idEq]);
}
/** Opções para entrar com a chave de acesso deste equipamento. */
function naval_wa_opcoes_entrada(array $eq): array
{
    [$rp] = naval_wa_rp();
    return [
        'challenge' => naval_wa_desafio('entrada|' . $eq['id']),
        'rpId' => $rp,
        'allowCredentials' => array_map(function ($c) { return ['type' => 'public-key', 'id' => $c['id']]; }, (array) $eq['credenciais']),
        'userVerification' => 'required',
        'timeout' => 120000,
    ];
}
/** Confirma a assinatura da chave de acesso; devolve o equipamento. */
function naval_wa_entrar(string $idEq, array $r): array
{
    [$rp, $origem] = naval_wa_rp();
    $desafio = naval_wa_tirar_desafio('entrada|' . $idEq);
    $eq = naval_app_activo($idEq);
    if (!$eq) throw new RuntimeException('Esta app foi desactivada ou activada noutro equipamento.');
    $cred = null;
    foreach ((array) $eq['credenciais'] as $c) if (hash_equals((string) $c['id'], (string) ($r['id'] ?? ''))) $cred = $c;
    if (!$cred) throw new RuntimeException('Chave de acesso desconhecida neste equipamento.');
    $cd = naval_b64url_ler((string) ($r['clientDataJSON'] ?? ''));
    naval_wa_cliente($cd, 'webauthn.get', $desafio, $origem);
    $ad = naval_b64url_ler((string) ($r['authenticatorData'] ?? ''));
    $f = naval_wa_auth_data($ad, $rp);
    if (openssl_verify($ad . hash('sha256', $cd, true), naval_b64url_ler((string) ($r['signature'] ?? '')), (string) $cred['pem'], OPENSSL_ALGO_SHA256) !== 1) {
        throw new RuntimeException('Assinatura inválida');
    }
    // contador: se o autenticador o usa, tem de subir (protege contra chaves copiadas)
    if (($f['contador'] || $cred['contador']) && $f['contador'] <= $cred['contador']) throw new RuntimeException('Contador da chave não subiu');
    naval_exclusivo('apps', function () use ($idEq, $f) {
        $l = naval_app_lista();
        if (!isset($l[$idEq]['credenciais'][0])) return;
        $l[$idEq]['credenciais'][0]['contador'] = $f['contador'];
        $l[$idEq]['ultimo_uso'] = date('Y-m-d H:i:s');
        naval_app_gravar($l);
    });
    return $eq;
}
