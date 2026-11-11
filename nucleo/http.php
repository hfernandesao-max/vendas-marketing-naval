<?php
/**
 * Camada HTTP dos serviços JSON: respostas, leitura do pedido, protecção CSRF e erros.
 *
 * Os serviços de negócio não escrevem respostas: lançam NavalErro com a mensagem para o utilizador
 * e o código HTTP; o tratador instalado por naval_api_iniciar() converte-o em JSON.
 */
defined('NAVAL_NUCLEO') || exit;

/** Erro previsto, com mensagem para o utilizador e código HTTP. $extra junta campos à resposta. */
class NavalErro extends RuntimeException
{
    /** @var int */
    private $estado;
    /** @var array<string,mixed> */
    private $extra;

    public function __construct(string $mensagem, int $estado = 400, array $extra = [])
    {
        parent::__construct($mensagem);
        $this->estado = $estado;
        $this->extra = $extra;
    }

    public function estado(): int
    {
        return $this->estado;
    }

    /** @return array<string,mixed> */
    public function extra(): array
    {
        return $this->extra;
    }
}

/** Responde em JSON e termina o pedido. */
function naval_json(array $dados, int $estado = 200): void
{
    while (ob_get_level() > 0) ob_end_clean();
    if (!headers_sent()) {
        http_response_code($estado);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }
    $flags = JSON_UNESCAPED_UNICODE | (defined('JSON_INVALID_UTF8_SUBSTITUTE') ? (int) constant('JSON_INVALID_UTF8_SUBSTITUTE') : 0);   // constante do PHP 7.2+
    $json = json_encode($dados, $flags);
    if ($json === false) {
        if (!headers_sent()) http_response_code(500);
        $json = json_encode(['ok' => false, 'erro' => 'Não foi possível converter os dados para JSON.', 'detalhe' => json_last_error_msg()]);
        $estado = 500;
    }
    $json = (string) $json;
    if ($estado === 200 && defined('NAVAL_API')) naval_json_optimizar($json);
    echo $json;
    exit;
}

/**
 * Respostas da API (GET 200): validação por ETag (304 sem corpo quando o navegador já tem a mesma versão;
 * Cache-Control «private, no-cache» obriga a confirmar sempre com o servidor) e compressão gzip das respostas
 * grandes. As respostas com segredos (token, código CSRF) ficam «no-store» e sem compressão (BREACH).
 */
