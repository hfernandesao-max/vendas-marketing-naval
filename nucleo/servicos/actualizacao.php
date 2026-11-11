<?php
/**
 * Actualizações automáticas do portal (ferramentas/actualizar.php).
 *
 * Cada alteração aprovada no ramo main que passa os testes automáticos dá origem a uma versão publicada no GitHub
 * (.github/workflows/publicar.yml): um pacote ZIP com todos os ficheiros do portal e um manifesto com o SHA-256 de
 * cada um. No servidor, o actualizador (tarefa agendada, de madrugada):
 *   1. obtém a versão mais recente pela API do GitHub (token só de leitura, no .env) e confirma o SHA-256 do pacote;
 *   2. valida o manifesto (caminhos, ficheiros protegidos, hash de cada ficheiro);
 *   3. compara com o servidor: só copia o que mudou e pára se um ficheiro foi alterado à mão no servidor desde a
 *      última actualização (desvio de configuração), em vez de o apagar sem aviso;
 *   4. aplica as migrações pendentes da base de dados do módulo Equipamentos (nunca toca no Pinguim BO);
 *   5. verifica a sintaxe do PHP, guarda cópia dos ficheiros a substituir e copia (cada ficheiro é escrito ao lado
 *      e trocado de uma vez; os pontos de entrada vão no fim);
 *   6. confirma que o portal responde (linha de comandos e HTTP) e, se não, repõe a versão anterior sozinho;
 *   7. regista tudo e avisa por correio electrónico.
 * Nunca apaga ficheiros do servidor (só os que ele próprio criou, ao reverter) e nunca escreve o .env nem os
 * dados (reports/data/*.json, pasta privada).
 */
defined('NAVAL_NUCLEO') || exit;
require_once dirname(__DIR__) . '/cliente-http.php';

const NAVAL_ACT_REPOSITORIO = 'hfernandesao-max/vendas-marketing-naval';
const NAVAL_ACT_COPIAS = 5;   // cópias de segurança guardadas

/** Pontos de entrada copiados no fim, para que um pedido nunca encontre um ponto de entrada novo com o resto antigo. */
const NAVAL_ACT_ENTRADAS = ['api/index.php', 'index.php', 'index.html', 'reports/index.php', 'reports/index.html', 'geo/login.php', 'sw.js', 'manifest.webmanifest', '.htaccess', 'reports/.htaccess'];

class NavalActualizacaoErro extends RuntimeException
{
}

// ── Regras do pacote ────────────────────────────────────────────────────────────────────────────────

/** Caminho relativo aceitável (sem «..», sem raiz, sem unidade, só caracteres simples). */
function naval_act_caminho_valido(string $c): bool
{
    if ($c === '' || strlen($c) > 240 || $c[0] === '/' || strpos($c, '\\') !== false || strpos($c, ':') !== false) return false;
    if (!preg_match('#^[A-Za-z0-9 ._()\-]+(/[A-Za-z0-9 ._()\-]+)*$#', $c)) return false;
    foreach (explode('/', $c) as $s) if ($s === '.' || $s === '..') return false;
    return true;
}

/** Ficheiros do servidor que nenhuma actualização escreve: configuração, dados reais e pasta privada. */
function naval_act_protegido(string $c): bool
{
    return (bool) preg_match('#(^|/)\.env([.\-].*)?$|^reports/data/[^/]+\.json$|(^|/)dados-privados(/|$)|\.(sqlite3?|db|mmdb|log|jsonl|bak)$#i', $c);
}

/** Ficheiros do repositório que vão para o servidor (sem testes, documentação nem configuração de desenvolvimento). */
function naval_act_incluir(string $c): bool
{
    if (!naval_act_caminho_valido($c) || naval_act_protegido($c)) return false;
    if (preg_match('#^(tests|docs|\.github|scripts|node_modules|vendor)/#', $c)) return false;
    $dev = ['.editorconfig', '.gitignore', '.gitattributes', '.nojekyll', '.prettierrc.json', '.prettierignore', '.semgrepignore', '.env.exemplo',
        'composer.json', 'composer.lock', 'package.json', 'package-lock.json', 'eslint.config.js', 'playwright.config.js', 'phpstan.neon.dist',
        'README.md', 'CONTRIBUTING.md'];   // o CHANGELOG.md vai: é a origem do resumo das actualizações (o Apache não o serve)
    return !in_array($c, $dev, true);
}

function naval_act_hash(string $f): ?string
{
    return is_file($f) ? (string) hash_file('sha256', $f) : null;
}

/** Versão da migração a partir do nome do ficheiro (0002-descricao.sql → 2). */
function naval_act_versao_migracao(string $nome): int
{
    return preg_match('/^(\d{4})-[a-z0-9-]+\.sql$/', $nome, $m) ? (int) $m[1] : 0;
}

// ── Pastas e estado ─────────────────────────────────────────────────────────────────────────────────

function naval_act_pasta(string $sub = ''): string
{
    $d = naval_pasta('actualizacoes') . ($sub !== '' ? '/' . $sub : '');
    if (!is_dir($d)) @mkdir($d, 0770, true);
    return $d;
}

/** Estado da instalação: versão, commit, data e o hash de cada ficheiro instalado (base da detecção de desvios). */
function naval_act_estado(): array
{
    return naval_json_ler(naval_act_pasta() . '/estado.json', ['versao' => '', 'commit' => '', 'aplicada_em' => '', 'ficheiros' => []]);
}

function naval_act_gravar_estado(array $e): void
{
    naval_json_gravar(naval_act_pasta() . '/estado.json', $e);
}

/** Linha no registo das actualizações (JSONL) e no registo de auditoria do portal. */
function naval_act_registar(string $accao, array $det = []): void
{
    naval_jsonl_acrescentar(naval_act_pasta() . '/registo.jsonl', ['data' => date('Y-m-d H:i:s'), 'accao' => $accao] + $det);
    try {
        naval_registar('actualizacao_' . $accao, $det, 'actualizador');
    } catch (Throwable $e) {
        // o registo de auditoria é complementar; o das actualizações já ficou gravado
    }
}

// ── GitHub ──────────────────────────────────────────────────────────────────────────────────────────

/**
 * Pedido à API do GitHub (ou a um ficheiro do pacote) sem seguir reencaminhamentos: o token só vai para a API;
 * o endereço temporário do ficheiro é pedido sem ele. $destino grava o corpo num ficheiro.
 * Devolve [código, corpo, cabeçalhos (minúsculas)].
 */
function naval_act_http(string $url, $comToken, string $aceitar = 'application/vnd.github+json', ?string $destino = null, string $metodo = 'GET'): array
{
    if (!function_exists('curl_init')) throw new NavalActualizacaoErro('O PHP do servidor não tem a extensão curl (php.ini: extension=curl).');
    $cab = ['Accept: ' . $aceitar, 'User-Agent: Portal-RTM-Actualizador', 'X-GitHub-Api-Version: 2022-11-28'];
    // $comToken: true = credencial do actualizador (GitHub App ou código de acesso pessoal); texto = essa credencial
    if ($comToken !== false) $cab[] = 'Authorization: Bearer ' . (is_string($comToken) ? $comToken : naval_act_credencial());
    $cabecalhos = [];
    $ch = curl_init($url);
    $fh = null;
    $op = [
        CURLOPT_HTTPHEADER => $cab, CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_TIMEOUT => 300,
        CURLOPT_HEADERFUNCTION => function ($c, $linha) use (&$cabecalhos) {
            $p = strpos($linha, ':');
            if ($p !== false) $cabecalhos[strtolower(trim(substr($linha, 0, $p)))] = trim(substr($linha, $p + 1));
            return strlen($linha);
        },
    ];
    // PHP no Windows sem curl.cainfo no php.ini: usar os certificados do próprio Windows
    if (DIRECTORY_SEPARATOR === '\\' && (string) ini_get('curl.cainfo') === '' && defined('CURLSSLOPT_NATIVE_CA')) $op[CURLOPT_SSL_OPTIONS] = CURLSSLOPT_NATIVE_CA;
    if ($destino !== null) {
        $fh = fopen($destino, 'wb');
        if (!$fh) throw new NavalActualizacaoErro('Não foi possível escrever ' . $destino);
        $op[CURLOPT_FILE] = $fh;
    } else {
        $op[CURLOPT_RETURNTRANSFER] = true;
    }
    if ($metodo !== 'GET') {
        $op[CURLOPT_CUSTOMREQUEST] = $metodo;
        $op[CURLOPT_POSTFIELDS] = '';
    }
    curl_setopt_array($ch, $op);
    $corpo = curl_exec($ch);
    $codigo = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $errno = curl_errno($ch);
    $erro = curl_error($ch);
    curl_close($ch);
    if ($fh) fclose($fh);
    if ($errno !== 0) {
        if ($errno === 60 || $errno === 77) {
            throw new NavalActualizacaoErro('Certificado HTTPS não verificado (' . $erro . '). Descarregar https://curl.se/ca/cacert.pem para o disco do servidor'
                . ' (ex.: C:\\php\\extras\\ssl\\cacert.pem) e, no php.ini, indicar esse caminho em curl.cainfo e openssl.cafile (o caminho do ficheiro, não o endereço).');
        }
        throw new NavalActualizacaoErro('Sem ligação a ' . (string) parse_url($url, PHP_URL_HOST) . ' (' . $erro . '). Confirmar o acesso do servidor à Internet por HTTPS.');
    }
    return [$codigo, is_string($corpo) ? $corpo : '', $cabecalhos];
}

// ── Credencial do GitHub ────────────────────────────────────────────────────────────────────────────
//
// Recomendado (prática do GitHub para servidores): uma GitHub App instalada só neste repositório, com «Contents:
// Read-only». O servidor guarda a chave privada da App (pasta privada, só SYSTEM e administradores) e, em cada execução,
// assina um JWT (RS256, 9 minutos) e troca-o por uma credencial de instalação que dura 1 hora. A chave não expira: não
// há renovação anual. Alternativa: código de acesso pessoal (ACTUALIZACAO_GITHUB_TOKEN), que expira e tem de ser renovado.

function naval_act_b64url(string $s): string
{
    return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}

function naval_act_ficheiro_chave_app(): string
{
    return naval_config('ACTUALIZACAO_GITHUB_APP_CHAVE', naval_act_pasta() . '/github-app.pem');
}

function naval_act_usa_app(): bool
{
    return naval_config('ACTUALIZACAO_GITHUB_APP_ID') !== '';
}

/** Diferença (s) entre a hora do GitHub e a do servidor, medida na última resposta recusada; 0 até lá. */
function naval_act_desvio(?int $novo = null): int
{
    static $d = 0;
    if ($novo !== null) $d = $novo;
    return $d;
}

/**
 * JWT da GitHub App (RS256), válido 9 minutos (o GitHub aceita até 10). A hora é a do servidor acertada pelo desvio
 * medido face ao GitHub: um relógio adiantado ou atrasado alguns minutos não bloqueia as actualizações.
 */
