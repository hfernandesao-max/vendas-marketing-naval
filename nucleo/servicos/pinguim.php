<?php
/**
 * Pinguim BO (backoffice do RTM): ligação à exportação REST (XML).
 *
 * .env (obrigatórias, sem valores predefinidos no código): USERNAME, PASSWORD e PINGUIM_URL (endereços por
 * ordem de tentativa, separados por vírgula). PINGUIM_VERIFICAR_SSL=0 desliga a verificação do certificado
 * HTTPS (só para um certificado interno sem cadeia válida; por omissão a verificação está ligada).
 */
defined('NAVAL_NUCLEO') || exit;
require_once dirname(__DIR__) . '/cliente-http.php';

const NAVAL_PINGUIM_CHAVES = ['USERNAME', 'PASSWORD', 'PINGUIM_URL'];

function naval_pinguim_config(): array
{
    return [
        'utilizador' => naval_config('USERNAME'),
        'senha' => naval_config('PASSWORD'),
        'enderecos' => array_values(array_filter(array_map(function ($u) { return rtrim(trim($u), '?'); }, explode(',', naval_config('PINGUIM_URL'))), function ($u) {
            return in_array(strtolower((string) parse_url($u, PHP_URL_SCHEME)), ['http', 'https'], true);
        })),
        'verificar_ssl' => naval_config('PINGUIM_VERIFICAR_SSL', '1') !== '0',
    ];
}

function naval_pinguim_configurado(): bool
{
    $c = naval_pinguim_config();
    return $c['utilizador'] !== '' && $c['senha'] !== '' && $c['enderecos'];
}

/** Tenta cada endereço até um responder; devolve a resposta do primeiro (com as falhas anteriores em «tentativas»). */
function naval_pinguim_pedido(string $consulta, int $tempo = 150): array
{
    $c = naval_pinguim_config();
    $tentativas = [];
    $r = ['corpo' => false, 'codigo' => 0, 'errno' => -1, 'erro' => 'Sem endereços do Pinguim BO.', 'seg' => 0, 'url' => ''];
    foreach ($c['enderecos'] as $i => $base) {
        $r = naval_http_pedido('GET', $base . '?' . $consulta, [
            'utilizador' => $c['utilizador'], 'senha' => $c['senha'], 'tempo' => $tempo,
            'ligacao' => $i < count($c['enderecos']) - 1 ? 5 : 15, 'verificar_ssl' => $c['verificar_ssl'],
        ]);
        if ($r['errno'] === 0) break;
        $tentativas[] = (string) parse_url($base, PHP_URL_HOST) . ': ' . $r['erro'];
    }
    $r['tentativas'] = $tentativas;
    return $r;
}

/** Requisitos do PHP para falar com o Pinguim BO; lança NavalErro se faltar algum. */
function naval_pinguim_requisitos(): void
{
    if (!function_exists('curl_init') && !filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
        throw new NavalErro('O PHP do servidor não consegue fazer pedidos HTTP.', 500, ['detalhe' => 'Active "extension=curl" ou "allow_url_fopen=On" no php.ini e reinicie o Apache.']);
    }
    if (!function_exists('simplexml_load_string')) {
        throw new NavalErro('A extensão SimpleXML do PHP não está activa no servidor.', 500, ['detalhe' => 'Active "extension=simplexml" no php.ini e reinicie o Apache.']);
    }
}

/** Exportação do período (datas AAAAMMDD) como XML; lança NavalErro (502) se o Pinguim BO falhar. */
function naval_pinguim_xml(string $inicio, string $fim, int $tempo = 150, int &$bytes = 0): SimpleXMLElement
{
    if (!naval_pinguim_configurado()) {
        throw new NavalErro('Faltam as credenciais do Pinguim BO.', 500, ['detalhe' => is_file(naval_ficheiro_env()) ? 'Chaves em falta no .env: ' . implode(', ', naval_config_em_falta(NAVAL_PINGUIM_CHAVES)) . '.' : 'Ficheiro .env não encontrado na raiz do site.']);
    }
    $r = naval_pinguim_pedido('start_date=' . rawurlencode($inicio) . '&end_date=' . rawurlencode($fim), $tempo);
    if ($r['errno'] !== 0) {
        error_log('[pinguim] ' . implode(' | ', $r['tentativas']));
        throw new NavalErro('Não foi possível contactar o Pinguim BO.', 502, naval_detalhe(implode(' | ', $r['tentativas'])));
    }
    if ($r['codigo'] === 401 || $r['codigo'] === 403) throw new NavalErro('O Pinguim BO recusou as credenciais do .env.', 502, ['detalhe' => 'HTTP ' . $r['codigo']]);
    if ($r['codigo'] !== 200 || !$r['corpo']) throw new NavalErro('O Pinguim BO respondeu com erro.', 502, ['detalhe' => 'HTTP ' . $r['codigo']]);
    $bytes = strlen((string) $r['corpo']);
    libxml_use_internal_errors(true);
    $xml = simplexml_load_string((string) $r['corpo'], 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_PARSEHUGE | LIBXML_NONET);
    if (!$xml) {
        $e = libxml_get_errors();
        error_log('[pinguim] XML inválido: ' . ($e ? trim($e[0]->message) : 'resposta vazia'));
        throw new NavalErro('A resposta do Pinguim BO não é XML válido.', 502, naval_detalhe($e ? trim($e[0]->message) : ''));
    }
    return $xml;
}

