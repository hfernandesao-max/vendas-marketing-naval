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
        'README.md', 'CONTRIBUTING.md', 'CHANGELOG.md'];
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
function naval_act_http(string $url, bool $comToken, string $aceitar = 'application/vnd.github+json', ?string $destino = null): array
{
    if (!function_exists('curl_init')) throw new NavalActualizacaoErro('O PHP do servidor não tem a extensão curl (php.ini: extension=curl).');
    $cab = ['Accept: ' . $aceitar, 'User-Agent: Portal-RTM-Actualizador', 'X-GitHub-Api-Version: 2022-11-28'];
    if ($comToken) {
        $token = naval_config('ACTUALIZACAO_GITHUB_TOKEN');
        if ($token === '') throw new NavalActualizacaoErro('Falta ACTUALIZACAO_GITHUB_TOKEN no .env (php ferramentas/actualizar.php configurar).');
        $cab[] = 'Authorization: Bearer ' . $token;
    }
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
    curl_setopt_array($ch, $op);
    $corpo = curl_exec($ch);
    $codigo = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $errno = curl_errno($ch);
    $erro = curl_error($ch);
    curl_close($ch);
    if ($fh) fclose($fh);
    if ($errno !== 0) {
        if ($errno === 60 || $errno === 77) {
            throw new NavalActualizacaoErro('Certificado HTTPS não verificado (' . $erro . '). No php.ini, indicar curl.cainfo e openssl.cafile com o ficheiro cacert.pem (https://curl.se/ca/cacert.pem).');
        }
        throw new NavalActualizacaoErro('Sem ligação a ' . (string) parse_url($url, PHP_URL_HOST) . ' (' . $erro . '). Confirmar o acesso do servidor à Internet por HTTPS.');
    }
    return [$codigo, is_string($corpo) ? $corpo : '', $cabecalhos];
}

function naval_act_api(string $caminho): array
{
    $base = rtrim(naval_config('ACTUALIZACAO_GITHUB_API', 'https://api.github.com'), '/');
    $repo = naval_config('ACTUALIZACAO_REPOSITORIO', NAVAL_ACT_REPOSITORIO);
    [$codigo, $corpo, $cab] = naval_act_http($base . '/repos/' . $repo . '/' . ltrim($caminho, '/'), true);
    if ($codigo === 401) throw new NavalActualizacaoErro('O GitHub recusou o token (expirado ou revogado): criar outro e correr «php ferramentas/actualizar.php configurar».');
    if ($codigo === 403 || $codigo === 404) throw new NavalActualizacaoErro('O token não dá acesso ao repositório ' . $repo . ' (HTTP ' . $codigo . '): confirmar «Contents: Read-only» nesse repositório.');
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

/** Versão mais recente publicada: [versao, commit, url do zip, url do sha256, dias do token]. */
function naval_act_ultima_versao(): array
{
    [$r, $cab] = naval_act_api('releases/latest');
    $zip = $sha = null;
    foreach ($r['assets'] ?? [] as $a) {
        $n = (string) ($a['name'] ?? '');
        if (preg_match('/^portal-rtm-[\w.\-]+\.zip$/', $n)) $zip = (string) $a['url'];
        if (preg_match('/^portal-rtm-[\w.\-]+\.zip\.sha256$/', $n)) $sha = (string) $a['url'];
    }
    if (!$zip || !$sha) throw new NavalActualizacaoErro('A versão ' . ($r['tag_name'] ?? '?') . ' não tem o pacote e o SHA-256 publicados.');
    return ['versao' => (string) ($r['tag_name'] ?? ''), 'commit' => (string) ($r['target_commitish'] ?? ''), 'zip' => $zip, 'sha' => $sha,
        'dias_token' => naval_act_dias_token($cab), 'publicada_em' => (string) ($r['published_at'] ?? '')];
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
            $u = naval_act_ultima_versao();
            if ($u['dias_token'] !== null && $u['dias_token'] <= 30) {
                $avisos[] = 'O token do GitHub expira dentro de ' . $u['dias_token'] . ' dia(s): criar outro e correr «php ferramentas/actualizar.php configurar».';
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
        if ($p['conflitos'] && !$o['forcar']) {
            naval_act_registar('conflito', ['versao' => $m['versao'], 'ficheiros' => $p['conflitos']]);
            return ['resultado' => 'conflito', 'versao' => $m['versao'], 'plano' => $p, 'avisos' => $avisos];
        }
        $mudar = array_merge($p['alterar'], $p['conflitos'], $p['novos']);

        // 4. Sintaxe do PHP novo (antes de mexer no servidor)
        $erros = naval_act_sintaxe($dir . '/ficheiros', $mudar);
        if ($erros) throw new NavalActualizacaoErro('Erros de sintaxe no pacote (nada foi alterado): ' . implode('; ', $erros));

        // 5. Migrações da base de dados (compatíveis com o código em produção: vão antes dos ficheiros)
        $aplicadas = $migracoes ? naval_act_migrar($dir . '/ficheiros/ferramentas/sql/migracoes', false) : [];

        $ficheirosNovos = $m['ficheiros'];
        $concluir = function (string $resultado, array $extra = []) use (&$estado, $m, $ficheirosNovos, $origem, $t0, $avisos) {
            $estado = ['versao' => $m['versao'], 'commit' => (string) ($m['commit'] ?? ''), 'aplicada_em' => date('Y-m-d H:i:s'), 'origem' => $origem,
                'ficheiros' => array_merge($estado['ficheiros'] ?? [], $ficheirosNovos)];
            naval_act_gravar_estado($estado);
            naval_act_registar($resultado, ['versao' => $m['versao'], 'seg' => round(microtime(true) - $t0, 1)] + $extra);
            return ['resultado' => $resultado, 'versao' => $m['versao'], 'avisos' => $avisos] + $extra;
        };
        if (!$mudar) {
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
        $aviso = naval_act_recarregar_php();
        if ($aviso !== '') {
            $avisos[] = $aviso;
            $diz('Aviso: ' . $aviso);
        }

        // 7. Confirmar que o portal funciona; senão, repor
        $falhas = naval_act_verificar_cli($raiz);
        if ($base !== '' && !$httpAntes) $falhas = array_merge($falhas, naval_act_verificar_http($base));
        if ($falhas) {
            $erros = naval_act_repor($raiz, $copia);
            naval_act_recarregar_php();
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
    naval_act_limpar_pasta($copia);
    naval_act_registar('reversao_manual', ['para' => $e['versao'], 'erros' => $erros]);
    return ['versao' => $e['versao'], 'erros' => $erros];
}