function naval_act_jwt_app(): string
{
    if (!function_exists('openssl_sign')) throw new NavalActualizacaoErro('O PHP do servidor não tem a extensão openssl (php.ini: extension=openssl).');
    $pem = @file_get_contents(naval_act_ficheiro_chave_app());
    $chave = $pem ? openssl_pkey_get_private($pem) : false;
    if (!$chave) throw new NavalActualizacaoErro('Chave privada da GitHub App em falta ou inválida (correr «php ferramentas/actualizar.php configurar»).');
    $agora = time() + naval_act_desvio();
    $dados = naval_act_b64url((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT'])) . '.'
        . naval_act_b64url((string) json_encode(['iat' => $agora - 60, 'exp' => $agora + 540, 'iss' => naval_config('ACTUALIZACAO_GITHUB_APP_ID')]));
    if (!openssl_sign($dados, $assinatura, $chave, OPENSSL_ALGO_SHA256)) throw new NavalActualizacaoErro('Não foi possível assinar o pedido à GitHub App.');
    return $dados . '.' . naval_act_b64url($assinatura);
}

/** Mede o desvio pela data da resposta do GitHub; true se for maior do que 30 s (vale a pena tentar de novo). */
function naval_act_medir_desvio(array $cabecalhos): bool
{
    $t = strtotime((string) ($cabecalhos['date'] ?? ''));
    if (!$t) return false;
    $d = $t - time();
    if (abs($d) <= 30 || abs($d) > 86400) return false;
    naval_act_desvio($d);
    error_log('[actualizador] hora do servidor difere ' . $d . ' s da do GitHub: acertar o relógio do Windows (w32tm /resync).');
    return true;
}

/** Credencial para a API: de instalação da GitHub App (1 hora, renovada sozinha) ou o código de acesso pessoal. */
function naval_act_credencial(): string
{
    static $cache = null;
    if (!naval_act_usa_app()) {
        $token = naval_config('ACTUALIZACAO_GITHUB_TOKEN');
        if ($token === '') throw new NavalActualizacaoErro('O servidor ainda não está ligado ao GitHub (GitHub App ou ACTUALIZACAO_GITHUB_TOKEN no .env): correr «php ferramentas/actualizar.php configurar».');
        return $token;
    }
    if ($cache !== null && $cache[1] > time() + 300) return $cache[0];
    $base = rtrim(naval_config('ACTUALIZACAO_GITHUB_API', 'https://api.github.com'), '/');
    $jwt = naval_act_jwt_app();
    $inst = naval_config('ACTUALIZACAO_GITHUB_APP_INSTALACAO');
    if ($inst === '') {
        $url = $base . '/repos/' . naval_config('ACTUALIZACAO_REPOSITORIO', NAVAL_ACT_REPOSITORIO) . '/installation';
        [$c, $corpo, $cab] = naval_act_http($url, $jwt);
        // recusado com a hora do servidor fora de tempo: acerta pela hora do GitHub e tenta uma vez mais
        if ($c === 401 && naval_act_medir_desvio($cab)) {
            $jwt = naval_act_jwt_app();
            [$c, $corpo] = naval_act_http($url, $jwt);
        }
        $j = json_decode($corpo, true);
        if ($c !== 200 || empty($j['id'])) throw new NavalActualizacaoErro('A GitHub App não está instalada no repositório do portal ou o ID da App está errado (HTTP ' . $c . ').');
        $inst = (string) $j['id'];
    }
    $urlTk = $base . '/app/installations/' . rawurlencode($inst) . '/access_tokens';
    [$c, $corpo, $cab] = naval_act_http($urlTk, $jwt, 'application/vnd.github+json', null, 'POST');
    if ($c === 401 && naval_act_medir_desvio($cab)) [$c, $corpo] = naval_act_http($urlTk, naval_act_jwt_app(), 'application/vnd.github+json', null, 'POST');
    $j = json_decode($corpo, true);
    if ($c !== 201 || empty($j['token'])) {
        $msg = 'O GitHub recusou a GitHub App (HTTP ' . $c . '): confirmar o ID da App, a chave privada e a instalação no repositório.';
        if (abs(naval_act_desvio()) > 60) $msg .= ' A hora do servidor difere ' . abs(naval_act_desvio()) . ' s da do GitHub: acertar o relógio (w32tm /resync).';
        throw new NavalActualizacaoErro($msg);
    }
    $cache = [(string) $j['token'], strtotime((string) ($j['expires_at'] ?? '')) ?: time() + 3000];
    return $cache[0];
}

function naval_act_api(string $caminho): array
{
    $base = rtrim(naval_config('ACTUALIZACAO_GITHUB_API', 'https://api.github.com'), '/');
    $repo = naval_config('ACTUALIZACAO_REPOSITORIO', NAVAL_ACT_REPOSITORIO);
    [$codigo, $corpo, $cab] = naval_act_http($base . '/repos/' . $repo . '/' . ltrim($caminho, '/'), true);
    if ($codigo === 401) throw new NavalActualizacaoErro(naval_act_usa_app() ? 'O GitHub recusou a credencial da GitHub App: confirmar a App e a chave («php ferramentas/actualizar.php configurar»).' : 'O GitHub recusou o código de acesso (expirado ou revogado): criar outro e correr «php ferramentas/actualizar.php configurar».');
    if ($codigo === 403 || $codigo === 404) throw new NavalActualizacaoErro('A credencial do GitHub não dá acesso ao repositório ' . $repo . ' (HTTP ' . $codigo . '): confirmar «Contents: Read-only» nesse repositório.');
    if ($codigo !== 200) throw new NavalActualizacaoErro('Resposta inesperada do GitHub (HTTP ' . $codigo . ').');
    $j = json_decode($corpo, true);
    if (!is_array($j)) throw new NavalActualizacaoErro('Resposta inválida do GitHub.');
    return [$j, $cab];
}

/** Dias até o token expirar (cabeçalho do GitHub), ou null se não tiver validade. */
function naval_act_dias_token(array $cab): ?int
{
    $v = $cab['github-authentication-token-expiration'] ?? '';
    if ($v === '' || ($t = strtotime($v)) === false) return null;
    return (int) floor(($t - time()) / 86400);
}

/** Versão publicada no formato do actualizador (null se não tiver o pacote e o SHA-256). */
function naval_act_release(array $r, array $cab = []): ?array
{
    $zip = $sha = null;
    foreach ($r['assets'] ?? [] as $a) {
        $n = (string) ($a['name'] ?? '');
        if (preg_match('/^portal-rtm-[\w.\-]+\.zip$/', $n)) $zip = (string) $a['url'];
        if (preg_match('/^portal-rtm-[\w.\-]+\.zip\.sha256$/', $n)) $sha = (string) $a['url'];
    }
    if (!$zip || !$sha || !empty($r['draft']) || !empty($r['prerelease'])) return null;
    return ['versao' => (string) ($r['tag_name'] ?? ''), 'commit' => (string) ($r['target_commitish'] ?? ''), 'zip' => $zip, 'sha' => $sha,
        'dias_token' => naval_act_dias_token($cab), 'publicada_em' => (string) ($r['published_at'] ?? ''), 'notas' => (string) ($r['body'] ?? '')];
}

/** Versão mais recente publicada: [versao, commit, url do zip, url do sha256, dias do token]. */
function naval_act_ultima_versao(): array
{
    [$r, $cab] = naval_act_api('releases/latest');
    $v = naval_act_release($r, $cab);
    if ($v === null) throw new NavalActualizacaoErro('A versão ' . ($r['tag_name'] ?? '?') . ' não tem o pacote e o SHA-256 publicados.');
    return $v;
}

/** Versões publicadas (as 30 mais recentes), da mais recente para a mais antiga. */
function naval_act_versoes(): array
{
    [$l, $cab] = naval_act_api('releases?per_page=30');
    $out = [];
    foreach ($l as $r) {
        $v = is_array($r) ? naval_act_release($r, $cab) : null;
        if ($v !== null) $out[] = $v;
    }
    usort($out, function ($a, $b) { return strcmp($b['publicada_em'], $a['publicada_em']); });
    return $out;
}

/** Descarrega um ficheiro de uma versão (API → endereço temporário, pedido sem o token). */
function naval_act_descarregar(string $urlApi, string $destino): void
{
    [$codigo, , $cab] = naval_act_http($urlApi, true, 'application/octet-stream', $destino);
    if ($codigo >= 300 && $codigo < 400 && !empty($cab['location'])) {
        $loc = $cab['location'];
        if (stripos($loc, 'https://') !== 0 && stripos($loc, 'http://127.0.0.1') !== 0) throw new NavalActualizacaoErro('Endereço de descarga inválido.');
        [$codigo] = naval_act_http($loc, false, 'application/octet-stream', $destino);
    }
    if ($codigo !== 200) throw new NavalActualizacaoErro('Falha a descarregar o pacote (HTTP ' . $codigo . ').');
}

// ── Pacote ──────────────────────────────────────────────────────────────────────────────────────────

/**
 * Extrai o pacote para $dir, validando cada nome antes de escrever (nada fora da pasta). Usa a extensão zip
 * se existir; senão o Phar (incluído no PHP).
 */
function naval_act_extrair(string $zip, string $dir, bool $semZipArchive = false): void
{
    naval_act_limpar_pasta($dir);
    @mkdir($dir, 0770, true);
    $escrever = function (string $nome, string $conteudo) use ($dir) {
        if (substr($nome, -1) === '/') return;
        if (!naval_act_caminho_valido($nome)) throw new NavalActualizacaoErro('Pacote recusado: nome de ficheiro inválido (' . $nome . ').');
        $f = $dir . '/' . $nome;
        if (!is_dir(dirname($f))) @mkdir(dirname($f), 0770, true);
        if (file_put_contents($f, $conteudo) === false) throw new NavalActualizacaoErro('Não foi possível extrair ' . $nome);
    };
    if (class_exists('ZipArchive') && !$semZipArchive) {
        $z = new ZipArchive();
        if ($z->open($zip) !== true) throw new NavalActualizacaoErro('Pacote ZIP inválido.');
        for ($i = 0; $i < $z->numFiles; $i++) {
            $nome = (string) $z->getNameIndex($i);
            $c = $z->getFromIndex($i);
            if ($c === false) throw new NavalActualizacaoErro('Pacote ZIP danificado (' . $nome . ').');
            $escrever($nome, $c);
        }
        $z->close();
        return;
    }
    if (!class_exists('PharData')) throw new NavalActualizacaoErro('O PHP não tem as extensões zip nem phar para abrir o pacote.');
    // o Phar exige a extensão .zip no nome
    $copia = $dir . '.zip';
    copy($zip, $copia);
    try {
        $p = new PharData($copia);
        $prefixo = 'phar://' . str_replace('\\', '/', realpath($copia)) . '/';
        foreach (new RecursiveIteratorIterator($p) as $f) {
            $caminho = str_replace('\\', '/', $f->getPathname());
            $nome = strpos($caminho, $prefixo) === 0 ? substr($caminho, strlen($prefixo)) : '';
            if ($nome === '') throw new NavalActualizacaoErro('Pacote ZIP com nomes inválidos.');
            $escrever($nome, (string) file_get_contents($f->getPathname()));
        }
        unset($p);
    } finally {
        @unlink($copia);
    }
}

/** Lê e valida o manifesto do pacote extraído: devolve-o com os ficheiros conferidos. */
function naval_act_manifesto(string $dir): array
{
    $m = json_decode((string) @file_get_contents($dir . '/manifesto.json'), true);
    if (!is_array($m) || ($m['formato'] ?? 0) !== 1 || !is_array($m['ficheiros'] ?? null) || empty($m['versao'])) {
        throw new NavalActualizacaoErro('Pacote sem manifesto válido.');
    }
    foreach ($m['ficheiros'] as $c => $h) {
        $c = (string) $c;
        if (!naval_act_caminho_valido($c)) throw new NavalActualizacaoErro('Pacote recusado: caminho inválido no manifesto (' . $c . ').');
        if (naval_act_protegido($c)) throw new NavalActualizacaoErro('Pacote recusado: inclui um ficheiro protegido do servidor (' . $c . ').');
        if (naval_act_hash($dir . '/ficheiros/' . $c) !== $h) throw new NavalActualizacaoErro('Pacote recusado: ' . $c . ' não confere com o manifesto.');
    }
    // nada fora do manifesto
    $n = 0;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir . '/ficheiros', FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) $n++;
    if ($n !== count($m['ficheiros'])) throw new NavalActualizacaoErro('Pacote recusado: tem ficheiros fora do manifesto.');
    return $m;
}

/**
 * Plano: o que muda no servidor. Por ficheiro: igual, alterar, novo ou conflito (alterado no servidor desde a
 * última actualização — o hash actual não é o que o actualizador lá deixou nem o da versão nova).
 */
function naval_act_plano(array $manifesto, string $raiz, array $estado): array
{
    $p = ['alterar' => [], 'novos' => [], 'conflitos' => [], 'iguais' => 0];
    foreach ($manifesto['ficheiros'] as $c => $novo) {
        $c = (string) $c;
        $actual = naval_act_hash($raiz . '/' . $c);
        if ($actual === $novo) {
            $p['iguais']++;
            continue;
        }
        $anterior = $estado['ficheiros'][$c] ?? null;
        if ($actual !== null && $anterior !== null && $actual !== $anterior) $p['conflitos'][] = $c;
        elseif ($actual !== null && $anterior === null && !empty($estado['ficheiros'])) $p['conflitos'][] = $c;   // criado à mão
        elseif ($actual === null) $p['novos'][] = $c;
        else $p['alterar'][] = $c;
    }
    // pontos de entrada no fim
    $ordem = function (array $l) {
        usort($l, function ($a, $b) {
            $ea = in_array($a, NAVAL_ACT_ENTRADAS, true) ? 1 : 0;
            $eb = in_array($b, NAVAL_ACT_ENTRADAS, true) ? 1 : 0;
            return $ea !== $eb ? $ea - $eb : strcmp($a, $b);
        });
        return $l;
    };
    $p['alterar'] = $ordem($p['alterar']);
    $p['novos'] = $ordem($p['novos']);
    return $p;
}

/** Escreve um ficheiro ao lado e troca-o de uma vez (o Apache nunca serve um ficheiro a meio). */
function naval_act_escrever(string $origem, string $destino): void
{
    if (!is_dir(dirname($destino)) && !@mkdir(dirname($destino), 0775, true) && !is_dir(dirname($destino))) {
        throw new NavalActualizacaoErro('Não foi possível criar a pasta de ' . $destino);
    }
    $tmp = $destino . '.actualizacao-' . getmypid();
    if (!@copy($origem, $tmp)) throw new NavalActualizacaoErro('Não foi possível escrever ' . $destino . ' (permissões?).');
    if (!@rename($tmp, $destino)) {
        // No Windows não se pode trocar um ficheiro aberto (ex.: o próprio actualizador em execução), mas pode
        // escrever-se por cima: o PHP já o leu e compilou. Confirma-se o conteúdo escrito.
        $ok = DIRECTORY_SEPARATOR === '\\' && @copy($tmp, $destino) && hash_file('sha256', $destino) === hash_file('sha256', $tmp);
        @unlink($tmp);
        if (!$ok) throw new NavalActualizacaoErro('Não foi possível substituir ' . $destino . ' (ficheiro em uso?).');
    }
}

/**
 * Muda o nome de uma pasta com tolerância ao Windows: o antivírus (Microsoft Defender) e o indexador abrem por momentos
 * os ficheiros acabados de escrever, e o rename falha com «acesso negado». Tenta de novo com espera crescente (cerca de
 * 30 s no total); se continuar a falhar, copia a pasta e apaga a origem. Lança excepção com o erro do sistema.
 */
function naval_act_renomear_pasta(string $de, string $para, ?callable $esperar = null): void
{
    $esperar = $esperar ?? function (float $s) { usleep((int) ($s * 1e6)); };
    $erro = '';
    foreach ([0, 0.5, 1, 2, 4, 8, 15] as $s) {
        if ($s > 0) $esperar($s);
        clearstatcache();
        if (@rename($de, $para)) return;
        $erro = (string) ((error_get_last() ?: [])['message'] ?? '');
    }
    // último recurso: cópia completa e remoção da origem (mais lento, não depende de nenhum ficheiro estar livre)
    if (!is_dir($para)) {
        $n = naval_act_copiar_pasta($de, $para);
        $total = iterator_count(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($de, FilesystemIterator::SKIP_DOTS)));
        if ($n === $total) {
            naval_act_limpar_pasta($de);
            return;
        }
        naval_act_limpar_pasta($para);
    }
    throw new NavalActualizacaoErro('Não foi possível mudar ' . basename($de) . ' para ' . basename($para) . ($erro !== '' ? ' (' . $erro . ')' : '') . '.');
}

