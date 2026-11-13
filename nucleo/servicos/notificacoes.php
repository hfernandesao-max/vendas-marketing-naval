<?php
/**
 * Notificações no telemóvel (Web Push: RFC 8030 entrega, RFC 8291 cifra da mensagem, RFC 8292 VAPID), sem serviços
 * de terceiros nem bibliotecas: o servidor do portal assina e cifra cada mensagem e entrega-a ao serviço de push do
 * próprio telefone (Apple, Google, Mozilla, Microsoft). Funciona com a app fechada; no iPhone a app tem de estar no
 * ecrã principal (iOS 16.4 ou mais recente).
 *
 * Quem recebe o quê: cada tipo de notificação tem um recurso da matriz de acessos e um nível mínimo; quem trabalha
 * por rota (SUP, PRO) só recebe as das suas rotas. Cada pessoa liga e desliga os tipos e tem horas de silêncio
 * (por omissão 20:00–07:00, excepto o que for crítico). Tudo fica também na caixa de notificações da app (100 últimas).
 *
 * Ficheiros (fora da pasta pública): dados-privados/notificacoes/vapid.pem (chave privada do servidor, criada sozinha),
 * subscricoes.json, preferencias.json, enviadas.json (para nunca repetir) e caixa/<conta>.json.
 * Sem dados pessoais nas mensagens além do que a pessoa já vê no portal (rota, documento, valor).
 */
defined('NAVAL_NUCLEO') || exit;

/** tipo => [nome, descrição, recurso, nível mínimo, gravidade por omissão] */
const NAVAL_NOTIF_TIPOS = [
    'rota_parada' => ['Rota parada', 'Uma rota com vendas hoje está há mais de uma hora sem vender, ou ainda não vendeu às 10:30.', 'alertas', 'Vr', 'aviso'],
    'venda_anulada' => ['Venda anulada', 'Um documento de venda de hoje foi anulado no Pinguim BO.', 'alertas', 'Vr', 'aviso'],
    'caixa_verificar' => ['Caixa por aprovar (1.º nível)', 'Uma folha de caixa validada ou um depósito no banco espera pela aprovação de 1.º nível.', 'caixa-aprovacao', 'G', 'aviso'],
    'caixa_aprovar' => ['Caixa por aprovação final', 'Uma folha de caixa ou um depósito no banco espera pela aprovação final.', 'caixa-aprovacao-final', 'G', 'aviso'],
    'carrinha_aprovar' => ['Carrinha por aprovar', 'A contagem de uma carrinha tem diferenças à espera de aprovação.', 'conciliacao-carrinha', 'G', 'aviso'],
    'conferencia' => ['Diferença na conferência', 'A conferência diária com o Pinguim BO encontrou diferenças.', 'conformidade', 'V', 'critico'],
    'problema' => ['Problema em produção', 'A vigilância do portal encontrou um problema novo.', 'actualizacoes', 'V', 'critico'],
    'versao' => ['Actualizações do portal', 'Versão instalada, à espera de aprovação ou reposta.', 'actualizacoes', 'V', 'info'],
    'resumo_dia' => ['Resumo do dia', 'Às 18:30: vendas do dia, documentos e a melhor rota.', 'app-gestao', 'V', 'info'],
];
/** Textos das notificações em português, inglês e tigrínia (o telefone recebe-as no idioma em que está). */
const NAVAL_NOTIF_TEXTOS = [
    'parada' => ['pt' => ['Rota {r} parada', 'Sem vendas há {h} h {m} min (última às {u}). Hoje: {n} documentos.'],
        'en' => ['Route {r} stopped', 'No sales for {h} h {m} min (last at {u}). Today: {n} documents.'],
        'ti' => ['መስመር {r} ደው ኢሉ', 'ን{h} ሰዓት {m} ደቒቕ መሸጣ የለን (ናይ መወዳእታ ኣብ {u})። ሎሚ፦ {n} ሰነዳት።']],
    'semvendas' => ['pt' => ['Rota {r} sem vendas', 'Ainda não há nenhuma venda hoje.'],
        'en' => ['Route {r} has no sales', 'No sales yet today.'],
        'ti' => ['መስመር {r} መሸጣ የብሉን', 'ሎሚ ገና ዝኾነ መሸጣ የለን።']],
    'anulada' => ['pt' => ['Venda anulada · rota {r}', '{doc} de {v} Kz foi anulado no Pinguim BO.'],
        'en' => ['Sale cancelled · route {r}', '{doc} for {v} Kz was cancelled in Pinguim BO.'],
        'ti' => ['ዝተሰረዘ መሸጣ · መስመር {r}', '{doc} ናይ {v} Kz ኣብ Pinguim BO ተሰሪዙ።']],
    'resumo' => ['pt' => ['Resumo de hoje: {v} Kz', '{n} documentos. Melhor rota: {r} ({mv} Kz).'],
        'en' => ["Today's summary: {v} Kz", '{n} documents. Best route: {r} ({mv} Kz).'],
        'ti' => ['ጽማቝ ሎሚ፦ {v} Kz', '{n} ሰነዳት። ዝበለጸ መስመር፦ {r} ({mv} Kz)።']],
    'carrinha' => ['pt' => ['Carrinha {r} por aprovar', '{n} diferenças na contagem{falta}.'],
        'en' => ['Van {r} to approve', '{n} differences in the count{falta}.'],
        'ti' => ['ቫን {r} ክጸድቕ', '{n} ፍልልያት ኣብ ቆጸራ{falta}።']],
    'caixa1' => ['pt' => ['Folha de caixa de {d} por aprovar', 'Validada pela tesouraria: falta a aprovação de 1.º nível.'],
        'en' => ['Cash sheet for {d} to approve', 'Validated by treasury: first-level approval pending.'],
        'ti' => ['ናይ {d} ወረቐት ገንዘብ ክጸድቕ', 'ብግምጃ ቤት ተረጋጊጹ፤ ናይ ቀዳማይ ደረጃ ምጽዳቕ ይጽበ።']],
    'caixa2' => ['pt' => ['Folha de caixa de {d} para aprovação final', 'Pronta para a aprovação final do responsável.'],
        'en' => ['Cash sheet for {d} for final approval', 'Ready for the manager\'s final approval.'],
        'ti' => ['ናይ {d} ወረቐት ገንዘብ ንመወዳእታ ምጽዳቕ', 'ንናይ ሓላፊ መወዳእታ ምጽዳቕ ድሉው እዩ።']],
    'deposito' => ['pt' => ['Depósito no banco por aprovar', '{v} Kz no {b} (lacre {l}), folha de {d}.'],
        'en' => ['Bank deposit to approve', '{v} Kz at {b} (seal {l}), sheet of {d}.'],
        'ti' => ['ናብ ባንክ ዝኣቱ ገንዘብ ክጸድቕ', '{v} Kz ኣብ {b} (ማሕተም {l})፣ ወረቐት {d}።']],
    'carrinha_falta' => ['pt' => [', falta de {v} Kz'], 'en' => [', {v} Kz short'], 'ti' => ['፣ ጉድለት {v} Kz']],
    'conferencia' => ['pt' => ['Conferência de {d} com diferenças', '{n} rota(s) com documentos ou valores diferentes do Pinguim BO: {lista}.'],
        'en' => ['Reconciliation for {d} with differences', '{n} route(s) with documents or amounts different from Pinguim BO: {lista}.'],
        'ti' => ['ምንጽጻር ናይ {d} ፍልልያት ኣለዎ', '{n} መስመር(ታት) ካብ Pinguim BO ዝተፈልየ ሰነዳት ወይ ዋጋታት፦ {lista}።']],
    'problema' => ['pt' => ['Problema em produção', '{m}'], 'en' => ['Production problem', '{m}'], 'ti' => ['ጸገም ኣብ ስራሕ', '{m}']],
    'teste' => ['pt' => ['Notificações ligadas', 'Vai receber aqui os alertas do Portal RTM.'],
        'en' => ['Notifications on', 'You will receive Portal RTM alerts here.'],
        'ti' => ['ምልክታታት ተወሊዖም', 'መጠንቀቕታታት Portal RTM ኣብዚ ክትቕበሉ ኢኹም።']],
];

