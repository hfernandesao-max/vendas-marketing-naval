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
    }
    echo $json;
    exit;
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
        $q = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($q)) $q = [];
    }
    return $q;
}

/** Pedido de alteração: tem de trazer o código anti-falsificação (CSRF) da sessão. */
function naval_exigir_csrf(array $q): void
{
    if (!hash_equals((string) ($_SESSION['csrf'] ?? ''), (string) ($q['csrf'] ?? ''))) {
        throw new NavalErro('Pedido inválido. Actualize a página e tente de novo.', 403);
    }
}

/** Código CSRF da sessão (criado na primeira vez). */
function naval_csrf(): string
{
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return (string) $_SESSION['csrf'];
}

/**
 * Erros em JSON: NavalErro passa a {ok:false, erro, ...} com o seu código; qualquer outro erro
 * (incluindo erros fatais do PHP) é registado no log e devolvido como 500 sem mostrar HTML.
 */
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
        naval_json(['ok' => false, 'erro' => 'Erro interno no servidor.', 'detalhe' => $e->getMessage()], 500);
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
        naval_json(['ok' => false, 'erro' => $erro, 'detalhe' => $msg . ' (' . basename($e['file']) . ':' . $e['line'] . ')'], 500);
    });
}