function naval_act_limpar_pasta(string $d): void
{
    if (!is_dir($d)) return;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    @rmdir($d);
}

// ── Verificações ────────────────────────────────────────────────────────────────────────────────────

/** Sintaxe dos ficheiros PHP novos ou alterados (antes de os copiar). Devolve a lista de erros. */
function naval_act_sintaxe(string $dir, array $ficheiros): array
{
    $erros = [];
    foreach ($ficheiros as $c) {
        if (substr($c, -4) !== '.php') continue;
        $saida = [];
        exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($dir . '/' . $c) . ' 2>&1', $saida, $r);
        if ($r !== 0) $erros[] = $c . ': ' . trim(implode(' ', $saida));
    }
    return $erros;
}

/**
 * O PHP do Apache guarda o código compilado (OPcache) e só repara num ficheiro alterado depois de
 * opcache.revalidate_freq segundos; com opcache.validate_timestamps=0 só depois de reiniciar o Apache. Lê a
 * configuração do php.ini (o mesmo do Apache no servidor), espera o necessário ou reinicia o serviço do Apache
 * (ACTUALIZACAO_APACHE_SERVICO, ex.: Apache2.4). Devolve um aviso, ou vazio.
 */
function naval_act_recarregar_php(): string
{
    $ligado = in_array(strtolower((string) ini_get('opcache.enable')), ['1', 'on', 'true'], true);
    $carimbos = !in_array(strtolower((string) ini_get('opcache.validate_timestamps')), ['0', 'off', 'false'], true);
    $servico = naval_config('ACTUALIZACAO_APACHE_SERVICO');
    if ($servico !== '' && (!$carimbos || naval_config('ACTUALIZACAO_REINICIAR_APACHE') === '1')) {
        if (!preg_match('/^[\w.\- ]{1,60}$/', $servico)) return 'ACTUALIZACAO_APACHE_SERVICO inválido.';
        exec('net stop "' . $servico . '" 2>&1 && net start "' . $servico . '" 2>&1', $o, $r);
        sleep(3);
        return $r === 0 ? '' : 'Não foi possível reiniciar o serviço ' . $servico . ': ' . trim(implode(' ', $o));
    }
    if ($ligado && !$carimbos) return 'opcache.validate_timestamps=0: o Apache só usa o código novo depois de reiniciado (indicar ACTUALIZACAO_APACHE_SERVICO no .env).';
    sleep(max(0, min(60, (int) ini_get('opcache.revalidate_freq'))) + 1);
    return '';
}

/** Endereço do portal para as verificações HTTP (ou vazio para não as fazer). */
function naval_act_url_portal(): string
{
    return rtrim(naval_config('ACTUALIZACAO_URL_VERIFICACAO', naval_config('PORTAL_URL')), '/');
}

/**
 * O portal responde? Entrada, portal, API (sem sessão → 401) e um ficheiro estático. Erros 5xx ou sem resposta
 * contam como falha. Devolve a lista de falhas (vazia = tudo bem).
 */
function naval_act_verificar_http(string $base): array
{
    $local = (bool) preg_match('#^https?://(localhost|127\.0\.0\.1|\[::1\])(:\d+)?$#i', $base);
    $falhas = [];
    $pedidos = ['geo/login.php' => [200], 'reports/' => [200, 302], 'api/index.php/v1/sessao' => [401], 'reports/js/main.js' => [200]];
    foreach ($pedidos as $c => $aceites) {
        $r = naval_http_pedido('GET', $base . '/' . $c, ['tempo' => 30, 'ligacao' => 10, 'verificar_ssl' => !$local]);
        if (!in_array($r['codigo'], $aceites, true)) $falhas[] = $c . ' → ' . ($r['errno'] ? 'sem resposta (' . $r['erro'] . ')' : 'HTTP ' . $r['codigo']);
    }
    return $falhas;
}

/** Verificação num processo novo (código já copiado): arranque, API e ligações às bases de dados. */
function naval_act_verificar_cli(string $raiz): array
{
    $saida = [];
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($raiz . '/ferramentas/actualizar.php') . ' verificar-instalacao 2>&1', $saida, $r);
    return $r === 0 ? [] : ['verificação interna: ' . trim(implode(' | ', array_slice($saida, -5)))];
}

// ── Migrações da base de dados do módulo Equipamentos ───────────────────────────────────────────────

/** Ficheiros de migração (ferramentas/sql/migracoes/NNNN-*.sql) por versão. */
function naval_act_migracoes(string $dir): array
{
    $l = [];
    foreach (glob($dir . '/*.sql') ?: [] as $f) {
        $v = naval_act_versao_migracao(basename($f));
        if ($v > 1) $l[$v] = $f;
    }
    ksort($l);
    return $l;
}

/** Versão do esquema na base de dados (tabela ATV_MIGRACAO; sem ela, 1 se o módulo estiver instalado). */
function naval_act_versao_bd(PDO $pdo): int
{
    try {
        $v = $pdo->query('SELECT MAX(Versao) AS V FROM ATV_MIGRACAO')->fetch(PDO::FETCH_ASSOC);
        return (int) ($v['V'] ?? 1);
    } catch (Throwable $e) {
        try {
            $pdo->query('SELECT COUNT(*) FROM ATV_ACTIVO')->fetch();
            return 1;
        } catch (Throwable $e2) {
            return 0;
        }
    }
}

/**
 * Aplica as migrações pendentes, cada uma numa transacção (lotes separados por «GO», como no SSMS). Usa a conta
 * ATV_DB_MIGRACAO_USER/PASS se existir (precisa de permissão para alterar tabelas); senão a do módulo.
 * Devolve as versões aplicadas. Sem base de dados do módulo configurada, não faz nada.
 */
function naval_act_migrar(string $dirMigracoes, bool $ensaio, ?PDO $pdo = null): array
{
    $todas = naval_act_migracoes($dirMigracoes);
    if (!$todas) return [];
    if ($pdo === null) {
        naval_servico('bd');
        if (naval_config('ATV_DB_DSN') === '' && naval_config('ATV_DB_NAME') === '') return [];
        $pdo = naval_bd_criar('ATV_DB', true, ['USER' => naval_config('ATV_DB_MIGRACAO_USER'), 'PASS' => naval_config('ATV_DB_MIGRACAO_PASS')]);
    }
    $actual = naval_act_versao_bd($pdo);
    if ($actual === 0) return [];   // módulo ainda não instalado: o script inicial é corrido à mão
    $pendentes = array_filter($todas, function ($v) use ($actual) { return $v > $actual; }, ARRAY_FILTER_USE_KEY);
    if ($ensaio) return array_keys($pendentes);
    $odbc = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'odbc';
    $aplicadas = [];
    foreach ($pendentes as $v => $f) {
        $sql = (string) file_get_contents($f);
        $lotes = array_filter(preg_split('/^\s*GO\s*;?\s*$/mi', $sql) ?: [], function ($l) { return trim((string) preg_replace('/^\s*--.*$/m', '', $l)) !== ''; });
        try {
            $pdo->beginTransaction();
            foreach ($lotes as $lote) $pdo->exec($odbc ? naval_bd_para_odbc($lote) : $lote);
            $st = $pdo->prepare('INSERT INTO ATV_MIGRACAO (Versao, Nome, AplicadaEm, Hash) VALUES (?, ?, ?, ?)');
            $st->execute([$v, basename($f), date('Y-m-d H:i:s'), hash('sha256', $sql)]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                try {
                    $pdo->rollBack();
                } catch (Throwable $e2) {
                    // o SQL Server já desfez a transacção (XACT_ABORT)
                }
            }
            throw new NavalActualizacaoErro('A migração ' . basename($f) . ' falhou e foi desfeita: ' . $e->getMessage()
                . (naval_config('ATV_DB_MIGRACAO_USER') === '' ? ' (sem ATV_DB_MIGRACAO_USER no .env, usa a conta do módulo, que pode não ter permissão para alterar tabelas)' : ''));
        }
        $aplicadas[] = $v;
    }
    return $aplicadas;
}

// ── Cópias de segurança e reversão ──────────────────────────────────────────────────────────────────

/** Guarda os ficheiros que vão ser substituídos e a lista dos novos. Devolve a pasta da cópia. */
function naval_act_copiar_antes(string $raiz, array $plano, array $estado, string $versaoNova): string
{
    $d = naval_act_pasta('copias') . '/' . date('Ymd-His') . '-' . preg_replace('/[^\w.\-]/', '_', $versaoNova);
    @mkdir($d . '/ficheiros', 0770, true);
    foreach (array_merge($plano['alterar'], $plano['conflitos']) as $c) {
        $destino = $d . '/ficheiros/' . $c;
        if (!is_dir(dirname($destino))) @mkdir(dirname($destino), 0770, true);
        if (!@copy($raiz . '/' . $c, $destino)) throw new NavalActualizacaoErro('Não foi possível guardar a cópia de ' . $c);
    }
    naval_json_gravar($d . '/lista.json', ['versao_anterior' => $estado['versao'] ?? '', 'commit_anterior' => $estado['commit'] ?? '', 'versao_nova' => $versaoNova,
        'substituidos' => array_merge($plano['alterar'], $plano['conflitos']), 'novos' => $plano['novos'], 'criada_em' => date('Y-m-d H:i:s')]);
    return $d;
}

/** Repõe uma cópia: ficheiros substituídos voltam ao que eram; os criados pela actualização são retirados. */
function naval_act_repor(string $raiz, string $copia): array
{
    $l = naval_json_ler($copia . '/lista.json');
    if (!$l) throw new NavalActualizacaoErro('Cópia de segurança sem lista: ' . $copia);
    $erros = [];
    foreach ($l['substituidos'] ?? [] as $c) {
        try {
            naval_act_escrever($copia . '/ficheiros/' . $c, $raiz . '/' . $c);
        } catch (Throwable $e) {
            $erros[] = $e->getMessage();
        }
    }
    foreach ($l['novos'] ?? [] as $c) {
        if (naval_act_caminho_valido($c) && is_file($raiz . '/' . $c) && !@unlink($raiz . '/' . $c)) $erros[] = 'Não foi possível retirar ' . $c;
    }
    return $erros;
}

/** Mantém só as últimas cópias de segurança e pacotes. */
function naval_act_arrumar(): void
{
    foreach (['copias' => NAVAL_ACT_COPIAS, 'pacotes' => 2] as $sub => $manter) {
        $l = glob(naval_act_pasta($sub) . '/*', GLOB_ONLYDIR) ?: [];
        sort($l);
        foreach (array_slice($l, 0, max(0, count($l) - $manter)) as $d) naval_act_limpar_pasta($d);
    }
}

function naval_act_ultima_copia(): ?string
{
    $l = glob(naval_act_pasta('copias') . '/*', GLOB_ONLYDIR) ?: [];
    sort($l);
    return $l ? end($l) : null;
}

// ── Aviso por correio electrónico ───────────────────────────────────────────────────────────────────

function naval_act_avisar(string $assunto, array $linhas): void
{
    $para = array_filter(array_map('trim', explode(',', naval_config('ACTUALIZACAO_EMAIL'))));
    if (!$para) return;
    try {
        naval_servico('email');
        $html = '<div style="font-family:Segoe UI,Arial,sans-serif;font-size:14px;color:#1c2340;text-align:justify">'
            . '<h2 style="color:#273777;margin:0 0 12px">' . htmlspecialchars($assunto, ENT_QUOTES, 'UTF-8') . '</h2>';
        foreach ($linhas as $l) $html .= '<p style="margin:0 0 8px">' . htmlspecialchars($l, ENT_QUOTES, 'UTF-8') . '</p>';
        $html .= '<p style="color:#5b6478;font-size:12px">Servidor ' . htmlspecialchars((string) gethostname(), ENT_QUOTES, 'UTF-8') . ' · ' . date('d-m-Y H:i') . '</p></div>';
        naval_enviar_email(array_map(function ($e) { return ['email' => $e]; }, $para), 'Portal RTM · ' . $assunto, $html);
    } catch (Throwable $e) {
        naval_act_registar('aviso_falhou', ['erro' => $e->getMessage()]);
    }
}

// ── Aplicar uma versão ──────────────────────────────────────────────────────────────────────────────

/**
 * Aplica a versão mais recente (ou o pacote local $o['pacote']). Opções: pacote, ensaio (só mostra o plano),
 * forcar (substitui ficheiros alterados no servidor; deles fica cópia), sem_http, raiz (testes), escrever (callable).
 * Devolve ['resultado' => actualizada|sem_alteracoes|ensaio|conflito|revertida, ...]. Erros: NavalActualizacaoErro.
 */