/** Título e texto de um modelo nos três idiomas: {pt: [título, texto], en: …, ti: …}. */
function naval_notif_txt(string $modelo, array $v): array
{
    $out = [];
    foreach (NAVAL_NOTIF_TEXTOS[$modelo] as $l => $par) {
        $out[$l] = array_map(function ($x) use ($v) {
            return strtr($x, array_combine(array_map(function ($k) { return '{' . $k . '}'; }, array_keys($v)), array_map('strval', array_values($v))));
        }, $par);
    }
    return $out;
}

/** Idioma de uma subscrição (o do telefone) ou, sem ele, o da conta; só pt, en ou ti. */
function naval_notif_idioma(?array $sub, array $u): string
{
    $l = strtolower(substr((string) ($sub['idioma'] ?? ''), 0, 2));
    if (!in_array($l, ['pt', 'en', 'ti'], true)) $l = (string) ($u['idioma'] ?? 'pt');
    return in_array($l, ['pt', 'en', 'ti'], true) ? $l : 'pt';
}

const NAVAL_NOTIF_SILENCIO = ['de' => '20:00', 'ate' => '07:00'];
const NAVAL_NOTIF_CAIXA_MAX = 100;
/** Serviços de push aceites (o endereço vem do telefone: nada de pedidos a outros servidores, OWASP SSRF). */
const NAVAL_NOTIF_SERVICOS = ['fcm.googleapis.com', 'android.googleapis.com', 'updates.push.services.mozilla.com', 'web.push.apple.com', 'api.push.apple.com'];

function naval_notif_pasta(string $sub = ''): string
{
    return naval_pasta('notificacoes' . ($sub !== '' ? '/' . $sub : ''));
}

function naval_b64u(string $b): string
{
    return rtrim(strtr(base64_encode($b), '+/', '-_'), '=');
}

function naval_b64u_dec(string $s): string
{
    return (string) base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
}

// ── Criptografia (VAPID e cifra da mensagem) ──────────────────────────────────────────────────────────────────────

/** Ponto público P-256 não comprimido (65 bytes) de uma chave EC do OpenSSL. */
function naval_ec_ponto($chave): string
{
    $d = openssl_pkey_get_details($chave);
    if (!$d || empty($d['ec']['x'])) throw new RuntimeException('Chave EC inválida.');
    return "\x04" . str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT);
}

/** Chave pública do OpenSSL a partir do ponto P-256 de 65 bytes (SubjectPublicKeyInfo). */
function naval_ec_publica(string $ponto)
{
    if (strlen($ponto) !== 65 || $ponto[0] !== "\x04") throw new NavalErro('Chave de subscrição inválida.', 400);
    $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $ponto;
    $k = openssl_pkey_get_public("-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n");
    if (!$k) throw new NavalErro('Chave de subscrição inválida.', 400);
    return $k;
}

