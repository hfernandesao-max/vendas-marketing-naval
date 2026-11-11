<?php
/**
 * Stock de artigos RTM (carrinhas e armazéns), lido do SQL Server do Pinguim BO com a função
 * dbo.GET_STOCK (só SELECT). Sem ligação à base de dados, usa reports/data/stock.json (gerado por
 * scripts/converter_stock.js a partir das exportações «Stock de artigos»).
 */
defined('NAVAL_NUCLEO') || exit;
require_once __DIR__ . '/rotas.php';
require_once __DIR__ . '/cargas.php';

function naval_stock_centro(string $nomeEntidade): string
{
    $n = function_exists('mb_strtoupper') ? mb_strtoupper($nomeEntidade, 'UTF-8') : strtoupper($nomeEntidade);
    if (strpos($n, 'HIGITEC') !== false) return 'HIGITEC';
    if (strpos($n, 'FOODTEC') !== false || strpos($n, 'REFITEC') !== false) return 'FOODTEC';
    if (strpos($n, 'GRUPO NAVAL') !== false || strpos($n, 'FORMAÇÃO') !== false || strpos($n, 'FORMACAO') !== false) return 'Grupo Naval';
    return 'Geral';
}

/** Carrinha, viatura e motorista de cada rota: plano de cargas (histórico e dias publicados no portal). */
function naval_stock_cargas(): array
{
    $porRota = [];
    $porDia = [];
    $linhas = naval_json_ler(naval_dados_reports() . '/cargas.json');
    foreach (naval_plano_para_cargas() as $x) $linhas[] = $x;
    usort($linhas, function ($a, $b) { return strcmp((string) ($a['data'] ?? ''), (string) ($b['data'] ?? '')); });
    foreach ($linhas as $c) {
        $rota = trim((string) ($c['rota'] ?? ''));
        if ($rota === '') continue;
        $info = [];
        foreach (['matricula', 'viatura', 'motorista', 'transportadora'] as $k) {
            $v = trim((string) ($c[$k] ?? ''));
            $info[$k] = $v !== '' ? $v : '—';
        }
        $porRota[$rota] = $info;
        if (!empty($c['data'])) $porDia[$c['data'] . '|' . $rota] = $info;
    }
    return [$porRota, $porDia];
}

/** Último erro da consulta de stock à base de dados (para o mostrar em vez de dados antigos). */
function naval_stock_erro(?string $e = null): ?string
{
    static $ultimo = null;
    if ($e !== null) $ultimo = $e;
    return $ultimo;
}

/**
 * Colunas existentes nas tabelas usadas no cálculo do stock ({TABELA: {COLUNA: true}}), lidas uma vez por
 * pedido em INFORMATION_SCHEMA (só SELECT). A consulta só usa colunas que existem nesta base de dados.
 */