function naval_act_aplicar(array $o = []): array
{
    $o += ['pacote' => null, 'ensaio' => false, 'forcar' => false, 'sem_http' => false, 'raiz' => naval_raiz(), 'escrever' => function ($s) { echo $s, "\n"; }];
    $diz = $o['escrever'];
    $raiz = rtrim(str_replace('\\', '/', (string) $o['raiz']), '/');
    $bloqueio = fopen(naval_act_pasta() . '/.bloqueio', 'c');
    if (!$bloqueio || !flock($bloqueio, LOCK_EX | LOCK_NB)) throw new NavalActualizacaoErro('Já está a correr outra actualização.');
    $t0 = microtime(true);
    try {
        $estado = naval_act_estado();
        $avisos = [];
        $u = [];

        // 1. Pacote
        if ($o['pacote']) {
            $zip = (string) $o['pacote'];
            if (!is_file($zip)) throw new NavalActualizacaoErro('Pacote não encontrado: ' . $zip);
            if (is_file($zip . '.sha256')) {
                $esperado = strtolower(substr(trim((string) file_get_contents($zip . '.sha256')), 0, 64));
                if (!hash_equals($esperado, (string) hash_file('sha256', $zip))) throw new NavalActualizacaoErro('O SHA-256 do pacote não confere.');
            }
            $origem = 'ficheiro ' . basename($zip);
        } else {
            if (naval_act_exige_aprovacao() && !$o['ensaio']) {
                // só versões aprovadas no portal (o ensaio «verificar» compara sempre com a mais recente publicada) (página «Actualizações»), presas ao SHA-256 aprovado
                $versoes = naval_act_versoes();
                if (!empty($o['avisar_pendentes'])) naval_act_avisar_pendentes($versoes, $estado);
                $u = naval_act_alvo_aprovado($versoes, $estado, naval_act_aprovacoes());
                if ($u === null) {
                    $n = 0;
                    foreach (naval_act_mais_recentes($versoes, $estado) as $x) if (!isset(naval_act_aprovacoes()[$x['versao']])) $n++;
                    $diz($n ? $n . ' versão(ões) publicada(s) à espera de aprovação no portal (Administração › Actualizações).' : 'Sem versões aprovadas por instalar.');
                    return ['resultado' => 'sem_aprovacao', 'versao' => (string) ($estado['versao'] ?? ''), 'pendentes' => $n, 'avisos' => $avisos];
                }
            } else {
                $u = naval_act_ultima_versao();
            }
            if ($u['dias_token'] !== null && $u['dias_token'] <= 30) {
                $avisos[] = 'O código de acesso do GitHub expira dentro de ' . $u['dias_token'] . ' dia(s): criar outro e correr «php ferramentas/actualizar.php configurar».';
            }
            if ($u['versao'] === ($estado['versao'] ?? '') && !$o['forcar']) {
                $diz('Já está na versão mais recente (' . $u['versao'] . ').');
                return ['resultado' => 'sem_alteracoes', 'versao' => $u['versao'], 'avisos' => $avisos];
            }
            if ($u['versao'] === ($estado['recusada'] ?? '') && !$o['forcar']) {
                $diz('A versão ' . $u['versao'] . ' foi revertida antes; fica de fora até haver uma mais recente (ou «aplicar --forcar»).');
                return ['resultado' => 'recusada', 'versao' => $u['versao'], 'avisos' => $avisos];
            }
            $zip = naval_act_pasta('transferencias') . '/portal-rtm.zip';
            $diz('A descarregar a versão ' . $u['versao'] . '…');
            naval_act_descarregar($u['zip'], $zip);
            naval_act_descarregar($u['sha'], $zip . '.sha256');
            $esperado = strtolower(substr(trim((string) file_get_contents($zip . '.sha256')), 0, 64));
            if (!hash_equals($esperado, (string) hash_file('sha256', $zip))) throw new NavalActualizacaoErro('O SHA-256 do pacote descarregado não confere: actualização cancelada.');
            if (isset($u['sha256_aprovado']) && !hash_equals($u['sha256_aprovado'], $esperado)) {
                throw new NavalActualizacaoErro('O pacote da versão ' . $u['versao'] . ' mudou depois de aprovado: não é instalado. Volte a aprová-lo no portal se for o caso.');
            }
            $origem = 'GitHub ' . $u['versao'];
        }

        // 2. Extrair e validar
        $dir = naval_act_pasta('pacotes') . '/' . date('Ymd-His');
        naval_act_extrair($zip, $dir, !empty($o['sem_ziparchive']));
        $m = naval_act_manifesto($dir);
        $diz('Pacote ' . $m['versao'] . ' (' . count($m['ficheiros']) . ' ficheiros, commit ' . substr((string) ($m['commit'] ?? ''), 0, 7) . ') conferido.');

        // 3. Plano
        $p = naval_act_plano($m, $raiz, $estado);
        $diz(sprintf('Plano: %d a substituir, %d novos, %d iguais, %d alterados no servidor.', count($p['alterar']), count($p['novos']), $p['iguais'], count($p['conflitos'])));
        foreach ($p['conflitos'] as $c) $diz('  alterado no servidor desde a última actualização: ' . $c);
        $migracoes = naval_act_migrar($dir . '/ficheiros/ferramentas/sql/migracoes', true);
        if ($migracoes) $diz('Migrações da base de dados a aplicar: ' . implode(', ', $migracoes));
        if ($o['ensaio']) return ['resultado' => 'ensaio', 'versao' => $m['versao'], 'plano' => $p, 'migracoes' => $migracoes, 'avisos' => $avisos];
        if ($p['conflitos'] && !$o['forcar'] && empty($u['forcar_aprovado'])) {
            naval_act_registar('conflito', ['versao' => $m['versao'], 'ficheiros' => $p['conflitos']]);
            return ['resultado' => 'conflito', 'versao' => $m['versao'], 'plano' => $p, 'avisos' => $avisos];
        }
        $mudar = array_merge($p['alterar'], $p['conflitos'], $p['novos']);

        // 4. Sintaxe do PHP novo (antes de mexer no servidor)
        $erros = naval_act_sintaxe($dir . '/ficheiros', $mudar);
        if ($erros) throw new NavalActualizacaoErro('Erros de sintaxe no pacote (nada foi alterado): ' . implode('; ', $erros));

        // 5. Requisitos do servidor (versão do PHP e extensões, activadas no php.ini com cópia) e migrações da base de
        //    dados (compatíveis com o código em produção: vão antes dos ficheiros)
        [$iniAlterado, $iniCopia] = naval_act_requisitos($dir . '/ficheiros', $diz);
        $reporIni = function () use ($iniAlterado, $iniCopia) {
            if ($iniAlterado && is_file($iniCopia)) @copy($iniCopia, naval_act_php_ini());
        };
        try {
            $aplicadas = $migracoes ? naval_act_migrar($dir . '/ficheiros/ferramentas/sql/migracoes', false) : [];
        } catch (Throwable $e) {
            $reporIni();
            throw $e;
        }

        $ficheirosNovos = $m['ficheiros'];
        $concluir = function (string $resultado, array $extra = []) use (&$estado, $m, $ficheirosNovos, $origem, $t0, $avisos) {
            $estado = ['versao' => $m['versao'], 'commit' => (string) ($m['commit'] ?? ''), 'aplicada_em' => date('Y-m-d H:i:s'), 'origem' => $origem,
                'ficheiros' => array_merge($estado['ficheiros'] ?? [], $ficheirosNovos)];
            naval_act_gravar_estado($estado);
            naval_act_registar($resultado, ['versao' => $m['versao'], 'seg' => round(microtime(true) - $t0, 1)] + $extra);
            return ['resultado' => $resultado, 'versao' => $m['versao'], 'avisos' => $avisos] + $extra;
        };
        if (!$mudar && !$iniAlterado) {
            $diz('Nenhum ficheiro a alterar.');
            return $concluir('sem_alteracoes', ['migracoes' => $aplicadas]);
        }

        // 6. Cópia de segurança e cópia dos ficheiros
        $base = $o['sem_http'] ? '' : naval_act_url_portal();
        $httpAntes = $base !== '' ? naval_act_verificar_http($base) : ['sem endereço'];
        if ($base !== '' && $httpAntes) $diz('Aviso: o portal já não respondia bem antes da actualização (' . implode('; ', $httpAntes) . '); a verificação HTTP fica de fora.');
        $copia = naval_act_copiar_antes($raiz, $p, $estado, $m['versao']);
        $diz('Cópia de segurança: ' . basename($copia));
        try {
            foreach ($mudar as $c) naval_act_escrever($dir . '/ficheiros/' . $c, $raiz . '/' . $c);
        } catch (Throwable $e) {
            $erros = naval_act_repor($raiz, $copia);
            naval_act_registar('revertida', ['versao' => $m['versao'], 'motivo' => $e->getMessage(), 'erros_reposicao' => $erros]);
            throw new NavalActualizacaoErro('Falha a copiar (' . $e->getMessage() . '); versão anterior reposta' . ($erros ? ' com erros: ' . implode('; ', $erros) : '') . '.');
        }
        $diz(count($mudar) . ' ficheiros copiados.');
        // configuração do Apache (ferramentas/apache) ou php.ini alterados: validar (httpd -t) e reiniciar o Apache
        $reiniciar = $iniAlterado || (bool) array_filter($mudar, function ($c) { return strpos($c, 'ferramentas/apache/portal-rtm') === 0; });
        if ($reiniciar) {
            $erroConf = naval_act_apache_testar();
            if ($erroConf !== '') {
                $erros = naval_act_repor($raiz, $copia);
                $reporIni();
                $estado['recusada'] = $m['versao'];
                naval_act_gravar_estado($estado);
                naval_act_registar('revertida', ['versao' => $m['versao'], 'falhas' => ['configuração do Apache: ' . $erroConf], 'erros_reposicao' => $erros]);
                $diz('FALHA na validação da configuração do Apache (httpd -t): ' . $erroConf . '. Versão anterior reposta; o Apache não foi reiniciado.');
                return ['resultado' => 'revertida', 'versao' => $m['versao'], 'falhas' => ['configuração do Apache: ' . $erroConf], 'erros_reposicao' => $erros, 'avisos' => $avisos];
            }
            $aviso = naval_act_apache_reiniciar();
            $diz($aviso === '' ? 'Configuração do Apache validada e Apache reiniciado.' : 'Aviso: ' . $aviso);
        } else {
            $aviso = naval_act_recarregar_php();
        }
        if ($aviso !== '') {
            $avisos[] = $aviso;
            if (!$reiniciar) $diz('Aviso: ' . $aviso);
        }

        // 7. Confirmar que o portal funciona; senão, repor
        $falhas = naval_act_verificar_cli($raiz);
        if ($base !== '' && !$httpAntes) $falhas = array_merge($falhas, naval_act_verificar_http($base));
        if ($falhas) {
            $erros = naval_act_repor($raiz, $copia);
            $reporIni();
            if ($reiniciar) naval_act_apache_reiniciar();
            else naval_act_recarregar_php();
            $depois = ($base !== '' && !$httpAntes) ? naval_act_verificar_http($base) : [];
            $estado['recusada'] = $m['versao'];   // não volta a ser aplicada sozinha todas as noites
            naval_act_gravar_estado($estado);
            naval_act_registar('revertida', ['versao' => $m['versao'], 'falhas' => $falhas, 'erros_reposicao' => $erros, 'depois' => $depois]);
            $diz('FALHA na verificação: ' . implode('; ', $falhas));
            $diz($erros ? 'Reposição com erros: ' . implode('; ', $erros) : 'Versão anterior reposta' . ($depois ? ', mas o portal continua com falhas: ' . implode('; ', $depois) : ' e a funcionar') . '.');
            return ['resultado' => 'revertida', 'versao' => $m['versao'], 'falhas' => $falhas, 'erros_reposicao' => $erros, 'avisos' => $avisos];
        }
        naval_act_arrumar();
        $diz('Portal verificado' . ($base !== '' && !$httpAntes ? ' (linha de comandos e HTTP)' : ' (linha de comandos)') . '.');
        return $concluir('actualizada', ['ficheiros' => count($mudar), 'migracoes' => $aplicadas, 'copia' => basename($copia)]);
    } finally {
        flock($bloqueio, LOCK_UN);
        fclose($bloqueio);
    }
}

/** Repõe a última cópia de segurança (comando «reverter»). */
function naval_act_reverter(string $raiz): array
{
    $copia = naval_act_ultima_copia();
    if (!$copia) throw new NavalActualizacaoErro('Não há cópias de segurança de actualizações.');
    $l = naval_json_ler($copia . '/lista.json');
    $erros = naval_act_repor($raiz, $copia);
    $e = naval_act_estado();
    foreach (array_merge($l['substituidos'] ?? [], $l['novos'] ?? []) as $c) {
        $h = naval_act_hash($raiz . '/' . $c);
        if ($h === null) unset($e['ficheiros'][$c]);
        else $e['ficheiros'][$c] = $h;
    }
    $e['versao'] = (string) ($l['versao_anterior'] ?? '');
    $e['commit'] = (string) ($l['commit_anterior'] ?? '');
    $e['aplicada_em'] = date('Y-m-d H:i:s');
    $e['origem'] = 'reversão manual';
    $e['recusada'] = (string) ($l['versao_nova'] ?? '');
    naval_act_gravar_estado($e);
    // com erros a cópia fica, para se poder repetir ou repor à mão o que faltou (e não recuar para uma cópia mais antiga)
    if (!$erros) naval_act_limpar_pasta($copia);
    naval_act_registar('reversao_manual', ['para' => $e['versao'], 'erros' => $erros, 'copia_mantida' => (bool) $erros]);
    return ['versao' => $e['versao'], 'erros' => $erros];
}

// ── Aprovação das versões (página «Actualizações» do portal, só o super administrador) ──────────────
//
// Gestão de alterações (ITIL): o GitHub publica versões já testadas; nenhuma é instalada sem ser aprovada no portal
// (ACTUALIZACAO_EXIGE_APROVACAO=1, por omissão). A aprovação fica presa ao SHA-256 exacto do pacote: se o pacote
// publicado mudar depois de aprovado, não é instalado. Cada versão inclui as anteriores; aprovar uma instala também o
// que vinha antes dela. «Instalar já» e «Repor a versão anterior» são tratados pela tarefa agendada de 5 em 5 minutos
// (o servidor web nunca altera os ficheiros do portal).

function naval_act_exige_aprovacao(): bool
{
    return naval_config('ACTUALIZACAO_EXIGE_APROVACAO', '1') !== '0';
}