function naval_json_optimizar(string &$json): void
{
    if (headers_sent() || !empty($GLOBALS['naval_api_sensivel']) || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') return;
    // ETag antecipado (naval_etag_antecipado), quando o serviço o calculou; senão, o resumo do próprio corpo
    $etag = (string) ($GLOBALS['naval_etag'] ?? ('W/"' . substr(hash('sha256', $json), 0, 32) . '"'));
    naval_cabecalhos_validacao($etag);
    if (naval_etag_pedido($etag)) {
        http_response_code(304);
        header_remove('Content-Type');
        $json = '';
        return;
    }
    $aceita = stripos((string) ($_SERVER['HTTP_ACCEPT_ENCODING'] ?? ''), 'gzip') !== false;
    if ($aceita && strlen($json) > 2048 && function_exists('gzencode') && !ini_get('zlib.output_compression')) {
        $gz = gzencode($json, 5);
        if (is_string($gz)) {
            header('Content-Encoding: gzip');
            header('Content-Length: ' . strlen($gz));
            $json = $gz;
        }
    }
}

/** Cabeçalhos de validação das respostas da API: confirmar sempre com o servidor (304 quando não mudou). */
function naval_cabecalhos_validacao(string $etag): void
{
    header('Cache-Control: private, no-cache');
    header_remove('Pragma');
    header_remove('Expires');
    header('ETag: ' . $etag);
    header('Vary: Authorization, Accept-Encoding');
}

/** O navegador já tem esta versão (If-None-Match com este ETag, ou «*»)? */
function naval_etag_pedido(string $etag): bool
{
    $pedido = array_map('trim', explode(',', (string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')));
    return in_array($etag, $pedido, true) || in_array('*', $pedido, true);
}

/**
 * Pedido condicional resolvido cedo (RFC 9110, 13.1.2): o ETag calcula-se a partir de metadados baratos da origem
 * dos dados (data e tamanho dos ficheiros, entradas da cache) e do âmbito de quem pede, ANTES de ler, filtrar e
 * converter os dados. Se o navegador já tem esta versão, responde 304 sem corpo e termina; senão, guarda o ETag
 * para a resposta completa (naval_json_optimizar), para que o pedido seguinte possa acabar logo aqui.
 *
 * O âmbito entra sempre no ETag (conta, perfil, rotas, NIF e níveis da matriz): pessoas ou perfis diferentes no
 * mesmo navegador nunca partilham uma versão. Entram também a rota, os parâmetros do pedido e a data dos ficheiros
 * PHP que montam a resposta (uma actualização do portal muda o ETag). $origem: metadados da origem dos dados;
 * null = sem metadados fiáveis (não valida cedo; a resposta leva o ETag do corpo).
 */
function naval_etag_antecipado(?array $origem, array $codigo = []): void
{
    unset($GLOBALS['naval_etag']);
    if ($origem === null || headers_sent() || !empty($GLOBALS['naval_api_sensivel']) || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') return;
    $u = naval_utilizador();
    $niveis = [];
    foreach (array_keys(naval_matriz()) as $rec) $niveis[$rec] = naval_nivel($rec);
    $q = $_GET;
    ksort($q);
    $ficheiros = [__FILE__];
    foreach ($codigo as $f) $ficheiros[] = $f;
    $versao = [];
    foreach ($ficheiros as $f) $versao[] = @filemtime($f) . ':' . @filesize($f);
    $chave = [
        'rota' => (string) ($GLOBALS['naval_api_rota'] ?? ''),
        'q' => $q,
        'conta' => [$u['login'] ?? '', $u['perfil'] ?? '', naval_rotas(), naval_ve_nif(), $niveis],
        'codigo' => $versao,
        'origem' => $origem,
    ];
    $etag = 'W/"a' . substr(hash('sha256', (string) json_encode($chave)), 0, 31) . '"';
    $GLOBALS['naval_etag'] = $etag;
    if (!naval_etag_pedido($etag)) return;
    while (ob_get_level() > 0) ob_end_clean();
    http_response_code(304);
    naval_cabecalhos_validacao($etag);
    exit;
}

/** Metadados (data e tamanho) de ficheiros, para naval_etag_antecipado; ficheiro em falta = «-». */
function naval_meta_ficheiros(array $ficheiros): array
{
    $out = [];
    foreach ($ficheiros as $f) {
        $st = is_string($f) && $f !== '' ? @stat($f) : false;
        $out[] = basename((string) $f) . '|' . ($st ? $st['mtime'] . '|' . $st['size'] : '-');
    }
    return $out;
}

/** Envia um ficheiro guardado (Excel) e termina o pedido. */
function naval_enviar_ficheiro(string $caminho, string $nome, string $tipo): void
{
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: ' . $tipo);
    header('Cache-Control: no-store');
    if ($nome !== '') header('Content-Disposition: attachment; filename="' . str_replace('"', '', $nome) . '"');
    readfile($caminho);
    exit;
}

const NAVAL_TIPO_XLSX = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

function naval_e_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

/** Corpo JSON do pedido (vazio se não for JSON). */
function naval_pedido(): array
{
    static $q = null;
    if ($q === null) {
        // leitura limitada (também quando o pedido não indica o tamanho): nunca mais do que o máximo da rota
        $max = (int) ($GLOBALS['naval_corpo_max'] ?? 0);
        $corpo = $max > 0 ? (string) stream_get_contents(fopen('php://input', 'rb'), $max + 1) : (string) file_get_contents('php://input');
        if ($max > 0 && strlen($corpo) > $max) throw new NavalErro('Pedido demasiado grande.', 413);
        $q = json_decode($corpo, true);
        if (!is_array($q)) $q = [];
    }
    return $q;
}

const NAVAL_PERIODO_MAX_DIAS = 400;   // um ano e um mês (comparações homólogas); evita pedidos enormes à base de dados

/**
 * Valida um período AAAAMMDD (ou AAAA-MM-DD): datas reais, início ≤ fim e no máximo NAVAL_PERIODO_MAX_DIAS.
 * Lança NavalErro 400 se não for válido.
 */
function naval_validar_periodo(string $de, string $ate, int $max = NAVAL_PERIODO_MAX_DIAS): void
{
    $data = function (string $d) {
        $d = (string) preg_replace('/\D/', '', $d);
        if (!preg_match('/^(\d{4})(\d{2})(\d{2})$/', $d, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) return null;
        return (int) gmmktime(0, 0, 0, (int) $m[2], (int) $m[3], (int) $m[1]);
    };
    $a = $data($de);
    $b = $data($ate);
    if ($a === null || $b === null) throw new NavalErro('Datas inválidas.', 400, ['detalhe' => 'Use datas reais no formato AAAAMMDD.']);
    if ($a > $b) throw new NavalErro('A data inicial é posterior à data final.', 400);
    if (($b - $a) / 86400 + 1 > $max) throw new NavalErro('Período demasiado longo (máximo ' . $max . ' dias). Escolha um período mais curto.', 400);
}

/** Pedido de alteração: tem de trazer o código anti-falsificação (CSRF) da sessão. */
function naval_exigir_csrf(array $q): void
{
    if (!hash_equals((string) ($_SESSION['csrf'] ?? ''), (string) ($q['csrf'] ?? ''))) {
        throw new NavalErro('Pedido inválido. Actualize a página e tente de novo.', 403);
    }
}

/**
 * Código só para a ligação «Terminar sessão» (?t=…): derivado do código CSRF, para que o endereço (que
 * fica no histórico e nos registos do servidor) não revele o código que protege as alterações.
 */
function naval_token_saida(): string
{
    return substr(hash_hmac('sha256', 'terminar-sessao', naval_csrf()), 0, 32);
}

/** Código CSRF da sessão (criado na primeira vez). */
function naval_csrf(): string
{
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return (string) $_SESSION['csrf'];
}

/**
 * Detalhe técnico de um erro (mensagens do PHP, da base de dados, endereços internos): só aparece na
 * resposta em modo de diagnóstico (APP_DEBUG=1) ou para os perfis que operam a plataforma (SADM, TI).
 * Para os restantes fica só no log do PHP, para não expor a infra-estrutura nem dados internos.
 */
function naval_detalhe(string $detalhe): array
{
    if ($detalhe === '') return [];
    $perfil = '';
    // a sessão pode já estar fechada para escrita (API: session_write_close); os dados continuam em $_SESSION
    if (isset($_SESSION) && !empty($_SESSION['usuario_autenticado']) && function_exists('naval_utilizador')) {
        $perfil = (string) (naval_utilizador()['perfil'] ?? '');
    }
    return naval_debug() || in_array($perfil, ['SADM', 'TI'], true) ? ['detalhe' => $detalhe] : [];
}

/**
 * Erros em JSON: NavalErro passa a {ok:false, erro, ...} com o seu código; qualquer outro erro
 * (incluindo erros fatais do PHP) é registado no log e devolvido como 500 sem mostrar HTML.
 */
/** Entrega a falha à vigilância (nucleo/servicos/vigia.php); nunca impede a resposta de erro. */
function naval_falha_vigiar(string $origem, string $tipo, string $msg, string $ficheiro, int $linha): void
{
    try {
        naval_servico('vigia');
        naval_vigia_registar_falha($origem, $tipo, $msg, $ficheiro, $linha);
    } catch (Throwable $x) {
        // sem vigilância, fica o error_log
    }
}

function naval_erros_em_json(string $origem): void
{
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    error_reporting(E_ALL);
    ob_start();
    set_exception_handler(function (Throwable $e) use ($origem) {
        if ($e instanceof NavalErro) {
            naval_json(['ok' => false, 'erro' => $e->getMessage()] + $e->extra(), $e->estado());
        }
        error_log('[' . $origem . '] ' . get_class($e) . ': ' . $e->getMessage() . ' em ' . $e->getFile() . ':' . $e->getLine());
        naval_falha_vigiar($origem, get_class($e), $e->getMessage(), $e->getFile(), $e->getLine());
        naval_json(['ok' => false, 'erro' => 'Erro interno no servidor.'] + naval_detalhe($e->getMessage()), 500);
    });
    register_shutdown_function(function () use ($origem) {
        $e = error_get_last();
        if (!$e || !in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) return;
        @ini_set('memory_limit', '-1'); // folga para conseguir responder depois de falta de memória
        $msg = $e['message'];
        $erro = 'Erro interno no servidor.';
        if (stripos($msg, 'curl_init') !== false) $erro = 'A extensão cURL do PHP não está activa no servidor (php.ini: extension=curl).';
        elseif (stripos($msg, 'simplexml') !== false) $erro = 'A extensão SimpleXML do PHP não está activa no servidor.';
        elseif (stripos($msg, 'Maximum execution time') !== false) $erro = 'O Pinguim BO demorou demasiado a responder. Experimente um período mais curto.';
        elseif (stripos($msg, 'Allowed memory size') !== false) $erro = 'Memória do PHP insuficiente para este período. Experimente um período mais curto.';
        error_log('[' . $origem . '] FATAL ' . $msg . ' em ' . $e['file'] . ':' . $e['line']);
        naval_falha_vigiar($origem, 'fatal', $msg, (string) $e['file'], (int) $e['line']);
        naval_json(['ok' => false, 'erro' => $erro] + naval_detalhe($msg . ' (' . basename($e['file']) . ':' . $e['line'] . ')'), 500);
    });
}