function naval_stock_colunas(): array
{
    static $c = null;
    if ($c !== null) return $c;
    $c = [];
    foreach (naval_bd_consultar("
        SELECT UPPER(TABLE_NAME) AS T, UPPER(COLUMN_NAME) AS C
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = 'dbo'
          AND UPPER(TABLE_NAME) IN ('WAREHOUSE', 'ENTITY', 'PRODUCT', 'DOCMOV', 'INVENTORYDETAIL', 'CCH_PRODUCTSTOCK')") as $r) {
        $c[$r['T']][$r['C']] = true;
    }
    return $c;
}

/**
 * Consulta (só SELECT) e parâmetros do stock na data e armazém/rota. $col: colunas existentes
 * (naval_stock_colunas); cada filtro ou fonte só entra se as suas colunas existirem.
 */
function naval_stock_consulta(string $data, string $armazem, array $col): array
{
    $tem = function (string $t, string ...$cs) use ($col): bool {
        foreach ($cs as $x) if (empty($col[$t][$x])) return false;
        return true;
    };
    $naoApagado = function (string $t, string $alias) use ($tem): string {
        return $tem($t, 'DELETED') ? ' AND ' . $alias . '.DELETED = 0' : '';
    };
    // Data e hora no formato canónico do ODBC (AAAA-MM-DD hh:mm:ss): o driver ODBC converte o parâmetro para
    // data antes de o enviar, por isso não depende do idioma da sessão; o formato ISO com «T» é recusado pelo
    // ODBC Driver 17 («Invalid character value for cast specification»).
    $params = [':data_fim_com_hora' => substr($data, 0, 10) . ' 23:59:59'];

    $filtroArm = trim($armazem);
    if ($filtroArm !== '' && strtolower($filtroArm) !== 'todos' && strtolower($filtroArm) !== 'all') {
        $params[':armazem'] = $filtroArm;
        $params[':armazem_carrinha'] = '1000' . $filtroArm;
        $params[':armazem_like'] = '%' . $filtroArm . '%';
        $condArmazem = "E.CODE = :armazem OR E.CODE = :armazem_carrinha OR E.DESCRIPTION LIKE :armazem_like";
    } else {
        $condArmazem = "E.CODE LIKE '100010%'
                    OR E.DESCRIPTION LIKE '%10[0-9][0-9]%'
                    OR E.CODE IN ('1150', '1151')
                    OR E.DESCRIPTION LIKE '%HIGITEC%'
                    OR E.DESCRIPTION LIKE '%FOODTEC%'
                    OR E.DESCRIPTION LIKE '%REFITEC%'";
    }
    // Só armazéns reais (tabela WAREHOUSE), quando a ligação WAREHOUSE.ENTITY_ID existe
    $armazens = $tem('WAREHOUSE', 'ENTITY_ID')
        ? "SELECT E.ID AS ENTITY_ID, E.CODE, E.DESCRIPTION
                FROM WAREHOUSE W
                INNER JOIN ENTITY E ON E.ID = W.ENTITY_ID
                WHERE (" . $condArmazem . ")" . $naoApagado('WAREHOUSE', 'W') . $naoApagado('ENTITY', 'E')
        : "SELECT E.ID AS ENTITY_ID, E.CODE, E.DESCRIPTION
                FROM ENTITY E
                WHERE (" . $condArmazem . ")" . $naoApagado('ENTITY', 'E');

    // Artigos de cada armazém: movimentos e, se existirem nesta base de dados, contagens de inventário e saldos
    $fontes = ["SELECT MV.WAREHOUSE_ID, MV.PRODUCT_ID
                FROM DOCMOV MV
                INNER JOIN ArmazensAlvo A ON A.ENTITY_ID = MV.WAREHOUSE_ID
                WHERE MV.PRODUCT_ID IS NOT NULL" . $naoApagado('DOCMOV', 'MV')];
    $invArm = $tem('INVENTORYDETAIL', 'CCH_WAREHOUSE_ID') ? 'CCH_WAREHOUSE_ID' : ($tem('INVENTORYDETAIL', 'WAREHOUSE_ID') ? 'WAREHOUSE_ID' : '');
    if ($invArm !== '' && $tem('INVENTORYDETAIL', 'PRODUCT_ID')) {
        $fontes[] = "SELECT ID.$invArm, ID.PRODUCT_ID
                FROM INVENTORYDETAIL ID
                INNER JOIN ArmazensAlvo A ON A.ENTITY_ID = ID.$invArm
                WHERE ID.PRODUCT_ID IS NOT NULL" . $naoApagado('INVENTORYDETAIL', 'ID');
    }
    if ($tem('CCH_PRODUCTSTOCK', 'WAREHOUSE_ID', 'PRODUCT_ID')) {
        $fontes[] = "SELECT PS.WAREHOUSE_ID, PS.PRODUCT_ID
                FROM CCH_PRODUCTSTOCK PS
                INNER JOIN ArmazensAlvo A ON A.ENTITY_ID = PS.WAREHOUSE_ID
                WHERE PS.PRODUCT_ID IS NOT NULL" . $naoApagado('CCH_PRODUCTSTOCK', 'PS');
    }

    $sql = "
        WITH ArmazensAlvo AS (
            " . $armazens . "
        ),
        ProdutosAlvo AS (
            " . implode("\n            UNION\n            ", $fontes) . "
        )
        SELECT
            A.ENTITY_ID AS WAREHOUSE_ID,
            A.CODE AS COD_ARMAZEM,
            A.DESCRIPTION AS ARMAZEM,
            P.ID AS PRODUCT_ID,
            P.CODE AS CODIGO_PRODUTO,
            P.DESCRIPTION AS PRODUTO,
            CAST(STK.QNT AS FLOAT) AS QUANTIDADE
        FROM ProdutosAlvo PA
        INNER JOIN ArmazensAlvo A ON A.ENTITY_ID = PA.WAREHOUSE_ID
        INNER JOIN PRODUCT P ON P.ID = PA.PRODUCT_ID" . $naoApagado('PRODUCT', 'P') . "
        CROSS APPLY dbo.GET_STOCK(:data_fim_com_hora, P.ID, A.ENTITY_ID, 1, NULL, 1, 1) STK
        WHERE STK.QNT <> 0
        ORDER BY A.CODE, P.DESCRIPTION";
    return [$sql, $params];
}

/**
 * Stock na data indicada (AAAA-MM-DD) e armazém/rota, calculado no SQL Server pelo próprio Pinguim BO
 * (dbo.GET_STOCK, só SELECT); null se a base de dados não estiver configurada ou não responder.
 *
 * Artigos considerados em cada armazém (como no Pinguim): os que tiveram movimentos (DOCMOV), contagens de
 * inventário (INVENTORYDETAIL) ou saldo registado (CCH_PRODUCTSTOCK). Sem as duas últimas fontes, um artigo
 * que entrou por inventário inicial e ainda não teve movimentos ficava de fora e o stock aparecia abaixo do real.
 * Só armazéns reais (WAREHOUSE) e registos não apagados; o filtro de armazéns é aplicado antes do cálculo.
 */
function naval_stock_sql(string $data, string $armazem = ''): ?array
{
    if (!naval_bd_configurada()) return null;
    try {
        [$sql, $params] = naval_stock_consulta($data, $armazem, naval_stock_colunas());
        $rows = naval_bd_consultar($sql, $params);
    } catch (Throwable $e) {
        error_log('[stock SQL] ' . $e->getMessage());
        naval_stock_erro($e->getMessage());
        return null;
    }
    return naval_stock_organizar($rows, $data);
}

/** Separa as linhas em carrinhas (armazéns 100010NN / «Armazém 10NN») e armazéns centrais. */
function naval_stock_organizar(array $rows, string $dataFim): array
{
    $promotores = naval_promotores();
    [$mapCargas, $mapCargasData] = naval_stock_cargas();
    $carrinhas = [];
    $armazens = [];
    $linhasCarrinhas = [];
    $linhasArmazens = [];
    foreach ($rows as $r) {
        $codArmazem = trim((string) ($r['COD_ARMAZEM'] ?? ''));
        $nomeArmazem = trim((string) ($r['ARMAZEM'] ?? ''));
        $codProduto = trim((string) ($r['CODIGO_PRODUTO'] ?? ''));
        $nomeProduto = trim((string) ($r['PRODUTO'] ?? ''));
        $quantidade = (float) ($r['QUANTIDADE'] ?? 0);
        $rota = '';
        if (preg_match('/^100010(\d{2})$/', $codArmazem, $m)) $rota = '10' . $m[1];
        elseif (preg_match('/10\d{2}/', $nomeArmazem, $m)) $rota = $m[0];

        if ($rota !== '') {
            $promotor = $promotores[$rota] ?? ('Promotor ' . $rota);
            $info = $mapCargasData[$dataFim . '|' . $rota] ?? $mapCargas[$rota] ?? ['matricula' => '—', 'viatura' => 'Carrinha ' . $rota, 'motorista' => '—', 'transportadora' => '—'];
            $viaturaMat = ($info['viatura'] !== '—' && $info['matricula'] !== '—') ? $info['viatura'] . ' · ' . $info['matricula']
                : ($info['matricula'] !== '—' ? $info['matricula'] : 'Carrinha ' . $rota);
            if (!isset($carrinhas[$rota])) {
                $carrinhas[$rota] = ['chave' => $dataFim . '|' . $rota, 'data' => $dataFim, 'rota' => $rota, 'promotor' => $promotor, 'carrinha' => $viaturaMat,
                    'viatura' => $info['viatura'], 'matricula' => $info['matricula'], 'motorista' => $info['motorista'], 'transportadora' => $info['transportadora'],
                    'armazem' => $nomeArmazem, 'total_quantidade' => 0.0, 'total_caixas' => 0.0, 'n_artigos' => 0, 'itens' => [], 'artigos' => []];
            }
            $carrinhas[$rota]['itens'][] = ['codigo' => $codProduto, 'produto' => $nomeProduto, 'armazem' => $nomeArmazem, 'quantidade' => $quantidade];
            $carrinhas[$rota]['artigos'][] = ['codigo' => $codProduto, 'artigo' => $nomeProduto, 'armazem' => $nomeArmazem, 'quantidade' => $quantidade];
            $carrinhas[$rota]['total_quantidade'] += $quantidade;
            $carrinhas[$rota]['total_caixas'] += $quantidade;
            $carrinhas[$rota]['n_artigos']++;
            $linhasCarrinhas[] = ['chave' => $dataFim . '|' . $rota . '|' . $codProduto, 'data' => $dataFim, 'rota' => $rota, 'promotor' => $promotor, 'carrinha' => $viaturaMat,
                'matricula' => $info['matricula'], 'viatura' => $info['viatura'], 'codigo' => $codProduto, 'produto' => $nomeProduto, 'artigo' => $nomeProduto,
                'armazem' => $nomeArmazem, 'quantidade' => $quantidade];
            continue;
        }
        // Armazém central / físico
        $k = $codArmazem !== '' ? $codArmazem : $nomeArmazem;
        if (!isset($armazens[$k])) {
            $armazens[$k] = ['chave' => $dataFim . '|' . $k, 'data' => $dataFim, 'cod_armazem' => $codArmazem, 'armazem' => $nomeArmazem,
                'centro' => naval_stock_centro($nomeArmazem), 'total_quantidade' => 0.0, 'n_artigos' => 0, 'itens' => [], 'artigos' => []];
        }
        $armazens[$k]['itens'][] = ['codigo' => $codProduto, 'produto' => $nomeProduto, 'armazem' => $nomeArmazem, 'quantidade' => $quantidade];
        $armazens[$k]['artigos'][] = ['codigo' => $codProduto, 'artigo' => $nomeProduto, 'armazem' => $nomeArmazem, 'quantidade' => $quantidade];
        $armazens[$k]['total_quantidade'] += $quantidade;
        $armazens[$k]['n_artigos']++;
        $linhasArmazens[] = ['chave' => $dataFim . '|' . $k . '|' . $codProduto, 'data' => $dataFim, 'centro' => $armazens[$k]['centro'], 'cod_armazem' => $codArmazem,
            'armazem' => $nomeArmazem, 'codigo' => $codProduto, 'produto' => $nomeProduto, 'artigo' => $nomeProduto, 'quantidade' => $quantidade];
    }
    ksort($carrinhas);
    return [
        'origem' => 'SQL Server (dbo.GET_STOCK)', 'data' => $dataFim, 'datas_disponiveis' => [$dataFim], 'ultima_data' => $dataFim, 'dados_ate' => $dataFim,
        'carrinhas' => array_values($carrinhas), 'armazens' => array_values($armazens), 'linhas_carrinhas' => $linhasCarrinhas, 'linhas_armazens' => $linhasArmazens,
        'total_carrinhas' => count($carrinhas), 'total_armazens' => count($armazens),
    ];
}

/** Stock da data e armazém (SQL Server) ou, sem ligação, o ficheiro stock.json. null se não houver nenhum. */
function naval_stock(string $data, string $armazem = ''): ?array
{
    $d = naval_stock_sql($data, $armazem);
    if ($d !== null) return $d;
    // Com base de dados configurada, nunca mostrar dados antigos de um ficheiro como se fossem da data pedida:
    // diz-se porque é que o cálculo falhou (o detalhe técnico só para SADM/TI, pela NavalErro).
    if (naval_bd_configurada()) {
        $e = (string) naval_stock_erro();
        $msg = 'O stock não pôde ser calculado na base de dados.';
        // classificação pela mensagem do SQL Server (a ordem conta: o texto de qualquer erro ODBC contém «Driver»)
        if (preg_match('/Invalid (column|object) name/i', $e)) $msg = 'A consulta de stock não corresponde à estrutura desta base de dados.';
        elseif (preg_match('/permission|permiss/i', $e)) $msg = 'O utilizador da base de dados não tem permissão para ler a função de stock (dbo.GET_STOCK).';
        elseif (preg_match('/timeout|expired|tempo limite/i', $e)) $msg = 'A base de dados demorou demasiado a calcular o stock. Escolha um armazém ou rota.';
        elseif (preg_match('/Login failed|could not find driver|connection|08001|HYT00/i', $e)) $msg = 'Sem ligação à base de dados do Pinguim BO.';
        $tecnico = function_exists('naval_perfil') && in_array(naval_perfil(), ['SADM', 'TI'], true);
        throw new NavalErro($msg, 503, ['codigo' => 'stock_bd'] + ($tecnico ? ['detalhe' => $e] : []));
    }
    // Sem base de dados configurada (desenvolvimento, testes): exportação «Stock de artigos» convertida
    $f = naval_json_ler(naval_dados_reports() . '/stock.json');
    if (!$f) return null;
    $f['origem'] = $f['origem'] ?? 'stock.json (exportação «Stock de artigos»)';
    $filtro = trim($armazem);
    if ($filtro !== '' && strtolower($filtro) !== 'todos' && strtolower($filtro) !== 'all') {
        $dentroCar = function ($x) use ($filtro) {
            return ($x['rota'] ?? '') === $filtro || ($x['matricula'] ?? '') === $filtro || strpos((string) ($x['armazem'] ?? ''), $filtro) !== false;
        };
        $dentroArm = function ($x) use ($filtro) {
            return ($x['cod_armazem'] ?? '') === $filtro || strpos((string) ($x['armazem'] ?? ''), $filtro) !== false;
        };
        if (!empty($f['carrinhas'])) $f['carrinhas'] = array_values(array_filter($f['carrinhas'], $dentroCar));
        if (!empty($f['linhas_carrinhas'])) $f['linhas_carrinhas'] = array_values(array_filter($f['linhas_carrinhas'], $dentroCar));
        if (!empty($f['armazens'])) $f['armazens'] = array_values(array_filter($f['armazens'], $dentroArm));
        if (!empty($f['linhas_armazens'])) $f['linhas_armazens'] = array_values(array_filter($f['linhas_armazens'], $dentroArm));
    }
    return $f;
}

/**
 * Âmbito do utilizador: só as carrinhas das suas rotas (SUP e PRO) e os armazéns centrais só para quem
 * tem a página «Stock por armazém».
 */
function naval_stock_ambito(array $d, ?array $rotas, bool $veArmazens): array
{
    if ($rotas !== null) {
        $dentro = function ($x) use ($rotas) { return in_array((string) ($x['rota'] ?? ''), $rotas, true); };
        foreach (['carrinhas', 'linhas_carrinhas'] as $k) if (isset($d[$k]) && is_array($d[$k])) $d[$k] = array_values(array_filter($d[$k], $dentro));
    }
    if (!$veArmazens) {
        $d['armazens'] = [];
        $d['linhas_armazens'] = [];
    }
    return $d;
}