/** Decisões: {versao: {decisao: aprovada|reprovada, por, em, motivo, sha256, urgente, publicada_em, tentada_em}}. */
function naval_act_aprovacoes(): array
{
    return naval_json_ler(naval_act_pasta() . '/aprovacoes.json');
}

function naval_act_gravar_aprovacoes(array $a): void
{
    naval_json_gravar(naval_act_pasta() . '/aprovacoes.json', $a);
}

/** Data de publicação da versão instalada (vazia se não estiver na lista). */
function naval_act_publicada_instalada(array $versoes, array $estado): string
{
    foreach ($versoes as $v) if ($v['versao'] === ($estado['versao'] ?? '')) return $v['publicada_em'];
    return '';
}

/** Versões mais recentes do que a instalada. */
function naval_act_mais_recentes(array $versoes, array $estado): array
{
    $instalada = (string) ($estado['versao'] ?? '');
    $desde = naval_act_publicada_instalada($versoes, $estado);
    return array_values(array_filter($versoes, function ($v) use ($instalada, $desde) {
        return $v['versao'] !== $instalada && ($desde === '' || strcmp($v['publicada_em'], $desde) > 0);
    }));
}

/** Versão a instalar: a mais recente aprovada (e não recusada) acima da instalada; null se não houver. */
function naval_act_alvo_aprovado(array $versoes, array $estado, array $aprov): ?array
{
    foreach (naval_act_mais_recentes($versoes, $estado) as $v) {
        $a = $aprov[$v['versao']] ?? null;
        if ($a && ($a['decisao'] ?? '') === 'aprovada' && $v['versao'] !== ($estado['recusada'] ?? '')) {
            return $v + ['sha256_aprovado' => (string) ($a['sha256'] ?? ''), 'forcar_aprovado' => !empty($a['forcar'])];
        }
    }
    return null;
}

/** SHA-256 publicado de uma versão (o ficheiro .sha256 do pacote). */
function naval_act_sha_publicado(array $v): string
{
    $tmp = naval_act_pasta('transferencias') . '/sha-' . getmypid() . '.txt';
    try {
        naval_act_descarregar($v['sha'], $tmp);
        $h = strtolower(substr(trim((string) file_get_contents($tmp)), 0, 64));
    } finally {
        @unlink($tmp);
    }
    if (!preg_match('/^[0-9a-f]{64}$/', $h)) throw new NavalActualizacaoErro('A versão ' . $v['versao'] . ' não tem um SHA-256 válido publicado.');
    return $h;
}

/**
 * Regista a decisão do super administrador sobre uma versão publicada: aprovar (opcionalmente «instalar já»),
 * reprovar (com motivo) ou cancelar uma aprovação ainda não instalada. Devolve a decisão gravada.
 */
function naval_act_decidir(string $versao, string $accao, string $login, string $motivo = '', bool $urgente = false, ?array $versoes = null, bool $automatica = false): array
{
    return naval_exclusivo('actualizacoes-aprovacoes', function () use ($versao, $accao, $login, $motivo, $urgente, $versoes, $automatica) {
        $versoes = $versoes ?? naval_act_versoes();
        $v = null;
        foreach ($versoes as $x) if ($x['versao'] === $versao) $v = $x;
        if ($v === null) throw new NavalErro('Versão desconhecida: ' . $versao, 404);
        $estado = naval_act_estado();
        $aprov = naval_act_aprovacoes();
        $pendente = in_array($versao, array_column(naval_act_mais_recentes($versoes, $estado), 'versao'), true);
        if ($accao !== 'cancelar' && !$pendente) throw new NavalErro('Esta versão já está instalada ou é anterior à instalada.', 409);
        if (in_array($accao, ['aprovar', 'forcar'], true) && !$automatica) {   // a automática pode deixar de fora uma recém-publicada
            // (uma mais recente reprovada não conta: pode instalar-se a anterior)
            foreach (naval_act_mais_recentes($versoes, $estado) as $x) {
                if (strcmp($x['publicada_em'], $v['publicada_em']) > 0 && ($aprov[$x['versao']]['decisao'] ?? '') !== 'reprovada') {
                    throw new NavalErro('Aprove a versão mais recente, ' . $x['versao'] . ': já inclui a ' . $versao . ' e todas as anteriores.', 409);
                }
            }
        }
        $motivo = trim(preg_replace('/\s+/', ' ', $motivo));
        switch ($accao) {
            case 'aprovar':
            case 'forcar':
                // só a versão que passou no ensaio, com o mesmo pacote (SHA-256)
                $sha = naval_act_sha_publicado($v);
                $bloqueio = naval_act_ensaio_bloqueio($versao, $sha);
                if ($bloqueio !== '') throw new NavalErro($bloqueio, 409);
                // «forcar»: aplicar mesmo com ficheiros alterados à mão no servidor (ficam na cópia de segurança); instala já
                $aprov[$versao] = ['decisao' => 'aprovada', 'por' => $login, 'em' => date('Y-m-d H:i:s'), 'motivo' => mb_substr($motivo, 0, 500),
                    'sha256' => $sha, 'urgente' => $urgente || $accao === 'forcar', 'forcar' => $accao === 'forcar',
                    'publicada_em' => $v['publicada_em'], 'commit' => $v['commit']] + ($automatica ? ['automatica' => true] : []);
                break;
            case 'reprovar':
                if (mb_strlen($motivo) < 5) throw new NavalErro('Indique o motivo da reprovação.', 400);
                $aprov[$versao] = ['decisao' => 'reprovada', 'por' => $login, 'em' => date('Y-m-d H:i:s'), 'motivo' => mb_substr($motivo, 0, 500),
                    'publicada_em' => $v['publicada_em'], 'commit' => $v['commit']];
                break;
            case 'cancelar':
                if (($aprov[$versao]['decisao'] ?? '') === '' || !$pendente) throw new NavalErro('Não há decisão por instalar para anular nesta versão.', 409);
                unset($aprov[$versao]);
                break;
            default:
                throw new NavalErro('Acção desconhecida.', 400);
        }
        naval_act_gravar_aprovacoes($aprov);
        naval_registar('actualizacao_' . ($accao === 'aprovar' ? 'aprovada' : ($accao === 'forcar' ? 'aprovada_forcar' : ($accao === 'reprovar' ? 'reprovada' : 'decisao_anulada'))),
            ['versao' => $versao, 'urgente' => $urgente, 'motivo' => $motivo], $login);
        naval_act_registar('decisao', ['versao' => $versao, 'accao' => $accao, 'por' => $login, 'urgente' => $urgente]);
        return $aprov[$versao] ?? [];
    });
}

/** Pedido de reposição da versão anterior (feito no portal; executado pela tarefa de 5 em 5 minutos). */
function naval_act_pedir_reversao(string $login): void
{
    if (!naval_act_ultima_copia()) throw new NavalErro('Não há nenhuma actualização automática para desfazer.', 409);
    naval_json_gravar(naval_act_pasta() . '/pedido-reversao.json', ['por' => $login, 'em' => date('Y-m-d H:i:s')]);
    naval_registar('actualizacao_reversao_pedida', [], $login);
}

/** Resultado mais recente de cada versão no registo das actualizações (actualizada, revertida, conflito, erro…). */
function naval_act_resultados(): array
{
    $f = naval_act_pasta() . '/registo.jsonl';
    $out = [];
    if (!is_file($f)) return $out;
    $linhas = array_slice(file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [], -2000);
    foreach ($linhas as $l) {
        $j = json_decode($l, true);
        if (!is_array($j) || empty($j['versao']) || in_array($j['accao'] ?? '', ['decisao', 'aviso_falhou'], true)) continue;
        $out[(string) $j['versao']] = ['accao' => (string) $j['accao'], 'data' => (string) ($j['data'] ?? '')]
            + ($j['accao'] === 'conflito' ? ['ficheiros' => array_slice((array) ($j['ficheiros'] ?? []), 0, 50)] : []);
    }
    return $out;
}

/** Última execução do actualizador (para o portal). */
function naval_act_ultima_execucao(): ?array
{
    $f = naval_act_pasta() . '/registo.jsonl';
    if (!is_file($f)) return null;
    $linhas = array_reverse(array_slice(file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [], -200));
    foreach ($linhas as $l) {
        $j = json_decode($l, true);
        if (is_array($j) && !in_array($j['accao'] ?? '', ['decisao', 'aviso_falhou', 'aprovacao_automatica_erro', 'pendentes_erro', 'certificados_erro'], true)) return $j;
    }
    return null;
}

/**
 * Tarefa de 5 em 5 minutos (comando «urgente»): executa um pedido de reposição feito no portal ou instala uma versão
 * aprovada com «instalar já». Sem pedidos, não faz nada (nem contacta o GitHub). Devolve o resultado ou null.
 */
function naval_act_urgente(array $o = []): ?array
{
    $pedido = naval_act_pasta() . '/pedido-reversao.json';
    if (is_file($pedido)) {
        $p = naval_json_ler($pedido);
        @unlink($pedido);
        $r = naval_act_reverter((string) ($o['raiz'] ?? naval_raiz()));
        naval_act_registar('reversao_portal', ['versao' => $r['versao'], 'por' => $p['por'] ?? '', 'erros' => $r['erros']]);
        return ['resultado' => $r['erros'] ? 'erro' : 'revertida_pedido', 'versao' => $r['versao'], 'erros' => $r['erros']];
    }
    $aprov = naval_act_aprovacoes();
    $urgente = '';
    foreach ($aprov as $versao => $a) {
        if (($a['decisao'] ?? '') === 'aprovada' && !empty($a['urgente']) && empty($a['tentada_em'])) $urgente = (string) $versao;
    }
    if ($urgente === '') return null;
    $aprov[$urgente]['tentada_em'] = date('Y-m-d H:i:s');
    naval_act_gravar_aprovacoes($aprov);
    return naval_act_aplicar($o);
}

// ── Janela nocturna: aprovação automática (02:45) e instalação (03:00) ─────────────────────────────────
//
// As versões que ninguém decidiu durante o dia são aprovadas sozinhas às 02:45 (só a mais recente: inclui as
// anteriores; uma versão reprovada ou revertida nunca é aprovada assim) e tudo o que estiver aprovado é instalado às
// 03:00, fora do horário de trabalho, com cópia de segurança, verificação e reposição automática se falhar. Corre na
// tarefa de 5 em 5 minutos (não é preciso mudar as tarefas do Windows); se o servidor estiver desligado à hora certa,
// faz-se no primeiro ciclo até às 06:00. Cada passo corre uma vez por dia. ACTUALIZACAO_APROVACAO_AUTOMATICA e
// ACTUALIZACAO_INSTALACAO_NOCTURNA mudam as horas (HH:MM); «0» desliga a aprovação automática.
// Salvaguardas (gestão de alterações, ITIL / ISO 20000; «deployment window» fora de horas): só se aprovam versões
// publicadas há pelo menos ACTUALIZACAO_APROVACAO_IDADE_MIN minutos (60, para haver tempo de as ver ou reprovar);
// se a última instalação falhou, foi revertida ou parou por conflito, não se aprova nada sozinho até uma pessoa
// decidir (disjuntor); a decisão fica registada em nome de «automatico» e o super administrador recebe um correio.

const NAVAL_ACT_NOITE_FIM = '06:00';

/** Horas da janela nocturna: ['aprovar' => 'HH:MM' ou '', 'instalar' => 'HH:MM']. */
function naval_act_horas_noite(): array
{
    $h = function (string $chave, string $omissao): string {
        $v = trim(naval_config($chave, $omissao));
        if ($v === '0' || strtolower($v) === 'nao') return '';
        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v) ? $v : $omissao;
    };
    return ['aprovar' => $h('ACTUALIZACAO_APROVACAO_AUTOMATICA', '02:45'), 'instalar' => $h('ACTUALIZACAO_INSTALACAO_NOCTURNA', '03:00')];
}

/** Está dentro da janela [desde, 06:00[ do dia de $agora? */
function naval_act_na_janela(string $desde, int $agora): bool
{
    $hm = date('H:i', $agora);
    return $desde !== '' && $hm >= $desde && $hm < NAVAL_ACT_NOITE_FIM;
}

/**
 * Versão que a aprovação automática aprova: a mais recente por decidir, publicada há pelo menos $idadeMin minutos,
 * que não esteja reprovada nem revertida. As recém-publicadas ficam para a noite seguinte.
 */
function naval_act_a_aprovar_sozinha(array $versoes, array $estado, array $aprov, int $agora, int $idadeMin = 60): ?array
{
    foreach (naval_act_mais_recentes($versoes, $estado) as $v) {   // da mais recente para a mais antiga
        $d = $aprov[$v['versao']]['decisao'] ?? '';
        if ($d === 'reprovada') continue;                          // uma reprovada deixa aprovar a anterior
        if ($d === 'aprovada') return null;                        // já há uma aprovada mais recente: nada a fazer
        if ($v['versao'] === ($estado['recusada'] ?? '')) return null;
        $pub = strtotime($v['publicada_em']);
        if ($pub === false || $pub > $agora - $idadeMin * 60) continue;   // recém-publicada: ainda não
        return $v;
    }
    return null;
}

/** Disjuntor: a última instalação ou reposição correu mal? (devolve a acção, ou '') */
function naval_act_ultima_falha(): string
{
    $f = naval_act_pasta() . '/registo.jsonl';
    if (!is_file($f)) return '';
    $finais = ['actualizada', 'revertida', 'conflito', 'erro', 'reversao_portal', 'reversao_manual'];
    foreach (array_reverse(array_slice(file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [], -500)) as $l) {
        $j = json_decode($l, true);
        $a = is_array($j) ? (string) ($j['accao'] ?? '') : '';
        if (in_array($a, $finais, true)) return $a === 'actualizada' ? '' : $a;
    }
    return '';
}

