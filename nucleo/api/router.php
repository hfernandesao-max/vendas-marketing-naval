<?php
/**
 * API central do portal (v1): único ponto de comunicação entre o front-end (reports/) e o back-end (nucleo/).
 *
 * Endereço público: api/index.php/v1/<recurso> (ou api/v1/<recurso> com mod_rewrite). Cada pedido passa por:
 *   1. origem: só o próprio site (sem CORS; um pedido com Origin de outro site é recusado);
 *   2. rota e método: lista fechada (404 / 405 com Allow);
 *   3. autenticação: token JWT de acesso (Bearer, 15 min) ligado à sessão e à conta (nucleo/seguranca/jwt.php);
 *      o token obtém-se em POST v1/auth/token com a sessão aberta na página de entrada e o código CSRF;
 *   4. ritmo: no máximo NAVAL_API_RITMO pedidos por minuto por pessoa (429 com Retry-After);
 *   5. pedido: POST só com JSON e com tamanho limitado;
 *   6. autorização: cada serviço verifica a matriz de acessos (naval_exigir) e o âmbito (rotas, NIF);
 *   7. resposta: JSON, sem detalhes técnicos fora de SADM/TI, ETag (304 quando não mudou) e compressão.
 */
defined('NAVAL_NUCLEO') || exit;
require_once dirname(__DIR__) . '/seguranca/jwt.php';

const NAVAL_API_RITMO = 300;                    // pedidos por minuto por pessoa (API_RITMO no .env)
const NAVAL_API_CORPO_MAX = 26214400;           // 25 MB (ficheiros Excel do SAP e do plano em base64)

/**
 * Rotas da API v1: rota => [métodos, acesso, serviço em nucleo/api/v1/, parâmetros fixos].
 * acesso: 'jwt' (token obrigatório), 'sessao' (troca da sessão por um token), 'publico' (sem autenticação).
 * Parâmetros fixos com valor null copiam o parâmetro indicado pelo nome (ex.: data → xlsx).
 */
function naval_api_rotas(): array
{
    $r = [
        'v1/auth/token' => [['POST'], 'sessao', 'token.php', []],
        'v1/csp-relatorio' => [['POST'], 'publico', 'csp.php', []],
        'v1/sessao' => [['GET'], 'jwt', 'sessao.php', []],
        'v1/vendas' => [['GET'], 'jwt', 'vendas.php', []],
        'v1/vendas/estado' => [['GET'], 'jwt', 'vendas.php', ['ping' => '1']],
        'v1/vendas/diagnostico' => [['GET'], 'jwt', 'vendas.php', ['diag' => '1']],
        'v1/vendas/estrutura' => [['GET'], 'jwt', 'vendas.php', ['estrutura' => '1']],
        'v1/dados/rotas' => [['GET'], 'jwt', 'dados.php', ['f' => 'rotas']],
        'v1/dados/cargas' => [['GET'], 'jwt', 'dados.php', ['f' => 'cargas']],
        'v1/dados/clientes' => [['GET'], 'jwt', 'dados.php', ['f' => 'clientes']],
        'v1/cadastros' => [['GET'], 'jwt', 'cadastros.php', []],
        'v1/sincronizacao' => [['GET'], 'jwt', 'sincronizacao.php', []],
        'v1/plano' => [['GET', 'POST'], 'jwt', 'plano.php', []],
        'v1/plano/historico' => [['GET'], 'jwt', 'plano.php', ['historico' => '1']],
        'v1/plano/excel' => [['GET'], 'jwt', 'plano.php', ['xlsx' => 'data']],
        'v1/sap' => [['GET', 'POST'], 'jwt', 'sap.php', []],
        'v1/sap/modelo' => [['GET'], 'jwt', 'sap.php', ['modelo' => '1']],
        'v1/sap/excel' => [['GET'], 'jwt', 'sap.php', ['xlsx' => 'numero']],
        'v1/gestao' => [['GET', 'POST'], 'jwt', 'gestao.php', []],
        'v1/exportacoes' => [['POST'], 'jwt', 'exportacoes.php', []],
        'v1/estrutura-dados' => [['GET'], 'jwt', 'estrutura-dados.php', []],
    ];
    // serviços que só existem em algumas instalações (ex.: stock do SQL Server)
    if (is_file(__DIR__ . '/v1/stock.php')) {
        $r['v1/stock'] = [['GET'], 'jwt', 'stock.php', []];
        $r['v1/stock/estado'] = [['GET'], 'jwt', 'stock.php', ['estado' => '1']];
    }
    return $r;
}