function naval_ec_nova()
{
    $k = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    if (!$k) throw new RuntimeException('O OpenSSL não criou a chave EC.');
    return $k;
}

/** Chaves VAPID do servidor: [chave privada, ponto público em base64url]. Criadas na primeira vez (0600). */
function naval_vapid(): array
{
    static $c = null;
    if ($c) return $c;
    $f = naval_notif_pasta() . '/vapid.pem';
    $pem = is_file($f) ? (string) file_get_contents($f) : '';
    $k = $pem !== '' ? openssl_pkey_get_private($pem) : false;
    if (!$k) {
        $k = naval_ec_nova();
        openssl_pkey_export($k, $pem);
        file_put_contents($f, $pem, LOCK_EX);
        @chmod($f, 0600);
    }
    return $c = [$k, naval_b64u(naval_ec_ponto($k))];
}

/** Assinatura ES256 em bruto (r‖s, 64 bytes) a partir da assinatura DER do OpenSSL. */
function naval_es256(string $dados, $chave): string
{
    if (!openssl_sign($dados, $der, $chave, OPENSSL_ALGO_SHA256)) throw new RuntimeException('Assinatura VAPID falhou.');
    $p = 2;
    $int = function () use ($der, &$p): string {
        if (ord($der[$p]) !== 0x02) throw new RuntimeException('Assinatura DER inválida.');
        $n = ord($der[$p + 1]);
        $v = substr($der, $p + 2, $n);
        $p += 2 + $n;
        return str_pad(ltrim($v, "\0"), 32, "\0", STR_PAD_LEFT);
    };
    if (ord($der[1]) & 0x80) $p = 2 + (ord($der[1]) & 0x7f);
    return $int() . $int();
}