/**
 * Passo nocturno da tarefa de 5 em 5 minutos. Devolve o resultado de naval_act_aplicar quando instala, ou null.
 * $o: opções de naval_act_aplicar, mais 'agora' (testes) e 'versoes' (testes).
 */
function naval_act_noite(array $o = []): ?array
{
    if (!naval_act_exige_aprovacao()) return null;
    $agora = (int) ($o['agora'] ?? time());
    $horas = naval_act_horas_noite();
    $aprovar = naval_act_na_janela($horas['aprovar'], $agora);
    $instalar = naval_act_na_janela($horas['instalar'], $agora);
    if (!$aprovar && !$instalar) return null;
    $f = naval_act_pasta() . '/noite.json';
    $hoje = date('Y-m-d', $agora);
    $n = naval_json_ler($f);
    if ($aprovar && ($n['aprovada'] ?? '') !== $hoje) {
        $n['aprovada'] = $hoje;
        naval_json_gravar($f, $n);
        try {
            $versoes = $o['versoes'] ?? naval_act_versoes();
            $idade = max(0, (int) naval_config('ACTUALIZACAO_APROVACAO_IDADE_MIN', '60'));
            $v = naval_act_a_aprovar_sozinha($versoes, naval_act_estado(), naval_act_aprovacoes(), $agora, $idade);
            $falha = $v ? naval_act_ultima_falha() : '';
            if ($v && $falha !== '') {
                naval_act_registar('aprovacao_automatica_suspensa', ['versao' => $v['versao'], 'motivo' => $falha]);
                naval_act_avisar('aprovação automática suspensa', [
                    'A versão ' . $v['versao'] . ' não foi aprovada automaticamente: a última instalação não terminou bem (' . $falha . ').',
                    'Por segurança, fica à espera de uma decisão no Portal RTM › Administração › Actualizações.',
                ]);
                $v = null;
            }
            if ($v) {
                naval_act_decidir($v['versao'], 'aprovar', 'automatico', 'Aprovação automática às ' . $horas['aprovar'] . ': não foi decidida durante o dia.', false, $versoes, true);
                naval_act_avisar('versão ' . $v['versao'] . ' aprovada automaticamente', [
                    'A versão ' . $v['versao'] . ' não foi aprovada nem reprovada durante o dia e foi aprovada automaticamente às ' . $horas['aprovar'] . '.',
                    'É instalada às ' . $horas['instalar'] . ', com cópia de segurança e verificação; se falhar, a anterior é reposta sozinha.',
                ]);
            }
        } catch (Throwable $e) {
            naval_act_registar('aprovacao_automatica_erro', ['erro' => $e->getMessage()]);
        }
    }
    if ($instalar && ($n['instalada'] ?? '') !== $hoje) {
        $n['instalada'] = $hoje;
        naval_json_gravar($f, $n);
        $versoes = $o['versoes'] ?? naval_act_versoes();
        if (naval_act_alvo_aprovado($versoes, naval_act_estado(), naval_act_aprovacoes()) === null) return null;
        unset($o['agora'], $o['versoes']);
        return naval_act_aplicar($o);
    }
    return null;
}

/** Avisa por correio, uma vez por versão, as versões novas à espera de aprovação. */
function naval_act_avisar_pendentes(array $versoes, array $estado): void
{
    $aprov = naval_act_aprovacoes();
    $f = naval_act_pasta() . '/avisadas.json';
    $avisadas = naval_json_ler($f);
    $novas = [];
    foreach (naval_act_mais_recentes($versoes, $estado) as $v) {
        if (!isset($aprov[$v['versao']]) && !in_array($v['versao'], $avisadas, true)) $novas[] = $v['versao'];
    }
    if (!$novas) return;
    naval_json_gravar($f, array_slice(array_merge($avisadas, $novas), -100));
    $url = rtrim(naval_config('PORTAL_URL'), '/');
    naval_act_avisar('versão nova à espera de aprovação', [
        'Versões publicadas e testadas à espera da sua decisão: ' . implode(', ', $novas) . '.',
        'Aprovar ou reprovar no Portal RTM › Administração › Actualizações' . ($url !== '' ? ' (' . $url . '/reports/#actualizacoes)' : '') . '.',
    ]);
}

/** Dados da página «Actualizações» do portal. */
function naval_act_vista_portal(): array
{
    naval_servico('novidades');
    $estado = naval_act_estado();
    $aprov = naval_act_aprovacoes();
    $res = naval_act_resultados();
    $v = [
        'instalada' => ['versao' => (string) $estado['versao'], 'commit' => (string) $estado['commit'], 'aplicada_em' => (string) $estado['aplicada_em'],
            'recusada' => (string) ($estado['recusada'] ?? ''), 'origem' => (string) ($estado['origem'] ?? '')],
        'configurada' => naval_act_usa_app() || naval_config('ACTUALIZACAO_GITHUB_TOKEN') !== '',
        'credencial' => naval_act_usa_app() ? 'app' : (naval_config('ACTUALIZACAO_GITHUB_TOKEN') !== '' ? 'pessoal' : ''),
        'certificados' => naval_json_ler(naval_act_pasta() . '/certificados.json', ['verificado_em' => '', 'lista' => []]),
        'automatica' => naval_config('ACTUALIZACAO_AUTOMATICA') === '1',
        'exige_aprovacao' => naval_act_exige_aprovacao(),
        'reversao_pedida' => is_file(naval_act_pasta() . '/pedido-reversao.json'),
        'pode_reverter' => naval_act_ultima_copia() !== null,
        'ultima_execucao' => naval_act_ultima_execucao(),
        'noite' => naval_act_horas_noite(),
        // ensaio: versão, quando, resultado e falhas (sem o SHA-256, que fica só no servidor)
        'ensaio' => naval_act_ensaio_ligado() ? array_diff_key(naval_act_ensaio_estado(), ['sha256' => 1]) + ['url' => '../ensaio/reports/'] : null,
        'pendentes' => [],
        'historico' => [],
        'erro' => '',
    ];
    try {
        $versoes = naval_cache('actualizacoes-versoes', 120, function () { return naval_act_versoes(); });
    } catch (Throwable $e) {
        $v['erro'] = $e->getMessage();
        $versoes = [];
    }
    $novas = array_column(naval_act_mais_recentes($versoes, $estado), 'versao');
    $agora = time();
    $v['proximo_ciclo'] = date('H:i', naval_act_proximo_ciclo($agora));
    $v['em_curso'] = false;
    foreach ($versoes as $x) {
        $item = ['versao' => $x['versao'], 'publicada_em' => $x['publicada_em'], 'commit' => substr($x['commit'], 0, 7),
            'seccoes' => naval_nov_seccoes($x['notas']), 'notas' => mb_substr($x['notas'], 0, 4000),
            'decisao' => $aprov[$x['versao']] ?? null, 'resultado' => $res[$x['versao']] ?? null];
        // estado da instalação depois da decisão: na fila (até ao próximo ciclo da tarefa de 5 em 5 minutos),
        // a instalar (a tarefa pegou nela e ainda não há resultado) ou esta noite (aprovada sem «instalar já»)
        $item['fila'] = naval_act_fila($item['decisao'], $item['resultado'], $agora);
        if (in_array($item['fila'], ['na_fila', 'a_instalar'], true)) $v['em_curso'] = true;
        if (is_array($item['decisao'])) unset($item['decisao']['sha256'], $item['decisao']['tentada_em']);
        if (in_array($x['versao'], $novas, true)) {
            // só a mais recente se aprova: as anteriores já vêm incluídas nela
            $item['incluida'] = (bool) $v['pendentes'];
            $v['pendentes'][] = $item;
        } else $v['historico'][] = $item;
    }
    $v['historico'] = array_slice($v['historico'], 0, 15);
    // Pacote único (gestão de versões, ISO/IEC 20000-1 §8.5.3 e ITIL «release package»): a versão mais recente já
    // contém todas as anteriores por instalar, por isso há uma só decisão, uma só instalação, uma só cópia de
    // segurança e um só ponto de reposição. O cartão mostra o que muda em todas, sem repetir secções.
    if ($v['pendentes']) {
        $seccoes = [];
        $inclui = [];
        foreach ($v['pendentes'] as $p) {
            $inclui[] = ['versao' => $p['versao'], 'publicada_em' => $p['publicada_em'], 'decisao' => $p['decisao']['decisao'] ?? ''];
            foreach ($p['seccoes'] as $sec) if (!isset($seccoes[$sec['id']])) $seccoes[$sec['id']] = $sec;
        }
        $v['pendentes'][0]['pacote'] = ['versoes' => $inclui, 'seccoes' => array_values($seccoes)];
    }
    return $v;
}

/** Próximo ciclo da tarefa de 5 em 5 minutos (corre aos minutos múltiplos de 5). */
function naval_act_proximo_ciclo(int $agora): int
{
    return (int) (floor($agora / 300) + 1) * 300;
}

/** Estado da instalação de uma versão decidida: na_fila | a_instalar | esta_noite | null. */
function naval_act_fila(?array $dec, ?array $res, int $agora): ?string
{
    if (!$dec || ($dec['decisao'] ?? '') !== 'aprovada') return null;
    $em = (string) ($dec['em'] ?? '');
    $resData = (string) ($res['data'] ?? '');
    if ($resData !== '' && $resData >= $em) return null;   // já há resultado depois da decisão
    if (empty($dec['urgente'])) return 'esta_noite';
    $tentada = (string) ($dec['tentada_em'] ?? '');
    if ($tentada === '') return 'na_fila';
    return strtotime($tentada) > $agora - 900 ? 'a_instalar' : null;
}

// ── Certificados HTTPS ──────────────────────────────────────────────────────────────────────────────
//
// A renovação é automática no servidor (protocolo ACME, ex.: win-acme com a Let's Encrypt; as autoridades de
// certificação estão a encurtar a validade: 200 dias em 2026, 47 dias em 2029). O portal vigia todas as noites a
// validade de cada endereço (PORTAL_URL, ATV_URL_RESOLVEDOR e ACTUALIZACAO_CERTIFICADOS) e avisa por correio a 30, 14,
// 7, 3 e 1 dias do fim, uma vez por limite, para nunca expirar sem ninguém saber.

const NAVAL_ACT_CERT_AVISOS = [30, 14, 7, 3, 1];

/** Endereços (anfitrião:porta) cujos certificados se vigiam. */
function naval_act_enderecos_tls(?array $urls = null): array
{
    $l = [];
    $urls = $urls ?? array_merge([naval_config('PORTAL_URL'), naval_config('ATV_URL_RESOLVEDOR')], explode(',', naval_config('ACTUALIZACAO_CERTIFICADOS')));
    foreach ($urls as $u) {
        $u = trim((string) $u);
        if ($u === '') continue;
        if (strpos($u, '://') === false) $u = 'https://' . $u;
        $p = parse_url($u);
        if (!is_array($p) || empty($p['host']) || ($p['scheme'] ?? '') !== 'https') continue;
        $l[strtolower($p['host']) . ':' . (int) ($p['port'] ?? 443)] = true;
    }
    return array_keys($l);
}

/** Lê o certificado apresentado por anfitrião:porta: [dias até expirar, data de fim, emitente, nomes, erro]. */
function naval_act_ler_certificado(string $endereco): array
{
    $ctx = stream_context_create(['ssl' => ['capture_peer_cert' => true, 'verify_peer' => false, 'verify_peer_name' => false, 'SNI_enabled' => true,
        'peer_name' => explode(':', $endereco)[0]]]);
    $s = @stream_socket_client('ssl://' . $endereco, $errno, $erro, 10, STREAM_CLIENT_CONNECT, $ctx);
    if (!$s) return ['endereco' => $endereco, 'dias' => null, 'expira' => '', 'emitente' => '', 'erro' => 'sem ligação (' . trim((string) $erro) . ')'];
    $p = stream_context_get_params($s);
    fclose($s);
    $c = isset($p['options']['ssl']['peer_certificate']) ? openssl_x509_parse($p['options']['ssl']['peer_certificate']) : false;
    if (!$c) return ['endereco' => $endereco, 'dias' => null, 'expira' => '', 'emitente' => '', 'erro' => 'certificado ilegível'];
    $fim = (int) $c['validTo_time_t'];
    return ['endereco' => $endereco, 'dias' => (int) floor(($fim - time()) / 86400), 'expira' => date('Y-m-d H:i', $fim),
        'emitente' => (string) ($c['issuer']['O'] ?? $c['issuer']['CN'] ?? ''), 'erro' => ''];
}

/** Avisos a enviar agora (um por endereço, certificado e limite ainda não avisado). Actualiza $avisados. */
function naval_act_avisos_certificados(array $lista, array &$avisados): array
{
    $out = [];
    foreach ($lista as $c) {
        if ($c['dias'] === null) continue;
        foreach (NAVAL_ACT_CERT_AVISOS as $limite) {
            $chave = $c['endereco'] . '|' . $c['expira'] . '|' . $limite;
            if ($c['dias'] <= $limite && empty($avisados[$chave])) {
                $avisados[$chave] = date('Y-m-d');
                $out[] = $c['dias'] < 0
                    ? 'O certificado de ' . $c['endereco'] . ' EXPIROU em ' . $c['expira'] . '.'
                    : 'O certificado de ' . $c['endereco'] . ' expira dentro de ' . $c['dias'] . ' dia(s) (' . $c['expira'] . ', ' . $c['emitente'] . '): a renovação automática não o renovou.';
                break;   // um aviso por certificado de cada vez (o limite mais alto ainda não avisado)
            }
        }
    }
    return $out;
}

