<?php
/**
 * Catálogo de esquema (Schema Registry) do Pinguim BO e do OLAP — só a estrutura, lida do catálogo do SQL Server
 * (sys.*), sem ler nenhuma linha das tabelas de negócio. Só SELECT, com WITH (NOLOCK), pela guarda naval_bd_so_leitura.
 *
 *     php ferramentas/catalogo-esquema.php                       → docs/database-catalog/schema-map.json
 *     php ferramentas/catalogo-esquema.php --saida=C:\Temp\schema-map.json
 *
 * Para cada base: tabelas e vistas com o número aproximado de linhas (metadados das partições, sem COUNT(*)), colunas
 * (tipo, tamanho, nulo, por defeito, identidade), chaves primárias, únicas e índices, chaves estrangeiras declaradas,
 * relações inferidas pelo nome (COLUNA_ID → TABELA.ID), texto das vistas e, se houver permissão, a última utilização.
 * Não grava credenciais, nome do servidor nem dados. O ficheiro gerado NÃO vai para o repositório (público): ver
 * .gitignore e docs/database-catalog/ACCESS_STRATEGY.md.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . '/nucleo/arranque.php';
naval_servico('bd');

$o = getopt('', ['saida::']);
$saida = (string) ($o['saida'] ?? dirname(__DIR__) . '/docs/database-catalog/schema-map.json');

$Q = [
    'identificacao' => "SELECT DB_NAME() AS BASE, CAST(SERVERPROPERTY('ProductVersion') AS VARCHAR(40)) AS VERSAO,
        CAST(SERVERPROPERTY('Edition') AS VARCHAR(80)) AS EDICAO, CAST(DATABASEPROPERTYEX(DB_NAME(), 'Collation') AS VARCHAR(80)) AS COLLATION_BD",
    'objectos' => "SELECT S.name AS ESQUEMA, O.name AS OBJECTO, O.type AS TIPO,
        (SELECT SUM(P.rows) FROM sys.partitions P WITH (NOLOCK) WHERE P.object_id = O.object_id AND P.index_id IN (0, 1)) AS LINHAS,
        CONVERT(VARCHAR(19), O.create_date, 120) AS CRIADO, CONVERT(VARCHAR(19), O.modify_date, 120) AS ALTERADO
        FROM sys.objects O WITH (NOLOCK) INNER JOIN sys.schemas S WITH (NOLOCK) ON S.schema_id = O.schema_id
        WHERE O.type IN ('U', 'V') AND O.is_ms_shipped = 0",
    'colunas' => "SELECT S.name AS ESQUEMA, O.name AS OBJECTO, C.column_id AS ORDEM, C.name AS COLUNA, TY.name AS TIPO, C.max_length AS BYTES,
        C.precision AS PRECISAO, C.scale AS ESCALA, C.is_nullable AS NULO, C.is_identity AS IDENTIDADE, C.is_computed AS CALCULADA, DF.definition AS POR_DEFEITO
        FROM sys.columns C WITH (NOLOCK)
        INNER JOIN sys.objects O WITH (NOLOCK) ON O.object_id = C.object_id
        INNER JOIN sys.schemas S WITH (NOLOCK) ON S.schema_id = O.schema_id
        INNER JOIN sys.types TY WITH (NOLOCK) ON TY.user_type_id = C.user_type_id
        LEFT JOIN sys.default_constraints DF WITH (NOLOCK) ON DF.object_id = C.default_object_id
        WHERE O.type IN ('U', 'V') AND O.is_ms_shipped = 0",
    'indices' => "SELECT S.name AS ESQUEMA, T.name AS TABELA, I.name AS INDICE, I.is_primary_key AS PK, I.is_unique AS UNICO, I.type_desc AS TIPO,
        C.name AS COLUNA, IC.key_ordinal AS ORDEM, IC.is_included_column AS INCLUIDA
        FROM sys.indexes I WITH (NOLOCK)
        INNER JOIN sys.tables T WITH (NOLOCK) ON T.object_id = I.object_id
        INNER JOIN sys.schemas S WITH (NOLOCK) ON S.schema_id = T.schema_id
        INNER JOIN sys.index_columns IC WITH (NOLOCK) ON IC.object_id = I.object_id AND IC.index_id = I.index_id
        INNER JOIN sys.columns C WITH (NOLOCK) ON C.object_id = IC.object_id AND C.column_id = IC.column_id
        WHERE I.type > 0",
    'chaves_estrangeiras' => "SELECT FK.name AS RELACAO, SP.name + '.' + TP.name AS TABELA, CP.name AS COLUNA, SR.name + '.' + TR.name AS REFERE, CR.name AS COLUNA_REFERIDA
        FROM sys.foreign_keys FK WITH (NOLOCK)
        INNER JOIN sys.foreign_key_columns FKC WITH (NOLOCK) ON FKC.constraint_object_id = FK.object_id
        INNER JOIN sys.tables TP WITH (NOLOCK) ON TP.object_id = FKC.parent_object_id
        INNER JOIN sys.schemas SP WITH (NOLOCK) ON SP.schema_id = TP.schema_id
        INNER JOIN sys.columns CP WITH (NOLOCK) ON CP.object_id = FKC.parent_object_id AND CP.column_id = FKC.parent_column_id
        INNER JOIN sys.tables TR WITH (NOLOCK) ON TR.object_id = FKC.referenced_object_id
        INNER JOIN sys.schemas SR WITH (NOLOCK) ON SR.schema_id = TR.schema_id
        INNER JOIN sys.columns CR WITH (NOLOCK) ON CR.object_id = FKC.referenced_object_id AND CR.column_id = FKC.referenced_column_id",
    'vistas' => "SELECT S.name AS ESQUEMA, O.name AS VISTA, M.definition AS DEFINICAO
        FROM sys.sql_modules M WITH (NOLOCK) INNER JOIN sys.objects O WITH (NOLOCK) ON O.object_id = M.object_id
        INNER JOIN sys.schemas S WITH (NOLOCK) ON S.schema_id = O.schema_id WHERE O.type = 'V'",
    'utilizacao' => "SELECT OBJECT_SCHEMA_NAME(U.object_id) AS ESQUEMA, OBJECT_NAME(U.object_id) AS TABELA,
        CONVERT(VARCHAR(19), MAX(U.last_user_seek), 120) AS ULTIMA_LEITURA, CONVERT(VARCHAR(19), MAX(U.last_user_scan), 120) AS ULTIMO_VARRIMENTO,
        CONVERT(VARCHAR(19), MAX(U.last_user_update), 120) AS ULTIMA_ESCRITA
        FROM sys.dm_db_index_usage_stats U WITH (NOLOCK) WHERE U.database_id = DB_ID() GROUP BY U.object_id",
];

/** Função pura: relações inferidas pelo nome — COLUNA «X_ID» ou «PREFIXO_X_ID» → tabela X com chave primária de uma coluna. */
function catalogo_inferir(array $tabelas, array $pks, array $declaradas): array
{
    $alvo = [];
    foreach ($pks as $t => $cols) if (count($cols) === 1) $alvo[strtoupper(explode('.', $t)[1])] = [$t, $cols[0]];
    $ja = [];
    foreach ($declaradas as $d) $ja[strtoupper($d['tabela'] . '.' . $d['coluna'])] = true;
    $out = [];
    foreach ($tabelas as $t => $info) {
        foreach ($info['colunas'] as $c) {
            $n = strtoupper($c['nome']);
            if (!preg_match('/^(.+)_ID$/', $n, $m) || isset($ja[strtoupper($t) . '.' . $n])) continue;
            $base = $m[1];
            $melhor = null;
            foreach ($alvo as $nome => $x) {
                if (($base === $nome || substr($base, -strlen($nome) - 1) === '_' . $nome) && ($melhor === null || strlen($nome) > strlen($melhor))) $melhor = $nome;
            }
            if ($melhor === null || $alvo[$melhor][0] === $t) continue;
            $out[] = ['tabela' => $t, 'coluna' => $c['nome'], 'refere' => $alvo[$melhor][0], 'coluna_referida' => $alvo[$melhor][1], 'origem' => 'inferida'];
        }
    }
    return $out;
}