/** Cabeçalho Authorization VAPID (RFC 8292) para o serviço de push do endereço. */
function naval_vapid_cabecalho(string $endpoint, int $agora): string
{
    [$k, $pub] = naval_vapid();
    $u = parse_url($endpoint);
    $aud = $u['scheme'] . '://' . $u['host'];
    $mail = naval_config('NOTIF_CONTACTO', naval_config('ACTUALIZACAO_EMAIL', 'ti@naval.co.ao'));
    $mail = trim(explode(',', $mail)[0]);
    $h = naval_b64u((string) json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
    $p = naval_b64u((string) json_encode(['aud' => $aud, 'exp' => $agora + 12 * 3600, 'sub' => 'mailto:' . $mail], JSON_UNESCAPED_SLASHES));
    $jwt = $h . '.' . $p . '.' . naval_b64u(naval_es256($h . '.' . $p, $k));
    return 'vapid t=' . $jwt . ', k=' . $pub;
}

/** HKDF-SHA256 (RFC 5869) até 32 bytes, sem depender do hash_hkdf (PHP 7.1.2+). */
function naval_hkdf(string $ikm, int $n, string $info, string $sal): string
{
    $prk = hash_hmac('sha256', $ikm, $sal, true);
    return substr(hash_hmac('sha256', $info . "\x01", $prk, true), 0, $n);
}

/** O PHP deste servidor tem o que a cifra precisa (openssl_pkey_derive, PHP 7.3+)? Sem isso, as notificações ficam só na app. */
function naval_notif_push_disponivel(): bool
{
    return function_exists('openssl_pkey_derive') && function_exists('curl_init');
}

/** Segredo ECDH P-256 (32 bytes) entre a chave pública do outro lado e a nossa privada. */
function naval_ecdh($publica, $privada): string
{
    $f = 'openssl_pkey_derive';   // PHP 7.3+: chamado pelo nome para o código continuar a carregar no PHP 7.1
    // @phpstan-ignore-next-line callable.nonCallable -- a análise corre com o PHP 7.1 (mínimo suportado); só se chama se existir
    $s = function_exists($f) ? $f($publica, $privada, 32) : false;
    if (!is_string($s) || $s === '') throw new RuntimeException('ECDH falhou.');
    return $s;
}

/** Cifra a mensagem para o telefone (RFC 8291 com aes128gcm, RFC 8188): corpo do pedido ao serviço de push. */
function naval_webpush_cifrar(string $texto, string $p256dh, string $auth, $local = null, ?string $sal = null): string
{
    $ua = naval_b64u_dec($p256dh);
    $segredo = naval_b64u_dec($auth);
    if (strlen($segredo) !== 16) throw new NavalErro('Chave de subscrição inválida.', 400);
    $local = $local ?: naval_ec_nova();
    $as = naval_ec_ponto($local);
    if (!naval_notif_push_disponivel()) throw new RuntimeException('Este PHP não tem openssl_pkey_derive.');
    $ecdh = naval_ecdh(naval_ec_publica($ua), $local);
    $ikm = naval_hkdf((string) $ecdh, 32, "WebPush: info\0" . $ua . $as, $segredo);
    $sal = $sal ?? random_bytes(16);
    $cek = naval_hkdf($ikm, 16, "Content-Encoding: aes128gcm\0", $sal);
    $nonce = naval_hkdf($ikm, 12, "Content-Encoding: nonce\0", $sal);
    $cifrado = openssl_encrypt($texto . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
    if ($cifrado === false) throw new RuntimeException('Cifra AES-GCM falhou.');
    return $sal . pack('N', 4096) . chr(65) . $as . $cifrado . $tag;
}

/** O endereço é de um serviço de push conhecido, em HTTPS? */
function naval_notif_endpoint_ok(string $e): bool
{
    $u = parse_url($e);
    if (!$u || ($u['scheme'] ?? '') !== 'https' || empty($u['host']) || isset($u['user']) || isset($u['port'])) return false;
    $h = strtolower($u['host']);
    foreach (NAVAL_NOTIF_SERVICOS as $s) if ($h === $s) return true;
    return (bool) preg_match('/(^|\.)(notify\.windows\.com|push\.apple\.com)$/', $h);
}

/** Entrega uma mensagem a uma subscrição. Devolve o código HTTP (0 sem ligação). Pode ser substituída nos testes. */
function naval_webpush_entregar(array $sub, array $mensagem, int $agora): int
{
    if (isset($GLOBALS['naval_notif_entregar']) && is_callable($GLOBALS['naval_notif_entregar'])) return (int) $GLOBALS['naval_notif_entregar']($sub, $mensagem);
    if (!naval_notif_endpoint_ok((string) $sub['endpoint']) || !naval_notif_push_disponivel()) return 400;
    $corpo = naval_webpush_cifrar((string) json_encode($mensagem, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), (string) $sub['p256dh'], (string) $sub['auth']);
    $ch = curl_init((string) $sub['endpoint']);
    $op = [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => $corpo, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => [
            'Authorization: ' . naval_vapid_cabecalho((string) $sub['endpoint'], $agora),
            'Content-Encoding: aes128gcm', 'Content-Type: application/octet-stream', 'TTL: 86400',
            'Urgency: ' . (($mensagem['gravidade'] ?? '') === 'critico' ? 'high' : 'normal'),
            'Topic: ' . substr(preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($mensagem['tag'] ?? 'naval')), 0, 32),
        ],
    ];
    if (DIRECTORY_SEPARATOR === '\\' && (string) ini_get('curl.cainfo') === '' && defined('CURLSSLOPT_NATIVE_CA')) $op[CURLOPT_SSL_OPTIONS] = CURLSSLOPT_NATIVE_CA;
    curl_setopt_array($ch, $op);
    curl_exec($ch);
    $codigo = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $codigo;
}

// ── Subscrições e preferências ────────────────────────────────────────────────────────────────────────────────────

function naval_notif_subscricoes(): array
{
    return naval_json_ler(naval_notif_pasta() . '/subscricoes.json');
}

function naval_notif_subscrever(string $login, array $s, string $agente, string $idioma = ''): array
{
    $e = trim((string) ($s['endpoint'] ?? ''));
    $p = (string) ($s['keys']['p256dh'] ?? '');
    $a = (string) ($s['keys']['auth'] ?? '');
    if (!naval_notif_endpoint_ok($e)) throw new NavalErro('Serviço de notificações não reconhecido.', 400);
    naval_ec_publica(naval_b64u_dec($p));   // valida a chave
    if (strlen(naval_b64u_dec($a)) !== 16) throw new NavalErro('Chave de subscrição inválida.', 400);
    $idioma = in_array(strtolower(substr($idioma, 0, 2)), ['pt', 'en', 'ti'], true) ? strtolower(substr($idioma, 0, 2)) : '';
    return naval_exclusivo('notif-subs', function () use ($login, $e, $p, $a, $agente, $idioma) {
        $f = naval_notif_pasta() . '/subscricoes.json';
        $t = naval_json_ler($f);
        $id = substr(hash('sha256', $e), 0, 24);
        $t[$id] = ['id' => $id, 'login' => $login, 'endpoint' => $e, 'p256dh' => $p, 'auth' => $a, 'criada' => $t[$id]['criada'] ?? date('Y-m-d H:i:s'),
            'agente' => mb_substr($agente, 0, 160), 'falhas' => 0, 'ultima' => $t[$id]['ultima'] ?? '', 'idioma' => $idioma];
        naval_json_gravar($f, $t);
        naval_registar('notificacoes_subscritas', ['id' => $id], $login);
        return $t[$id];
    });
}

function naval_notif_cancelar(string $login, string $endpoint): bool
{
    return naval_exclusivo('notif-subs', function () use ($login, $endpoint) {
        $f = naval_notif_pasta() . '/subscricoes.json';
        $t = naval_json_ler($f);
        $id = substr(hash('sha256', trim($endpoint)), 0, 24);
        if (!isset($t[$id]) || $t[$id]['login'] !== $login) return false;
        unset($t[$id]);
        naval_json_gravar($f, $t);
        return true;
    });
}

/** Preferências da pessoa: {tipos: {tipo: bool}, silencio: {de, ate}} (por omissão tudo ligado, silêncio 20–07). */
function naval_notif_preferencias(string $login): array
{
    $p = naval_json_ler(naval_notif_pasta() . '/preferencias.json')[$login] ?? [];
    $tipos = [];
    foreach (NAVAL_NOTIF_TIPOS as $id => $x) $tipos[$id] = (bool) ($p['tipos'][$id] ?? true);
    $sil = $p['silencio'] ?? NAVAL_NOTIF_SILENCIO;
    return ['tipos' => $tipos, 'silencio' => $sil === false ? false : ['de' => (string) ($sil['de'] ?? '20:00'), 'ate' => (string) ($sil['ate'] ?? '07:00')]];
}

function naval_notif_gravar_preferencias(string $login, array $n): array
{
    $hm = '/^([01]\d|2[0-3]):[0-5]\d$/';
    return naval_exclusivo('notif-pref', function () use ($login, $n, $hm) {
        $f = naval_notif_pasta() . '/preferencias.json';
        $t = naval_json_ler($f);
        $actual = naval_notif_preferencias($login);
        foreach ((array) ($n['tipos'] ?? []) as $id => $v) if (isset(NAVAL_NOTIF_TIPOS[$id])) $actual['tipos'][$id] = (bool) $v;
        if (array_key_exists('silencio', $n)) {
            $s = $n['silencio'];
            $actual['silencio'] = $s === false ? false
                : (preg_match($hm, (string) ($s['de'] ?? '')) && preg_match($hm, (string) ($s['ate'] ?? '')) ? ['de' => $s['de'], 'ate' => $s['ate']] : $actual['silencio']);
        }
        $t[$login] = $actual;
        naval_json_gravar($f, $t);
        return $actual;
    });
}

/** Está nas horas de silêncio da pessoa? */
function naval_notif_em_silencio(array $pref, int $agora): bool
{
    $s = $pref['silencio'];
    if (!$s) return false;
    $h = date('H:i', $agora);
    return $s['de'] <= $s['ate'] ? ($h >= $s['de'] && $h < $s['ate']) : ($h >= $s['de'] || $h < $s['ate']);
}

/** Nível da conta no recurso (sem sessão: pela matriz e pelo perfil, com os módulos ligados). */
function naval_notif_nivel(array $u, string $recurso): string
{
    $p = (string) ($u['perfil'] ?? '');
    if ($p === '') return '';
    $n = naval_matriz()[$recurso][$p] ?? '';
    if ($n !== '' && function_exists('naval_recurso_disponivel') && !naval_recurso_disponivel($recurso, $p)) return '';
    return $n;
}

/** Contas que recebem o tipo (opcionalmente só as da rota): [login => conta]. */
function naval_notif_destinatarios(string $tipo, ?string $rota = null): array
{
    [, , $rec, $min] = NAVAL_NOTIF_TIPOS[$tipo];
    $porRota = naval_perfis_por_rota();
    $out = [];
    foreach (naval_utilizadores() as $login => $u) {
        $u += ['login' => $login];
        if (!naval_conta_valida($u)) continue;
        if (NAVAL_ORDEM_NIVEIS[naval_notif_nivel($u, $rec)] < NAVAL_ORDEM_NIVEIS[$min]) continue;
        if ($rota !== null && in_array($u['perfil'], $porRota, true) && !in_array($rota, array_map('strval', $u['rotas'] ?? []), true)) continue;
        $out[$login] = $u;
    }
    return $out;
}

// ── Envio ────────────────────────────────────────────────────────────────────────────────────────────────────────

/**
 * Notifica todas as pessoas que devem receber o tipo. $o: chave (nunca repete a mesma), rota, url (dentro da app),
 * gravidade. Devolve {destinatarios, entregues, silenciadas}. Nunca lança excepções: a notificação é um extra.
 */
function naval_notificar(string $tipo, string $titulo, string $corpo, array $o = [], ?int $agora = null): array
{
    $agora = $agora ?? time();
    $r = ['destinatarios' => 0, 'entregues' => 0, 'silenciadas' => 0, 'repetida' => false];
    if (!isset(NAVAL_NOTIF_TIPOS[$tipo]) || naval_config('NOTIFICACOES', '1') === '0') return $r;
    try {
        $chave = (string) ($o['chave'] ?? '');
        if ($chave !== '') {
            $dup = naval_exclusivo('notif-env', function () use ($chave, $agora) {
                $f = naval_notif_pasta() . '/enviadas.json';
                $e = array_filter(naval_json_ler($f), function ($t) use ($agora) { return $t > $agora - 4 * 86400; });
                if (isset($e[$chave])) return true;
                $e[$chave] = $agora;
                naval_json_gravar($f, $e);
                return false;
            });
            if ($dup) return ['repetida' => true] + $r;
        }
        $grav = (string) ($o['gravidade'] ?? NAVAL_NOTIF_TIPOS[$tipo][4]);
        $textos = (array) ($o['textos'] ?? []);   // {en: [título, texto], ti: …}: cada telefone recebe no seu idioma
        $textos['pt'] = $textos['pt'] ?? [$titulo, $corpo];
        $msg = ['id' => substr(bin2hex(random_bytes(8)), 0, 16), 'tipo' => $tipo, 'titulo' => mb_substr($titulo, 0, 80), 'corpo' => mb_substr($corpo, 0, 240),
            'textos' => array_map(function ($p) { return [mb_substr((string) $p[0], 0, 80), mb_substr((string) ($p[1] ?? ''), 0, 240)]; }, $textos),
            'url' => (string) ($o['url'] ?? '../gestao/#alertas'), 'tag' => $tipo . (isset($o['rota']) ? '-' . $o['rota'] : ''), 'gravidade' => $grav,
            'data' => date('Y-m-d H:i:s', $agora)];
        $dest = naval_notif_destinatarios($tipo, isset($o['rota']) ? (string) $o['rota'] : null);
        $subs = naval_notif_subscricoes();
        $remover = [];
        $excluir = array_map('strtolower', array_filter(array_map('strval', (array) ($o['excluir'] ?? []))));
        foreach ($dest as $login => $u) {
            if (in_array(strtolower((string) $login), $excluir, true)) continue;   // separação de funções
            $pref = naval_notif_preferencias($login);
            if (empty($pref['tipos'][$tipo])) continue;
            $r['destinatarios']++;
            $naoLidas = naval_notif_caixa_juntar($login, $msg);
            if ($grav !== 'critico' && naval_notif_em_silencio($pref, $agora)) {
                $r['silenciadas']++;
                continue;
            }
            foreach ($subs as $id => $s) {
                if ($s['login'] !== $login) continue;
                $l = naval_notif_idioma($s, $u);
                $loc = isset($msg['textos'][$l]) ? ['titulo' => $msg['textos'][$l][0], 'corpo' => $msg['textos'][$l][1]] : [];
                $env = $loc + $msg;
                unset($env['textos']);
                $c = naval_webpush_entregar($s, $env + ['nao_lidas' => $naoLidas], $agora);
                if ($c >= 200 && $c < 300) $r['entregues']++;
                elseif (in_array($c, [404, 410], true)) $remover[] = $id;   // o telefone retirou a subscrição
            }
        }
        if ($remover) {
            naval_exclusivo('notif-subs', function () use ($remover) {
                $f = naval_notif_pasta() . '/subscricoes.json';
                $t = naval_json_ler($f);
                foreach ($remover as $id) unset($t[$id]);
                naval_json_gravar($f, $t);
            });
        }
        naval_jsonl_acrescentar(naval_pasta_privada() . '/registos/notificacoes-' . date('Y-m', $agora) . '.jsonl',
            ['data' => $msg['data'], 'tipo' => $tipo, 'chave' => $chave] + $r);
    } catch (Throwable $e) {
        error_log('[notificações] ' . $e->getMessage());
    }
    return $r;
}

/** Junta a mensagem à caixa da pessoa; devolve quantas estão por ler. */
function naval_notif_caixa_juntar(string $login, array $msg): int
{
    return naval_exclusivo('notif-caixa-' . md5($login), function () use ($login, $msg) {
        $f = naval_notif_pasta('caixa') . '/' . md5($login) . '.json';
        $c = naval_json_ler($f);
        array_unshift($c, $msg + ['lida' => false]);
        $c = array_slice($c, 0, NAVAL_NOTIF_CAIXA_MAX);
        naval_json_gravar($f, $c);
        return count(array_filter($c, function ($x) { return empty($x['lida']); }));
    });
}

function naval_notif_caixa(string $login): array
{
    return naval_json_ler(naval_notif_pasta('caixa') . '/' . md5($login) . '.json');
}

function naval_notif_marcar_lidas(string $login): void
{
    naval_exclusivo('notif-caixa-' . md5($login), function () use ($login) {
        $f = naval_notif_pasta('caixa') . '/' . md5($login) . '.json';
        $c = array_map(function ($x) { return ['lida' => true] + $x; }, naval_json_ler($f));
        foreach ($c as &$x) $x['lida'] = true;
        unset($x);
        naval_json_gravar($f, $c);
    });
}

/** Vista para a app: chave pública, tipos que a pessoa pode receber, preferências, caixa e se este telefone está subscrito. */
function naval_notif_vista(array $u, string $endpoint = ''): array
{
    $login = (string) $u['login'];
    $pref = naval_notif_preferencias($login);
    $tipos = [];
    foreach (NAVAL_NOTIF_TIPOS as $id => [$nome, $desc, $rec, $min, $grav]) {
        if (NAVAL_ORDEM_NIVEIS[naval_notif_nivel($u, $rec)] < NAVAL_ORDEM_NIVEIS[$min]) continue;
        $tipos[] = ['id' => $id, 'nome' => $nome, 'descricao' => $desc, 'gravidade' => $grav, 'activo' => $pref['tipos'][$id]];
    }
    $subs = array_filter(naval_notif_subscricoes(), function ($s) use ($login) { return $s['login'] === $login; });
    $caixa = naval_notif_caixa($login);
    return [
        'chave' => naval_vapid()[1], 'tipos' => $tipos, 'silencio' => $pref['silencio'],
        'subscrito' => $endpoint !== '' && isset($subs[substr(hash('sha256', trim($endpoint)), 0, 24)]), 'equipamentos' => count($subs),
        'idioma' => $endpoint !== '' ? (string) ($subs[substr(hash('sha256', trim($endpoint)), 0, 24)]['idioma'] ?? '') : '',
        'caixa' => array_slice($caixa, 0, 50), 'nao_lidas' => count(array_filter($caixa, function ($x) { return empty($x['lida']); })),
    ];
}

// ── Acontecimentos (tarefa periódica, de 15 em 15 minutos) ─────────────────────────────────────────────────────────

/** Vendas de hoje por rota (documentos, total, última hora) e documentos anulados hoje, do Pinguim BO. */
function naval_notif_dados_dia(string $dia): ?array
{
    if (!function_exists('naval_bd_configurada')) naval_servico('bd');
    if (!naval_bd_configurada()) return null;
    naval_servico('vendas');
    naval_servico('rotas');
    $v = naval_vendas_sql(str_replace('-', '', $dia), str_replace('-', '', $dia), naval_promotores(), false);
    if ($v === null) return null;
    $rotas = [];
    foreach (naval_vendas_efectivas((array) $v['vendas']) as $d) {
        $r = (string) $d['rota'];
        $x = $rotas[$r] ?? ['docs' => 0, 'total' => 0.0, 'ultima' => ''];
        if (empty($d['nc'])) $x['docs']++;
        $x['total'] += (float) $d['total'];
        if ((string) $d['hora'] > $x['ultima']) $x['ultima'] = (string) $d['hora'];
        $rotas[$r] = $x;
    }
    $anulados = [];
    try {
        $anulados = naval_bd_consultar("
            SELECT RTRIM(LTRIM(COALESCE(DH.DOC_PREFIX, '') + ' ' + CAST(DH.DOC_NUMBER AS VARCHAR(20)))) AS DOCUMENTO,
                   COALESCE(STORE.CODE, SUBSTRING(STORE.DESCRIPTION, 1, 4)) AS ROTA, CAST(DH.CALC_TOTAL_W_TAX AS FLOAT) AS TOTAL
            FROM DOCHEADER DH
            INNER JOIN DOCCONFIG DC ON DC.ID = DH.DOCCONFIG_ID
            LEFT JOIN STORE ON STORE.ID = DH.STORE_ID
            WHERE DH.DELETED = 0 AND " . naval_vendas_condicao_anulados() . "
              AND DH.CALC_SALES_SIGNAL <> 0 AND DH.DOC_DATE >= :de AND DH.DOC_DATE <= :ate", [':de' => $dia, ':ate' => $dia]);
    } catch (Throwable $e) {
        error_log('[notificações anulados] ' . $e->getMessage());
    }
    return ['rotas' => $rotas, 'anulados' => $anulados, 'promotores' => naval_promotores()];
}

/**
 * Ciclo das notificações de negócio. $o: dados (para os testes), carrinhas (idem), forcar. Devolve o que enviou ou
 * null se ainda não era altura. Horário do calendário de trabalho (calendario.php): alertas das rotas só durante as
 * vendas (07:30–15:00 de segunda a sexta, 07:30–13:00 ao sábado), nada nos dias de folga (domingos e feriados);
 * resumo do dia depois do fim da carga; carrinhas por aprovar a partir do fecho.
 */
function naval_notif_ciclo(int $agora, array $o = []): ?array
{
    if (naval_config('NOTIFICACOES', '1') === '0') return null;
    $f = naval_notif_pasta() . '/ciclo.json';
    $e = naval_json_ler($f, ['ultimo' => 0, 'resumo' => '']);
    if (empty($o['forcar']) && $agora - (int) $e['ultimo'] < 900) return null;
    $e['ultimo'] = $agora;
    naval_json_gravar($f, $e);
    $dia = date('Y-m-d', $agora);
    $hm = date('H:i', $agora);
    naval_servico('calendario');
    $cal = naval_cal_dia($dia);
    $util = $cal['tipo'] !== 'folga';
    $vIni = $cal['vendas'][0] ?? '07:30';
    $vFim = $cal['vendas'][1] ?? '15:00';
    $fIni = $cal['fecho'][0] ?? '15:30';
    // resumo do dia: meia hora depois do fim da carga (17:30 de segunda a sexta; 15:30 ao sábado)
    $resumoHora = date('H:i', strtotime($dia . ' ' . ($cal['carga'][1] ?? '17:00')) + 1800);
    $feito = [];
    $d = array_key_exists('dados', $o) ? $o['dados'] : (($util && $hm >= $vIni && $hm <= '19:00') ? naval_notif_dados_dia($dia) : null);
    if (is_array($d)) {
        $prom = $d['promotores'] ?? [];
        $nome = function (string $r) use ($prom) { return $r . (isset($prom[$r]) && $prom[$r] !== $r ? ' · ' . $prom[$r] : ''); };
        if ($util && $hm >= $vIni && $hm < $vFim) {
            // rota com vendas hoje e sem vender há mais de 60 minutos (uma vez por paragem)
            foreach ($d['rotas'] as $r => $x) {
                if (!$x['docs'] || $x['ultima'] === '') continue;
                $min = (int) round(($agora - strtotime($dia . ' ' . $x['ultima'])) / 60);
                if ($min < 60) continue;
                $tx = naval_notif_txt('parada', ['r' => $nome((string) $r), 'h' => intdiv($min, 60), 'm' => $min % 60, 'u' => substr($x['ultima'], 0, 5), 'n' => $x['docs']]);
                $feito[] = naval_notificar('rota_parada', $tx['pt'][0], $tx['pt'][1],
                    ['chave' => 'parada|' . $dia . '|' . $r . '|' . $x['ultima'], 'rota' => (string) $r, 'url' => '../gestao/#rotas', 'textos' => $tx], $agora);
            }
            // às 10:30 sem nenhuma venda (rotas do portal)
            if ($hm >= '10:30') {
                foreach (array_keys($prom) as $r) {
                    if (!empty($d['rotas'][$r]['docs'])) continue;
                    $tx = naval_notif_txt('semvendas', ['r' => $nome((string) $r)]);
                    $feito[] = naval_notificar('rota_parada', $tx['pt'][0], $tx['pt'][1],
                        ['chave' => 'semvendas|' . $dia . '|' . $r, 'rota' => (string) $r, 'url' => '../gestao/#rotas', 'textos' => $tx], $agora);
                }
            }
        }
        foreach ((array) $d['anulados'] as $a) {
            $doc = (string) $a['DOCUMENTO'];
            $tx = naval_notif_txt('anulada', ['r' => $nome((string) $a['ROTA']), 'doc' => $doc, 'v' => number_format((float) $a['TOTAL'], 0, ',', ' ')]);
            $feito[] = naval_notificar('venda_anulada', $tx['pt'][0], $tx['pt'][1],
                ['chave' => 'anulada|' . $doc, 'rota' => (string) $a['ROTA'], 'url' => '../gestao/#rotas', 'textos' => $tx], $agora);
        }
        // resumo do dia depois do fim da carga
        if ($util && $hm >= $resumoHora && $e['resumo'] !== $dia) {
            $tot = array_sum(array_column($d['rotas'], 'total'));
            $docs = array_sum(array_column($d['rotas'], 'docs'));
            $melhor = '';
            $mv = -1;
            foreach ($d['rotas'] as $r => $x) if ($x['total'] > $mv) [$melhor, $mv] = [(string) $r, $x['total']];
            $tx = naval_notif_txt('resumo', ['v' => number_format($tot, 0, ',', ' '), 'n' => $docs, 'r' => $melhor !== '' ? $nome($melhor) : '—', 'mv' => number_format(max(0, $mv), 0, ',', ' ')]);
            $feito[] = naval_notificar('resumo_dia', $tx['pt'][0], $tx['pt'][1], ['chave' => 'resumo|' . $dia, 'url' => '../gestao/#hoje', 'textos' => $tx], $agora);
            $e = naval_json_ler($f, $e);
            $e['resumo'] = $dia;
            naval_json_gravar($f, $e);
        }
    }
    // carrinhas com diferenças à espera de aprovação
    $car = $o['carrinhas'] ?? null;
    if ($car === null && $util && $hm >= $fIni && $hm <= '21:00') {
        try {
            naval_servico('carrinha');
            $car = naval_carr_vista($dia, null, true)['rotas'] ?? [];
        } catch (Throwable $ex) {
            $car = [];
        }
    }
    foreach ((array) $car as $r) {
        if (!in_array($r['resumo']['estado'] ?? '', ['por_aprovar', 'por_fechar'], true)) continue;
        $falta = (float) ($r['resumo']['falta_kz'] ?? 0);
        $fl = $falta ? naval_notif_txt('carrinha_falta', ['v' => number_format($falta, 0, ',', ' ')]) : ['pt' => [''], 'en' => [''], 'ti' => ['']];
        $tx = [];
        foreach (['pt', 'en', 'ti'] as $l) {
            $tx[$l] = naval_notif_txt('carrinha', ['r' => $r['rota'], 'n' => $r['resumo']['diferencas'] ?? 0, 'falta' => $fl[$l][0]])[$l];
        }
        $feito[] = naval_notificar('carrinha_aprovar', $tx['pt'][0], $tx['pt'][1],
            ['chave' => 'carrinha|' . $dia . '|' . $r['rota'], 'rota' => (string) $r['rota'], 'url' => '../reports/#conciliacao-carrinha', 'textos' => $tx], $agora);
    }
    // folhas de caixa e depósitos no banco à espera de aprovação (últimos 7 dias), ao nível que falta
    $caixas = $o['caixas'] ?? null;
    if ($caixas !== null || !array_key_exists('dados', $o)) {
        try {
            $feito = array_merge($feito, naval_notif_folhas_caixa($dia, $agora, $caixas));
        } catch (Throwable $ex) {
            error_log('[notificações caixa] ' . $ex->getMessage());
        }
    }
    return ['enviadas' => count(array_filter($feito, function ($x) { return empty($x['repetida']); }))];
}

/**
 * Folhas de caixa validadas (ou aprovadas no 1.º nível) e depósitos no banco por aprovar: avisa quem aprova o nível
 * que falta, uma vez por passo. Quem lançou, validou ou requisitou não recebe (separação de funções). $caixas: {dia:
 * folha} para os testes; sem ele, lê os ficheiros.
 */
function naval_notif_folhas_caixa(string $dia, int $agora, ?array $caixas = null, ?bool $dois = null): array
{
    naval_servico('caixa');
    $dois = $dois ?? naval_caixa_dois_niveis();
    $feito = [];
    for ($i = 6; $i >= 0; $i--) {
        $cd = date('Y-m-d', strtotime($dia . ' 12:00') - $i * 86400);
        $c = $caixas !== null ? ($caixas[$cd] ?? null) : naval_json_ler(naval_caixa_ficheiro($cd));
        if (!$c || ($c['estado'] ?? '') === 'aprovada') continue;
        $dl = date('d-m-Y', strtotime($cd . ' 12:00'));
        foreach ((array) ($c['saidas'] ?? []) as $l) {
            if (($l['tipo'] ?? '') !== 'deposito_banco' || !empty($l['aprovado_por']) || empty($l['id'])) continue;
            $tx = naval_notif_txt('deposito', ['d' => $dl, 'v' => number_format((float) ($l['valor'] ?? 0), 0, ',', ' '), 'b' => (string) ($l['banco'] ?? ''), 'l' => (string) ($l['lacre'] ?? '')]);
            $feito[] = naval_notificar($dois ? 'caixa_verificar' : 'caixa_aprovar', $tx['pt'][0], $tx['pt'][1],
                ['chave' => 'deposito|' . $l['id'], 'url' => '../gestao/#alertas', 'textos' => $tx, 'excluir' => [(string) ($l['requisitante'] ?? '')]], $agora);
        }
        $e = (string) ($c['estado'] ?? '');
        if (!in_array($e, ['fechada', 'verificada'], true)) continue;
        $nivel = $e === 'fechada' && $dois ? 1 : 2;
        $tx = naval_notif_txt($nivel === 1 ? 'caixa1' : 'caixa2', ['d' => $dl]);
        $feito[] = naval_notificar($nivel === 1 ? 'caixa_verificar' : 'caixa_aprovar', $tx['pt'][0], $tx['pt'][1],
            ['chave' => 'caixa|' . $cd . '|' . $e, 'url' => '../gestao/#alertas', 'textos' => $tx,
                'excluir' => [(string) ($c['fechada_por'] ?? ''), (string) ($c['alterado_por'] ?? ''), (string) ($c['verificada_por'] ?? '')]], $agora);
    }
    return $feito;
}