/** Vigia os certificados (tarefa nocturna): grava o estado para o portal e avisa por correio quando é preciso. */
function naval_act_vigiar_certificados(): array
{
    $lista = array_map('naval_act_ler_certificado', naval_act_enderecos_tls());
    $f = naval_act_pasta() . '/certificados.json';
    $antes = naval_json_ler($f);
    $avisados = (array) ($antes['avisados'] ?? []);
    $avisos = naval_act_avisos_certificados($lista, $avisados);
    naval_json_gravar($f, ['verificado_em' => date('Y-m-d H:i:s'), 'lista' => $lista, 'avisados' => $avisados]);
    if ($avisos) naval_act_avisar('certificado HTTPS a expirar', array_merge($avisos, ['Confirmar a tarefa de renovação automática (win-acme) no servidor.']));
    return $lista;
}

// ── Requisitos do servidor e configuração do Apache ─────────────────────────────────────────────────

/** php.ini usado pelo Apache (ACTUALIZACAO_PHP_INI; por omissão o mesmo da linha de comandos). */
function naval_act_php_ini(): string
{
    return naval_config('ACTUALIZACAO_PHP_INI', (string) php_ini_loaded_file());
}

/** Extensões carregadas pelo PHP com esse php.ini (processo novo, como o Apache as verá). */
function naval_act_modulos_php(string $ini): array
{
    $o = [];
    exec(escapeshellarg(PHP_BINARY) . ($ini !== '' ? ' -c ' . escapeshellarg($ini) : '') . ' -m 2>&1', $o);
    return array_map('strtolower', array_filter(array_map('trim', $o), function ($l) { return $l !== '' && $l[0] !== '['; }));
}

/** Activa uma extensão no texto do php.ini: descomenta «;extension=x» (ou php_x.dll) ou acrescenta «extension=x». */
function naval_act_ini_activar(string $ini, string $ext): string
{
    $padrao = '/^\s*;\s*(extension\s*=\s*"?(?:php_)?' . preg_quote($ext, '/') . '(?:\.dll|\.so)?"?\s*)$/mi';
    if (preg_match($padrao, $ini)) return (string) preg_replace($padrao, '$1', $ini, 1);
    $fim = strpos($ini, "\r\n") !== false ? "\r\n" : "\n";
    return rtrim($ini, "\r\n") . $fim . '; activada pelo actualizador do Portal RTM' . $fim . 'extension=' . $ext . $fim;
}

/**
 * Confere os requisitos da versão (ferramentas/requisitos.json do pacote) antes de alterar o servidor: versão mínima do
 * PHP (não se actualiza sozinha: pára com aviso) e extensões (activadas no php.ini, com cópia e verificação).
 * Devolve [alterou o php.ini, cópia do php.ini anterior]. ACTUALIZACAO_REQUISITOS_IGNORAR: requisitos a não conferir
 * (servidores de teste).
 */
function naval_act_requisitos(string $dirPacote, callable $diz): array
{
    $r = json_decode((string) @file_get_contents($dirPacote . '/ferramentas/requisitos.json'), true);
    if (!is_array($r)) return [false, ''];
    $minimo = (string) ($r['php_minimo'] ?? '');
    if ($minimo !== '' && version_compare(PHP_VERSION, $minimo, '<')) {
        throw new NavalActualizacaoErro('Esta versão do portal precisa do PHP ' . $minimo . ' ou superior (o servidor tem ' . PHP_VERSION . '): actualizar o PHP antes de a aprovar.');
    }
    $ignorar = array_filter(array_map('trim', explode(',', strtolower(naval_config('ACTUALIZACAO_REQUISITOS_IGNORAR')))));
    $ini = naval_act_php_ini();
    // configuração de desempenho do PHP (OPcache, cache de caminhos, memória): melhor esforço, nunca pára a actualização
    $afinado = in_array('ini', $ignorar, true) ? [false, ''] : naval_act_ini_afinar($ini, (array) ($r['ini'] ?? []), $diz);
    $carregadas = naval_act_modulos_php($ini);
    $faltam = [];
    foreach ((array) ($r['extensoes'] ?? []) as $req) {
        $req = strtolower((string) $req);
        if (in_array($req, $ignorar, true)) continue;
        $alt = array_map('trim', explode('|', $req));
        if (!array_intersect($alt, $carregadas)) $faltam[] = $alt;
    }
    if (!$faltam) return $afinado;
    if ($ini === '' || !is_file($ini) || !is_writable($ini)) {
        throw new NavalActualizacaoErro('Faltam extensões do PHP (' . implode(', ', array_map(function ($a) { return implode(' ou ', $a); }, $faltam)) . ') e o php.ini não pode ser alterado (ACTUALIZACAO_PHP_INI).');
    }
    $antes = (string) file_get_contents($ini);
    $copia = $afinado[1] !== '' ? $afinado[1] : $ini . '.antes-actualizacao-' . date('Ymd-His');
    if ($afinado[1] === '') file_put_contents($copia, $antes);
    $texto = $antes;
    foreach ($faltam as $alt) $texto = naval_act_ini_activar($texto, $alt[0]);
    file_put_contents($ini, $texto);
    $agora = naval_act_modulos_php($ini);
    $ainda = array_filter($faltam, function ($alt) use ($agora) { return !array_intersect($alt, $agora); });
    if ($ainda) {
        file_put_contents($ini, $antes);
        throw new NavalActualizacaoErro('Não foi possível activar no PHP: ' . implode(', ', array_map(function ($a) { return $a[0]; }, $ainda)) . ' (a extensão não está instalada no servidor). Nada foi alterado.');
    }
    $diz('Extensões do PHP activadas no php.ini: ' . implode(', ', array_map(function ($a) { return $a[0]; }, $faltam)) . ' (cópia: ' . basename($copia) . ').');
    return [true, $copia];
}

/** Valores efectivos de directivas do php.ini (processo novo do PHP com esse php.ini, como o Apache os verá). */
function naval_act_ini_ler(string $ini, array $chaves): ?array
{
    $f = tempnam(sys_get_temp_dir(), 'naval-ini');
    if ($f === false) return null;
    // script num ficheiro: evita aspas na linha de comandos (no Windows o escapeshellarg troca as aspas por espaços)
    file_put_contents($f, '<?php $o = []; foreach (array_slice($argv, 1) as $k) { $v = ini_get($k); $o[$k] = $v === false ? null : $v; } echo json_encode(["ok" => 1, "v" => $o, "m" => get_loaded_extensions(true)]);');
    $o = [];
    exec(escapeshellarg(PHP_BINARY) . ($ini !== '' ? ' -c ' . escapeshellarg($ini) : '') . ' ' . escapeshellarg($f) . ' ' . implode(' ', array_map('escapeshellarg', $chaves)) . ' 2>&1', $o);
    @unlink($f);
    $d = json_decode((string) end($o), true);
    return is_array($d) && !empty($d['ok']) ? ['v' => (array) $d['v'], 'zend' => array_map('strtolower', (array) ($d['m'] ?? []))] : null;
}
/** Tamanho do php.ini em bytes («256M», «4096K», «1G» ou número). */
function naval_act_ini_bytes(string $v): float
{
    $v = trim($v);
    $n = (float) $v;
    $u = strtoupper(substr($v, -1));
    return $u === 'G' ? $n * 1073741824 : ($u === 'M' ? $n * 1048576 : ($u === 'K' ? $n * 1024 : $n));
}
/** Define uma directiva no texto do php.ini (substitui a linha, mesmo comentada, ou acrescenta no fim). */
function naval_act_ini_definir(string $texto, string $chave, string $valor): string
{
    $padrao = '/^[ \t]*;?[ \t]*' . preg_quote($chave, '/') . '[ \t]*=.*$/mi';
    if (preg_match($padrao, $texto)) return (string) preg_replace($padrao, $chave . '=' . $valor, $texto, 1);
    $fim = strpos($texto, "\r\n") !== false ? "\r\n" : "\n";
    return rtrim($texto, "\r\n") . $fim . $chave . '=' . $valor . '   ; Portal RTM (desempenho)' . $fim;
}
/**
 * Afina o php.ini para muitas pessoas ao mesmo tempo (ferramentas/requisitos.json, «ini»): «>=X» só sobe o valor se
 * estiver abaixo; outro valor fica exactamente esse. Activa o OPcache (zend_extension) se faltar. Melhor esforço:
 * sem php.ini alterável, só avisa; se o PHP não arrancar com a configuração nova, repõe a anterior. Devolve
 * [alterou o php.ini, cópia do php.ini anterior].
 */
function naval_act_ini_afinar(string $ini, array $quer, callable $diz): array
{
    if (!$quer) return [false, ''];
    if ($ini === '' || !is_file($ini) || !is_writable($ini)) {
        $diz('Aviso: o php.ini não pode ser alterado (ACTUALIZACAO_PHP_INI); a configuração de desempenho do PHP fica como está.');
        return [false, ''];
    }
    $agora = naval_act_ini_ler($ini, array_keys($quer));
    if ($agora === null) return [false, ''];
    $antes = (string) file_get_contents($ini);
    $texto = $antes;
    $mudou = [];
    $opcache = in_array('zend opcache', $agora['zend'], true);
    foreach ($quer as $k => $alvo) {
        $alvo = (string) $alvo;
        $min = strpos($alvo, '>=') === 0;
        $valor = $min ? substr($alvo, 2) : $alvo;
        $actual = $agora['v'][$k] ?? null;
        $certo = $actual !== null && ($min ? naval_act_ini_bytes((string) $actual) >= naval_act_ini_bytes($valor) || (string) $actual === '-1' : (string) $actual === $valor);
        if ($certo) continue;
        $texto = naval_act_ini_definir($texto, $k, $valor);
        $mudou[] = $k . '=' . $valor;
    }
    if (!$opcache && preg_grep('/^opcache\./', array_keys($quer))) {
        $p = '/^[ \t]*;[ \t]*(zend_extension[ \t]*=[ \t]*"?(?:php_)?opcache(?:\.dll|\.so)?"?)[ \t]*$/mi';
        $texto = preg_match($p, $texto) ? (string) preg_replace($p, '$1', $texto, 1) : naval_act_ini_definir($texto, 'zend_extension', 'opcache');
        $mudou[] = 'zend_extension=opcache';
    }
    if (!$mudou) return [false, ''];
    $copia = $ini . '.antes-actualizacao-' . date('Ymd-His');
    file_put_contents($copia, $antes);
    file_put_contents($ini, $texto);
    if (naval_act_ini_ler($ini, array_keys($quer)) === null) {
        file_put_contents($ini, $antes);   // o PHP não arrancou com a configuração nova: fica a anterior
        @unlink($copia);
        $diz('Aviso: a configuração de desempenho do PHP não foi aceite; o php.ini ficou como estava.');
        return [false, ''];
    }
    $diz('Desempenho do PHP afinado no php.ini: ' . implode(', ', $mudou) . ' (cópia: ' . basename($copia) . ').');
    return [true, $copia];
}

/** Comando do Apache (httpd.exe) ou null se não for encontrado. ACTUALIZACAO_APACHE_CMD permite outro (testes). */
function naval_act_apache_cmd(): ?string
{
    $cmd = naval_config('ACTUALIZACAO_APACHE_CMD');
    if ($cmd !== '') return $cmd;
    $bin = naval_config('ACTUALIZACAO_APACHE_BIN', DIRECTORY_SEPARATOR === '\\' ? 'C:/Apache24/bin/httpd.exe' : '');
    return $bin !== '' && is_file($bin) ? escapeshellarg($bin) : null;
}

/** Valida a configuração do Apache (httpd -t). Devolve a mensagem de erro, ou vazio se estiver correcta. */
function naval_act_apache_testar(): string
{
    $cmd = naval_act_apache_cmd();
    if ($cmd === null) return '';
    $o = [];
    exec($cmd . ' -t 2>&1', $o, $r);
    return $r === 0 ? '' : trim(implode(' ', array_slice($o, -3)));
}

/** Reinicia o Apache para carregar a configuração nova e o php.ini. Devolve um aviso, ou vazio. */
function naval_act_apache_reiniciar(): string
{
    $cmd = naval_act_apache_cmd();
    if ($cmd === null) return 'O Apache não foi encontrado (ACTUALIZACAO_APACHE_BIN): reiniciar o serviço à mão para aplicar a configuração nova.';
    $servico = naval_config('ACTUALIZACAO_APACHE_SERVICO', DIRECTORY_SEPARATOR === '\\' ? 'Apache2.4' : '');
    $o = [];
    exec($cmd . ' -k restart' . ($servico !== '' ? ' -n ' . escapeshellarg($servico) : '') . ' 2>&1', $o, $r);
    sleep(5);
    return $r === 0 ? '' : 'O Apache não reiniciou: ' . trim(implode(' ', $o));
}

/** Inclui a configuração gerida pelo portal no httpd.conf (uma vez), com cópia, validação e reposição se falhar. */
function naval_act_apache_incluir(string $raiz): string
{
    $conf = naval_config('ACTUALIZACAO_APACHE_CONF', 'C:/Apache24/conf/httpd.conf');
    if (!is_file($conf) || !is_writable($conf)) throw new NavalActualizacaoErro('httpd.conf não encontrado ou sem permissão de escrita: ' . $conf . ' (ACTUALIZACAO_APACHE_CONF).');
    $linha = 'Include "' . str_replace('\\', '/', $raiz) . '/ferramentas/apache/portal-rtm.conf"';
    $antes = (string) file_get_contents($conf);
    if (stripos($antes, 'ferramentas/apache/portal-rtm.conf') !== false) return 'já incluída';
    $copia = $conf . '.antes-portal-' . date('Ymd-His');
    file_put_contents($copia, $antes);
    $fim = strpos($antes, "\r\n") !== false ? "\r\n" : "\n";
    file_put_contents($conf, rtrim($antes, "\r\n") . $fim . $fim . '# Portal RTM: configuração gerida pelo portal (actualizada com cada versão)' . $fim . $linha . $fim);
    $erro = naval_act_apache_testar();
    if ($erro !== '') {
        file_put_contents($conf, $antes);
        throw new NavalActualizacaoErro('A configuração do Apache não passou na validação e foi reposta: ' . $erro);
    }
    return 'incluída (cópia: ' . basename($copia) . ')';
}