$tipo = function (array $c) {
    $t = strtolower((string) $c['TIPO']);
    $b = (int) $c['BYTES'];
    if (in_array($t, ['varchar', 'char', 'varbinary', 'binary'], true)) return $t . '(' . ($b === -1 ? 'max' : $b) . ')';
    if (in_array($t, ['nvarchar', 'nchar'], true)) return $t . '(' . ($b === -1 ? 'max' : intdiv($b, 2)) . ')';
    if (in_array($t, ['decimal', 'numeric'], true)) return $t . '(' . (int) $c['PRECISAO'] . ',' . (int) $c['ESCALA'] . ')';
    return $t;
};

$mapa = ['gerado' => date('Y-m-d H:i:s'), 'nota' => 'Só estrutura (catálogo do SQL Server); sem dados, credenciais ou nome do servidor.', 'bases' => []];
foreach (['DB' => 'pinguim_bo', 'OLAP' => 'olap'] as $p => $chave) {
    echo "$chave: a ler o catálogo...\n";
    try {
        $pdo = naval_bd_criar($p);
    } catch (Throwable $e) {
        echo "   sem ligação: " . mb_substr($e->getMessage(), 0, 160) . "\n";
        $mapa['bases'][$chave] = ['erro' => 'sem ligação'];
        continue;
    }
    $r = [];
    foreach ($Q as $k => $sql) {
        $t0 = microtime(true);
        try {
            $r[$k] = naval_bd_consultar_em($pdo, $sql);
            echo '   ' . str_pad($k, 20) . count($r[$k]) . ' linhas · ' . round(microtime(true) - $t0, 1) . " s\n";
        } catch (Throwable $e) {
            $r[$k] = null;
            echo '   ' . str_pad($k, 20) . 'indisponível (' . mb_substr($e->getMessage(), 0, 100) . ")\n";
        }
    }
    $tab = [];
    foreach ((array) $r['objectos'] as $x) {
        $tab[$x['ESQUEMA'] . '.' . $x['OBJECTO']] = ['tipo' => $x['TIPO'] === 'V' ? 'vista' : 'tabela', 'linhas' => $x['LINHAS'] === null ? null : (int) $x['LINHAS'],
            'criado' => $x['CRIADO'], 'alterado' => $x['ALTERADO'], 'colunas' => [], 'pk' => [], 'indices' => []];
    }
    foreach ((array) $r['colunas'] as $c) {
        $t = $c['ESQUEMA'] . '.' . $c['OBJECTO'];
        if (!isset($tab[$t])) continue;
        $tab[$t]['colunas'][(int) $c['ORDEM']] = array_filter(['nome' => $c['COLUNA'], 'tipo' => $tipo($c), 'nulo' => (bool) $c['NULO'],
            'identidade' => (bool) $c['IDENTIDADE'] ?: null, 'calculada' => (bool) $c['CALCULADA'] ?: null, 'por_defeito' => $c['POR_DEFEITO']], function ($v) { return $v !== null; });
    }
    $pks = [];
    $idx = [];
    foreach ((array) $r['indices'] as $i) {
        $t = $i['ESQUEMA'] . '.' . $i['TABELA'];
        if (!isset($tab[$t])) continue;
        if ((int) $i['PK'] && !(int) $i['INCLUIDA']) $pks[$t][(int) $i['ORDEM']] = $i['COLUNA'];
        $idx[$t][$i['INDICE']]['unico'] = (bool) $i['UNICO'];
        $idx[$t][$i['INDICE']]['pk'] = (bool) $i['PK'];
        $idx[$t][$i['INDICE']][(int) $i['INCLUIDA'] ? 'incluidas' : 'colunas'][] = $i['COLUNA'];
    }
    foreach ($tab as $t => &$x) {
        ksort($x['colunas']);
        $x['colunas'] = array_values($x['colunas']);
        if (isset($pks[$t])) { ksort($pks[$t]); $x['pk'] = array_values($pks[$t]); $pks[$t] = $x['pk']; }
        foreach ($idx[$t] ?? [] as $n => $i) $x['indices'][] = ['nome' => $n] + $i;
    }
    unset($x);
    $decl = array_map(function ($f) { return ['tabela' => $f['TABELA'], 'coluna' => $f['COLUNA'], 'refere' => $f['REFERE'], 'coluna_referida' => $f['COLUNA_REFERIDA'],
        'origem' => 'declarada', 'nome' => $f['RELACAO']]; }, (array) $r['chaves_estrangeiras']);
    foreach ((array) $r['utilizacao'] as $u) {
        $t = $u['ESQUEMA'] . '.' . $u['TABELA'];
        if (isset($tab[$t])) $tab[$t]['ultima_utilizacao'] = array_filter(['leitura' => $u['ULTIMA_LEITURA'], 'varrimento' => $u['ULTIMO_VARRIMENTO'], 'escrita' => $u['ULTIMA_ESCRITA']]);
    }
    foreach ((array) $r['vistas'] as $v) {
        $t = $v['ESQUEMA'] . '.' . $v['VISTA'];
        if (isset($tab[$t])) $tab[$t]['definicao'] = (string) $v['DEFINICAO'];
    }
    ksort($tab);
    $mapa['bases'][$chave] = ['identificacao' => $r['identificacao'][0] ?? null, 'tabelas' => count(array_filter($tab, function ($x) { return $x['tipo'] === 'tabela'; })),
        'vistas' => count(array_filter($tab, function ($x) { return $x['tipo'] === 'vista'; })), 'utilizacao_disponivel' => $r['utilizacao'] !== null,
        'relacoes' => array_merge($decl, catalogo_inferir($tab, $pks, $decl)), 'objectos' => $tab];
}
if (!is_dir(dirname($saida))) mkdir(dirname($saida), 0775, true);
file_put_contents($saida, json_encode($mapa, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo "Gravado: $saida (" . round(filesize($saida) / 1024) . " KB). Nada foi alterado nas bases de dados.\n";