/** Rota pedida: PATH_INFO (api/index.php/v1/...) ou ?r= (sem PATH_INFO); só caracteres seguros. */
function naval_api_rota(): string
{
    $r = (string) ($_SERVER['PATH_INFO'] ?? '');
    if ($r === '' && isset($_GET['r'])) $r = (string) $_GET['r'];
    $r = trim($r, '/');
    return preg_match('#^v[0-9]+(/[a-z0-9-]+)*$#', $r) ? $r : '';
}

/** Só pedidos do próprio site: um Origin de outro site é recusado (sem CORS). */
function naval_api_origem(): void
{
    $o = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($o === '' || $o === 'null') {
        if ($o === 'null') throw new NavalErro('Origem não autorizada.', 403, ['codigo' => 'origem']);
        return;
    }
    $h = strtolower((string) parse_url($o, PHP_URL_HOST) . (parse_url($o, PHP_URL_PORT) ? ':' . parse_url($o, PHP_URL_PORT) : ''));
    $proprio = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $semPorta = function ($x) { return preg_replace('/:(80|443)$/', '', $x); };
    if ($semPorta($h) !== $semPorta($proprio)) throw new NavalErro('Origem não autorizada.', 403, ['codigo' => 'origem']);
}

/** Limite de pedidos por minuto (por pessoa ou, sem pessoa, por IP). */
function naval_api_ritmo(string $quem, int $max = 0): void
{
    if ($max <= 0) $max = max(30, (int) naval_config('API_RITMO', (string) NAVAL_API_RITMO));
    $id = substr(hash('sha256', $quem), 0, 24);
    $min = (int) floor(time() / 60);
    $n = naval_exclusivo('ritmo-' . $id, function () use ($id, $min) {
        $f = naval_pasta('limites') . '/api-' . $id . '.json';
        $d = naval_json_ler($f);
        $n = ($d['min'] ?? 0) === $min ? (int) ($d['n'] ?? 0) + 1 : 1;
        naval_json_gravar($f, ['min' => $min, 'n' => $n], false);
        return $n;
    });
    if ($n > $max) {
        header('Retry-After: ' . (60 - time() % 60));
        throw new NavalErro('Demasiados pedidos. Aguarde um momento.', 429, ['codigo' => 'ritmo']);
    }
}

/** Pedido POST: JSON (ou relatório da CSP) e tamanho limitado. */
function naval_api_corpo(string $rota): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') return;
    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > NAVAL_API_CORPO_MAX) throw new NavalErro('Pedido demasiado grande.', 413);
    $tipo = strtolower(trim(explode(';', (string) ($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
    $aceites = $rota === 'v1/csp-relatorio' ? ['application/csp-report', 'application/reports+json', 'application/json'] : ['application/json'];
    if (!in_array($tipo, $aceites, true)) throw new NavalErro('Tipo de conteúdo não suportado (use application/json).', 415);
}

/** Atende o pedido à API. */
function naval_api_executar(): void
{
    define('NAVAL_API', true);
    naval_erros_em_json('api');
    naval_cabecalhos_seguranca();
    $metodo = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
    naval_api_origem();
    $rota = naval_api_rota();
    $rotas = naval_api_rotas();
    if ($rota === '' || !isset($rotas[$rota])) throw new NavalErro('Recurso desconhecido.', 404, ['codigo' => 'rota']);
    [$metodos, $acesso, $servico, $fixos] = $rotas[$rota];
    if ($metodo === 'HEAD') $metodo = 'GET';
    if (!in_array($metodo, $metodos, true)) {
        header('Allow: ' . implode(', ', $metodos));
        throw new NavalErro('Método não permitido.', 405, ['codigo' => 'metodo']);
    }
    naval_api_corpo($rota);
    $GLOBALS['naval_api_rota'] = $rota;

    if ($acesso === 'jwt') {
        $c = naval_jwt_autenticar();
        $GLOBALS['naval_jwt'] = $c;
        naval_api_ritmo('conta|' . $c['sub']);
    } else {
        // por IP (vários utilizadores podem partilhar o mesmo IP na rede da empresa)
        naval_api_ritmo('ip|' . naval_ip_cliente(), $acesso === 'publico' ? 120 : 1200);
    }
    // parâmetros fixos da rota (ex.: v1/sap/excel?numero=5 → xlsx=5)
    foreach ($fixos as $k => $v) {
        $_GET[$k] = in_array($v, ['data', 'numero'], true) ? (string) ($_GET[$v] ?? '') : $v;
    }
    require __DIR__ . '/v1/' . $servico;
    if (http_response_code() === 204) exit;   // resposta sem corpo (ex.: relatórios da CSP)
    throw new NavalErro('O serviço não respondeu.', 500);
}