// ── Ambiente de ensaio dentro do portal (versão nova lado a lado) ──────────────────────────────────────
//
// Práticas: instalação azul e verde (a versão nova corre ao lado da produção, sem lhe tocar, e só passa a produção
// depois de aprovada) e lançamento por anéis (anel 0: só os administradores de sistema). A tarefa de 5 em 5 minutos
// instala a versão publicada mais recente em <portal>/ensaio/, com uma cópia dos dados privados (a produção nunca lê
// o ensaio), e corre os testes de fumo (arranque, bases de dados e HTTP). A aprovação, manual ou automática às 02:45,
// só é aceite para a versão que passou no ensaio, com o mesmo SHA-256. ENSAIO=0 desliga o ensaio (e a exigência).

/** Pastas da pasta privada que não vão para a cópia do ensaio (sessões, cache, limites, registos e o próprio actualizador). */
const NAVAL_ACT_ENSAIO_SEM_COPIA = ['ensaio', 'sessoes', 'cache', 'limites', 'registos', 'actualizacoes', 'copias-dados', 'conformidade'];

function naval_act_ensaio_ligado(): bool
{
    return naval_config('ENSAIO', '1') !== '0' && naval_act_exige_aprovacao();
}

/** Estado do ensaio: versão, SHA-256, quando, resultado (ok | falhou) e falhas. */
function naval_act_ensaio_estado(): array
{
    return naval_json_ler(naval_act_pasta() . '/ensaio.json', ['versao' => '', 'sha256' => '', 'instalada_em' => '', 'resultado' => '', 'falhas' => []]);
}

/** Copia uma pasta (recursiva), sem as subpastas de topo indicadas. */
function naval_act_copiar_pasta(string $de, string $para, array $semTopo = []): int
{
    $n = 0;
    if (!is_dir($de)) return $n;
    if (!is_dir($para) && !@mkdir($para, 0770, true) && !is_dir($para)) throw new NavalActualizacaoErro('Não foi possível criar ' . $para);
    foreach (scandir($de) ?: [] as $x) {
        if ($x === '.' || $x === '..' || in_array($x, $semTopo, true)) continue;
        $o = $de . '/' . $x;
        if (is_dir($o)) $n += naval_act_copiar_pasta($o, $para . '/' . $x);
        elseif (is_file($o) && @copy($o, $para . '/' . $x)) $n++;
    }
    return $n;
}

/**
 * Instala no ensaio a versão publicada mais recente ainda não instalada na produção, se ainda não lá estiver. Uma falha
 * da instalação (pasta, rede, ficheiros em uso) repete-se sozinha até 3 vezes, de 5 em 5 minutos; uma falha nos testes
 * de fumo não se repete (a versão tem um defeito). «Repetir o ensaio» (Actualizações) recomeça. Devolve o resultado,
 * ou null sem nada a fazer.
 * $o: versoes (testes), raiz (testes), sem_http, escrever.
 */
function naval_act_ensaio_ciclo(array $o = []): ?array
{
    if (!naval_act_ensaio_ligado()) return null;
    naval_act_ensaio_limpar($o['raiz'] ?? naval_raiz());
    $versoes = $o['versoes'] ?? naval_act_versoes();
    $novas = naval_act_mais_recentes($versoes, naval_act_estado());
    if (!$novas) return null;
    $v = $novas[0];
    $e = naval_act_ensaio_estado();
    if ($e['versao'] === $v['versao'] && $e['resultado'] !== '') {
        $tentativas = (int) ($e['tentativas'] ?? 1);
        if ($e['resultado'] === 'ok' || !empty($e['testes']) || $tentativas >= 3) return null;
    }
    $r = naval_act_ensaio_instalar($v, $o);
    // conta as tentativas da mesma versão (a seguinte só repete se ainda não chegou às 3)
    if (($r['resultado'] ?? '') !== 'ocupado') {
        $r['tentativas'] = $e['versao'] === $v['versao'] ? (int) ($e['tentativas'] ?? 1) + 1 : 1;
        naval_json_gravar(naval_act_pasta() . '/ensaio.json', $r);
    }
    return $r;
}

/** Descarrega, confere e instala a versão $v em <raiz>/ensaio, copia os dados privados e corre os testes de fumo. */
function naval_act_ensaio_instalar(array $v, array $o = []): array
{
    $diz = $o['escrever'] ?? function ($s) { echo $s, "\n"; };
    $raiz = rtrim(str_replace('\\', '/', (string) ($o['raiz'] ?? naval_raiz())), '/');
    $bloqueio = fopen(naval_act_pasta() . '/.bloqueio-ensaio', 'c');
    if (!$bloqueio || !flock($bloqueio, LOCK_EX | LOCK_NB)) return ['resultado' => 'ocupado', 'versao' => $v['versao']];
    $t0 = microtime(true);
    $mascarados = null;
    // $testes: a falha veio dos testes de fumo (defeito da versão, não se repete) e não da instalação
    $gravar = function (string $resultado, array $falhas, string $sha, bool $testes = false) use ($v, $t0, &$mascarados) {
        $e = ['versao' => $v['versao'], 'sha256' => $sha, 'instalada_em' => date('Y-m-d H:i:s'), 'resultado' => $resultado, 'falhas' => array_slice($falhas, 0, 20),
            'seg' => round(microtime(true) - $t0, 1), 'mascarados' => $mascarados, 'testes' => $testes];
        naval_json_gravar(naval_act_pasta() . '/ensaio.json', $e);
        naval_act_registar($resultado === 'ok' ? 'ensaio_instalado' : 'ensaio_falhou', ['versao' => $v['versao'], 'falhas' => $e['falhas'], 'seg' => $e['seg']]);
        return $e;
    };
    try {
        // 1. Pacote: o mesmo que a produção vai instalar (SHA-256 publicado)
        $zip = naval_act_pasta('transferencias') . '/ensaio.zip';
        $diz('Ensaio: a descarregar a versão ' . $v['versao'] . '…');
        naval_act_descarregar($v['zip'], $zip);
        naval_act_descarregar($v['sha'], $zip . '.sha256');
        $sha = strtolower(substr(trim((string) file_get_contents($zip . '.sha256')), 0, 64));
        if (!hash_equals($sha, (string) hash_file('sha256', $zip))) return $gravar('falhou', ['O SHA-256 do pacote não confere.'], $sha);
        $dir = naval_act_pasta('pacotes') . '/ensaio-' . date('Ymd-His');
        naval_act_extrair($zip, $dir);
        $m = naval_act_manifesto($dir);
        $erros = naval_act_sintaxe($dir . '/ficheiros', array_keys($m['ficheiros']));
        if ($erros) {
            naval_act_limpar_pasta($dir);
            return $gravar('falhou', array_merge(['Erros de sintaxe no pacote:'], $erros), $sha);
        }

        // 2. Código lado a lado: pasta nova completa e troca de uma só vez (a produção não é tocada)
        $alvo = $raiz . '/ensaio';
        $novo = $alvo . '.novo';
        naval_act_limpar_pasta($novo);
        foreach (array_keys($m['ficheiros']) as $c) naval_act_escrever($dir . '/ficheiros/' . $c, $novo . '/' . $c);
        file_put_contents($novo . '/.ambiente-ensaio', (string) json_encode(['versao' => $m['versao'], 'sha256' => $sha, 'instalada_em' => date('Y-m-d H:i:s')]));
        naval_act_limpar_pasta($alvo . '.antigo');
        if (is_dir($alvo . '.antigo')) throw new NavalActualizacaoErro('Não foi possível apagar a pasta ' . basename($alvo) . '.antigo (ficheiro em uso?).');
        $esperar = $o['esperar'] ?? null;
        if (is_dir($alvo)) naval_act_renomear_pasta($alvo, $alvo . '.antigo', $esperar);
        elseif (file_exists($alvo)) throw new NavalActualizacaoErro('Existe um ficheiro com o nome da pasta do ensaio: ' . $alvo);
        naval_act_renomear_pasta($novo, $alvo, $esperar);
        naval_act_limpar_pasta($alvo . '.antigo');
        naval_act_limpar_pasta($dir);

        // 3. Dados privados próprios: cópia dos da produção (contas, matriz, planos, equipamentos da app…)
        $priv = naval_pasta_privada();
        naval_act_limpar_pasta($priv . '/ensaio');
        $n = naval_act_copiar_pasta($priv, $priv . '/ensaio', NAVAL_ACT_ENSAIO_SEM_COPIA);
        // dados de teste protegidos (ISO/IEC 27001, 8.33): NIF, telefones, e-mails e moradas mascarados na cópia
        naval_servico('conformidade');
        $mascarados = naval_conf_mascarar_pasta($priv . '/ensaio');
        $diz('Ensaio: ' . count($m['ficheiros']) . ' ficheiros instalados, ' . $n . ' ficheiros de dados copiados e ' . $mascarados . ' dados pessoais mascarados.');

        // 4. Testes de fumo: arranque e bases de dados (linha de comandos) e páginas e API por HTTP
        $falhas = naval_act_verificar_cli($alvo);
        $base = empty($o['sem_http']) ? naval_act_url_portal() : '';
        if ($base !== '') $falhas = array_merge($falhas, naval_act_verificar_http($base . '/ensaio'));
        $e = $gravar($falhas ? 'falhou' : 'ok', $falhas, $sha, (bool) $falhas);
        $diz($falhas ? 'Ensaio: FALHA nos testes de fumo: ' . implode('; ', $falhas) : 'Ensaio: testes de fumo passaram.');
        naval_act_avisar($falhas ? 'versão ' . $v['versao'] . ' falhou no ensaio' : 'versão ' . $v['versao'] . ' pronta a testar no ensaio', $falhas
            ? array_merge(['A versão ' . $v['versao'] . ' foi instalada no ensaio mas não passou nos testes de fumo; não pode ser aprovada:'], $falhas)
            : ['A versão ' . $v['versao'] . ' está no ensaio e passou nos testes de fumo.', 'Teste-a em Portal RTM › Administração › Actualizações › «Abrir a versão nova (ensaio)» e aprove ou reprove.']);
        return $e;
    } catch (Throwable $ex) {
        return $gravar('falhou', [$ex->getMessage()], (string) ($sha ?? ''));
    } finally {
        flock($bloqueio, LOCK_UN);
        fclose($bloqueio);
    }
}

/** «Repetir o ensaio» (super administrador): a versão volta a instalar-se no ensaio na próxima volta (até 5 minutos). */
function naval_act_ensaio_repetir(string $login): void
{
    $e = naval_act_ensaio_estado();
    if ($e['versao'] === '' || $e['resultado'] === 'ok') throw new NavalErro('Não há nenhuma instalação falhada no ensaio para repetir.', 409);
    naval_json_gravar(naval_act_pasta() . '/ensaio.json', ['versao' => $e['versao'], 'sha256' => '', 'instalada_em' => '', 'resultado' => '', 'falhas' => [], 'tentativas' => 0]);
    naval_act_registar('ensaio_repetir', ['versao' => $e['versao'], 'por' => $login]);
    naval_registar('actualizacao_ensaio_repetir', ['versao' => $e['versao']], $login);
}

/**
 * Depois de a versão do ensaio chegar à produção, o ensaio já não é preciso: apaga-se o código e a cópia dos dados
 * (ISO/IEC 27001, 8.33: os dados de teste apagam-se quando o teste termina). Fica o registo.
 */
function naval_act_ensaio_limpar(string $raiz): bool
{
    $e = naval_act_ensaio_estado();
    $instalada = (string) (naval_act_estado()['versao'] ?? '');
    $alvo = rtrim(str_replace('\\', '/', $raiz), '/') . '/ensaio';
    if ($e['versao'] === '' || $e['versao'] !== $instalada || (!is_dir($alvo) && !is_dir(naval_pasta_privada() . '/ensaio'))) return false;
    naval_act_limpar_pasta($alvo);
    naval_act_limpar_pasta(naval_pasta_privada() . '/ensaio');
    naval_json_gravar(naval_act_pasta() . '/ensaio.json', $e + ['removido_em' => date('Y-m-d H:i:s')]);
    naval_act_registar('ensaio_removido', ['versao' => $e['versao']]);
    return true;
}

/**
 * Pode aprovar-se esta versão? Com o ensaio ligado: só a que está no ensaio, passou nos testes de fumo e tem o mesmo
 * SHA-256 publicado. Devolve o motivo da recusa, ou '' (pode).
 */
function naval_act_ensaio_bloqueio(string $versao, string $shaPublicado): string
{
    if (!naval_act_ensaio_ligado()) return '';
    $e = naval_act_ensaio_estado();
    if ($e['versao'] !== $versao) return 'A versão ' . $versao . ' ainda não está no ensaio: instala-se sozinha nos próximos 5 minutos. Teste-a no ensaio antes de a aprovar.';
    if ($e['resultado'] !== 'ok') return 'A versão ' . $versao . ' falhou nos testes do ensaio: ' . implode('; ', (array) $e['falhas']);
    if (!hash_equals((string) $e['sha256'], $shaPublicado)) return 'O pacote da versão ' . $versao . ' mudou depois de instalado no ensaio: aguarde a nova instalação no ensaio.';
    return '';
}