/** Estado da ligação (indicador do portal): pedido curto, sem devolver dados. */
function naval_pinguim_estado(): array
{
    if (!naval_pinguim_configurado()) return ['ok' => false, 'erro' => 'Configuração do Pinguim BO incompleta no .env (' . implode(', ', naval_config_em_falta(NAVAL_PINGUIM_CHAVES)) . ').'];
    $hoje = date('Ymd');
    $t = naval_pinguim_pedido("start_date=$hoje&end_date=$hoje", 20);
    $ok = $t['errno'] === 0 && $t['codigo'] === 200;
    return [
        'ok' => $ok,
        'segundos' => $t['seg'],
        // endereço interno só para quem opera a plataforma (SADM, TI) ou em modo de diagnóstico
        'origem' => $ok ? (naval_detalhe(parse_url($t['url'], PHP_URL_HOST) . ':' . parse_url($t['url'], PHP_URL_PORT))['detalhe'] ?? 'Pinguim BO') : null,
        'erro' => $ok ? null : ($t['errno'] !== 0 ? 'Sem ligação ao Pinguim BO' : 'Pinguim BO respondeu HTTP ' . $t['codigo']),
        'hora' => date('Y-m-d H:i:s'),
    ];
}

/** Diagnóstico do servidor e da ligação (TI / super administrador). */
function naval_pinguim_diagnostico(): array
{
    $c = naval_pinguim_config();
    $hoje = date('Ymd');
    $t = naval_pinguim_pedido("start_date=$hoje&end_date=$hoje", 60);
    return [
        'php' => PHP_VERSION,
        'curl' => function_exists('curl_version') ? (curl_version()['version'] ?? true) : false,
        'cliente_http' => function_exists('curl_init') ? 'cURL' : 'nativo do PHP (sem cURL)',
        'allow_url_fopen' => ini_get('allow_url_fopen'),
        'openssl' => extension_loaded('openssl'),
        'php_ini' => php_ini_loaded_file(),
        'simplexml' => function_exists('simplexml_load_string'),
        'env_encontrado' => is_file(naval_ficheiro_env()),
        'credenciais' => naval_pinguim_configurado(),
        'max_execution_time' => ini_get('max_execution_time'),
        'memory_limit' => ini_get('memory_limit'),
        'pinguim_urls' => $c['enderecos'],
        'pinguim_usado' => $t['errno'] === 0 ? $t['url'] : null,
        'pinguim_falhas' => $t['tentativas'],
        'pinguim_http' => $t['codigo'],
        'pinguim_erro' => $t['erro'],
        'pinguim_segundos' => $t['seg'],
        'pinguim_bytes' => is_string($t['corpo']) ? strlen($t['corpo']) : 0,
    ];
}

/**
 * Estrutura da exportação (super administrador): secções, tipos de documento e campos, com um
 * exemplo curto de cada valor e sem dados pessoais. Serve para encontrar dados ainda não usados.
 */
function naval_pinguim_estrutura(SimpleXMLElement $x, int $bytes): array
{
    $descrever = function (SimpleXMLElement $e, int $nivel) use (&$descrever) {
        $out = [];
        foreach ($e->children() as $nome => $f) {
            if (isset($out[$nome])) { if (is_array($out[$nome]) && isset($out[$nome]['_repete'])) $out[$nome]['_repete']++; continue; }
            if ($f->count() && $nivel < 3) $out[$nome] = $descrever($f, $nivel + 1) + ['_repete' => 1];
            else $out[$nome] = preg_replace('/^(.{0,40}).*$/us', '$1', trim((string) $f));
        }
        return $out;
    };
    $seccoes = [];
    foreach ($x->children() as $nome => $sec) {
        $n = $sec->count();
        $info = ['elementos' => $n];
        if ($n && !in_array($nome, ['entities', 'transactions'], true)) {   // clientes: sem dados; documentos: ver tipos_de_documento
            $exemplo = null;
            foreach ($sec->children() as $c) { $exemplo = $c; break; }
            $info['exemplo'] = $exemplo ? $descrever($exemplo, 1) : null;
        }
        $seccoes[$nome] = $info;
    }
    $tipos = [];
    if (isset($x->transactions)) {
        foreach ($x->transactions->transaction as $tx) {
            $tp = (string) $tx->document_type;
            if (!isset($tipos[$tp])) $tipos[$tp] = ['documentos' => 0, 'exemplo' => $descrever($tx, 1)];
            $tipos[$tp]['documentos']++;
        }
    }
    foreach ($tipos as &$tp) {   // sem dados pessoais nos exemplos
        foreach (['tax_number', 'entity_code'] as $k) if (isset($tp['exemplo'][$k])) $tp['exemplo'][$k] = '…';
    }
    unset($tp);
    return ['bytes' => $bytes, 'seccoes' => $seccoes, 'tipos_de_documento' => $tipos];
}
