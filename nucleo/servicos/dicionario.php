<?php
/**
 * Dicionário de dados da base de dados do Pinguim BO (estrutura, sem dados): tabelas e vistas, colunas,
 * chaves primárias, relações (chaves estrangeiras) e índices, lidos do catálogo do SQL Server só com
 * SELECT (vistas sys.* e INFORMATION_SCHEMA). Não lê nenhuma linha das tabelas de negócio.
 */
defined('NAVAL_NUCLEO') || exit;
require_once __DIR__ . '/bd.php';

/** Tipo legível ao estilo do SQL Server: nvarchar(50), decimal(18,4), varchar(max), int. */
function naval_dic_tipo(string $tipo, int $bytes, int $precisao, int $escala): string
{
    $t = strtolower($tipo);
    if (in_array($t, ['varchar', 'char', 'varbinary', 'binary'], true)) return $t . '(' . ($bytes === -1 ? 'max' : $bytes) . ')';
    if (in_array($t, ['nvarchar', 'nchar'], true)) return $t . '(' . ($bytes === -1 ? 'max' : intdiv($bytes, 2)) . ')';
    if (in_array($t, ['decimal', 'numeric'], true)) return $t . '(' . $precisao . ',' . $escala . ')';
    if (in_array($t, ['datetime2', 'time', 'datetimeoffset'], true)) return $t . '(' . $escala . ')';
    return $t;
}

/** Estrutura completa (SQL Server). Sem SQL Server (catálogo sys.* indisponível): INFORMATION_SCHEMA. */
function naval_bd_dicionario(): array
{
    $base = naval_config('DB_NAME');
    try {
        $versao = naval_bd_consultar('SELECT CAST(SERVERPROPERTY(\'ProductVersion\') AS VARCHAR(40)) AS VERSAO, CAST(SERVERPROPERTY(\'Edition\') AS VARCHAR(80)) AS EDICAO');
        $objectos = naval_bd_consultar("
            SELECT S.name AS ESQUEMA, O.name AS OBJECTO, O.type AS TIPO,
                   (SELECT SUM(P.rows) FROM sys.partitions P WHERE P.object_id = O.object_id AND P.index_id IN (0, 1)) AS LINHAS,
                   CONVERT(VARCHAR(19), O.create_date, 120) AS CRIADO, CONVERT(VARCHAR(19), O.modify_date, 120) AS ALTERADO,
                   CAST(EP.value AS NVARCHAR(4000)) AS DESCRICAO
            FROM sys.objects O
            INNER JOIN sys.schemas S ON S.schema_id = O.schema_id
            LEFT JOIN sys.extended_properties EP ON EP.class = 1 AND EP.major_id = O.object_id AND EP.minor_id = 0 AND EP.name = 'MS_Description'
            WHERE O.type IN ('U', 'V') AND O.is_ms_shipped = 0
            ORDER BY S.name, O.name");
        $colunas = naval_bd_consultar("
            SELECT S.name AS ESQUEMA, O.name AS OBJECTO, C.column_id AS ORDEM, C.name AS COLUNA, TY.name AS TIPO,
                   C.max_length AS BYTES, C.precision AS PRECISAO, C.scale AS ESCALA, C.is_nullable AS NULO,
                   C.is_identity AS IDENTIDADE, C.is_computed AS CALCULADA, DF.definition AS OMISSAO,
                   CASE WHEN PK.column_id IS NULL THEN 0 ELSE 1 END AS CHAVE_PRIMARIA,
                   CAST(EP.value AS NVARCHAR(4000)) AS DESCRICAO
            FROM sys.columns C
            INNER JOIN sys.objects O ON O.object_id = C.object_id
            INNER JOIN sys.schemas S ON S.schema_id = O.schema_id
            INNER JOIN sys.types TY ON TY.user_type_id = C.user_type_id
            LEFT JOIN sys.default_constraints DF ON DF.object_id = C.default_object_id
            LEFT JOIN (SELECT IC.object_id, IC.column_id FROM sys.index_columns IC
                       INNER JOIN sys.indexes I ON I.object_id = IC.object_id AND I.index_id = IC.index_id
                       WHERE I.is_primary_key = 1) PK ON PK.object_id = C.object_id AND PK.column_id = C.column_id
            LEFT JOIN sys.extended_properties EP ON EP.class = 1 AND EP.major_id = C.object_id AND EP.minor_id = C.column_id AND EP.name = 'MS_Description'
            WHERE O.type IN ('U', 'V') AND O.is_ms_shipped = 0
            ORDER BY S.name, O.name, C.column_id");
        $fks = naval_bd_consultar("
            SELECT FK.name AS RELACAO, SP.name AS ESQUEMA, TP.name AS TABELA, CP.name AS COLUNA,
                   SR.name AS ESQUEMA_REF, TR.name AS TABELA_REF, CR.name AS COLUNA_REF,
                   FK.delete_referential_action_desc AS AO_APAGAR, FK.update_referential_action_desc AS AO_ALTERAR,
                   FKC.constraint_column_id AS ORDEM
            FROM sys.foreign_keys FK
            INNER JOIN sys.foreign_key_columns FKC ON FKC.constraint_object_id = FK.object_id
            INNER JOIN sys.tables TP ON TP.object_id = FKC.parent_object_id
            INNER JOIN sys.schemas SP ON SP.schema_id = TP.schema_id
            INNER JOIN sys.columns CP ON CP.object_id = FKC.parent_object_id AND CP.column_id = FKC.parent_column_id
            INNER JOIN sys.tables TR ON TR.object_id = FKC.referenced_object_id
            INNER JOIN sys.schemas SR ON SR.schema_id = TR.schema_id
            INNER JOIN sys.columns CR ON CR.object_id = FKC.referenced_object_id AND CR.column_id = FKC.referenced_column_id
            ORDER BY TP.name, FK.name, FKC.constraint_column_id");
        $idx = naval_bd_consultar("
            SELECT S.name AS ESQUEMA, T.name AS TABELA, I.name AS INDICE, I.type_desc AS TIPO, I.is_unique AS UNICO,
                   I.is_primary_key AS CHAVE_PRIMARIA, C.name AS COLUNA, IC.key_ordinal AS ORDEM,
                   IC.is_descending_key AS DESCENDENTE, IC.is_included_column AS INCLUIDA
            FROM sys.indexes I
            INNER JOIN sys.tables T ON T.object_id = I.object_id
            INNER JOIN sys.schemas S ON S.schema_id = T.schema_id
            INNER JOIN sys.index_columns IC ON IC.object_id = I.object_id AND IC.index_id = I.index_id
            INNER JOIN sys.columns C ON C.object_id = IC.object_id AND C.column_id = IC.column_id
            WHERE I.index_id > 0 AND T.is_ms_shipped = 0
            ORDER BY S.name, T.name, I.name, IC.is_included_column, IC.key_ordinal");
        return naval_dic_montar($base, 'Microsoft SQL Server ' . ($versao[0]['VERSAO'] ?? '') . ' ' . ($versao[0]['EDICAO'] ?? ''), $objectos, $colunas, $fks, $idx);
    } catch (PDOException $e) {
        // Outra base de dados (ou sem acesso ao catálogo sys.*): estrutura normalizada ISO
        $objectos = naval_bd_consultar("
            SELECT TABLE_SCHEMA AS ESQUEMA, TABLE_NAME AS OBJECTO, CASE WHEN TABLE_TYPE = 'VIEW' THEN 'V' ELSE 'U' END AS TIPO
            FROM INFORMATION_SCHEMA.TABLES ORDER BY TABLE_SCHEMA, TABLE_NAME");
        $colunas = naval_bd_consultar("
            SELECT TABLE_SCHEMA AS ESQUEMA, TABLE_NAME AS OBJECTO, ORDINAL_POSITION AS ORDEM, COLUMN_NAME AS COLUNA, DATA_TYPE AS TIPO,
                   CHARACTER_MAXIMUM_LENGTH AS COMPRIMENTO, NUMERIC_PRECISION AS PRECISAO, NUMERIC_SCALE AS ESCALA,
                   CASE WHEN IS_NULLABLE = 'YES' THEN 1 ELSE 0 END AS NULO, COLUMN_DEFAULT AS OMISSAO
            FROM INFORMATION_SCHEMA.COLUMNS ORDER BY TABLE_SCHEMA, TABLE_NAME, ORDINAL_POSITION");
        return naval_dic_montar($base, 'INFORMATION_SCHEMA', $objectos, $colunas, [], []);
    }
}

/** Organiza as linhas do catálogo (separado para os testes). */
function naval_dic_montar(string $base, string $motor, array $objectos, array $colunas, array $fks, array $idx): array
{
    $chave = function ($e, $o) { return $e . '.' . $o; };
    $ncol = [];
    $pk = [];
    $cols = [];
    foreach ($colunas as $c) {
        $k = $chave($c['ESQUEMA'], $c['OBJECTO']);
        $ncol[$k] = ($ncol[$k] ?? 0) + 1;
        if (!empty($c['CHAVE_PRIMARIA'])) $pk[$k][] = (string) $c['COLUNA'];
        $tipo = isset($c['BYTES'])
            ? naval_dic_tipo((string) $c['TIPO'], (int) $c['BYTES'], (int) $c['PRECISAO'], (int) $c['ESCALA'])
            : strtolower((string) $c['TIPO']) . (isset($c['COMPRIMENTO']) ? '(' . ((int) $c['COMPRIMENTO'] === -1 ? 'max' : (int) $c['COMPRIMENTO']) . ')' : '');
        $cols[] = [
            'esquema' => (string) $c['ESQUEMA'], 'objecto' => (string) $c['OBJECTO'], 'ordem' => (int) $c['ORDEM'], 'coluna' => (string) $c['COLUNA'],
            'tipo' => $tipo, 'nulo' => !empty($c['NULO']), 'chave_primaria' => !empty($c['CHAVE_PRIMARIA']), 'identidade' => !empty($c['IDENTIDADE']),
            'calculada' => !empty($c['CALCULADA']), 'omissao' => $c['OMISSAO'] ?? null, 'descricao' => (string) ($c['DESCRICAO'] ?? ''),
            'chave_estrangeira' => '',
        ];
    }
    // Relações: uma linha por coluna; referência marcada também na coluna de origem
    $rel = [];
    $fkCol = [];
    foreach ($fks as $f) {
        $rel[] = [
            'relacao' => (string) $f['RELACAO'], 'esquema' => (string) $f['ESQUEMA'], 'tabela' => (string) $f['TABELA'], 'coluna' => (string) $f['COLUNA'],
            'tabela_ref' => $f['ESQUEMA_REF'] . '.' . $f['TABELA_REF'], 'coluna_ref' => (string) $f['COLUNA_REF'],
            'ao_apagar' => (string) $f['AO_APAGAR'], 'ao_alterar' => (string) $f['AO_ALTERAR'],
        ];
        $fkCol[$chave($f['ESQUEMA'], $f['TABELA']) . '.' . $f['COLUNA']] = $f['ESQUEMA_REF'] . '.' . $f['TABELA_REF'] . '.' . $f['COLUNA_REF'];
    }
    foreach ($cols as $i => $c) $cols[$i]['chave_estrangeira'] = $fkCol[$chave($c['esquema'], $c['objecto']) . '.' . $c['coluna']] ?? '';
    $nrel = [];
    foreach ($rel as $r) $nrel[$chave($r['esquema'], $r['tabela'])][$r['relacao']] = true;
    // Índices: uma linha por índice, com as colunas por ordem (e as incluídas à parte)
    $ind = [];
    foreach ($idx as $x) {
        $k = $x['ESQUEMA'] . '.' . $x['TABELA'] . '|' . $x['INDICE'];
        if (!isset($ind[$k])) {
            $ind[$k] = ['esquema' => (string) $x['ESQUEMA'], 'tabela' => (string) $x['TABELA'], 'indice' => (string) $x['INDICE'], 'tipo' => (string) $x['TIPO'],
                'unico' => !empty($x['UNICO']), 'chave_primaria' => !empty($x['CHAVE_PRIMARIA']), 'colunas' => [], 'incluidas' => []];
        }
        if (!empty($x['INCLUIDA'])) $ind[$k]['incluidas'][] = (string) $x['COLUNA'];
        else $ind[$k]['colunas'][] = $x['COLUNA'] . (!empty($x['DESCENDENTE']) ? ' DESC' : '');
    }
    $ind = array_values(array_map(function ($i) {
        $i['colunas'] = implode(', ', $i['colunas']);
        $i['incluidas'] = implode(', ', $i['incluidas']);
        return $i;
    }, $ind));
    $obj = [];
    foreach ($objectos as $o) {
        $k = $chave($o['ESQUEMA'], $o['OBJECTO']);
        $obj[] = [
            'esquema' => (string) $o['ESQUEMA'], 'objecto' => (string) $o['OBJECTO'], 'tipo' => trim((string) $o['TIPO']) === 'V' ? 'Vista' : 'Tabela',
            'linhas' => isset($o['LINHAS']) ? (int) $o['LINHAS'] : null, 'colunas' => $ncol[$k] ?? 0, 'chave_primaria' => implode(', ', $pk[$k] ?? []),
            'relacoes' => count($nrel[$k] ?? []), 'criado' => $o['CRIADO'] ?? null, 'alterado' => $o['ALTERADO'] ?? null, 'descricao' => (string) ($o['DESCRICAO'] ?? ''),
        ];
    }
    return ['base' => $base, 'motor' => trim($motor), 'objectos' => $obj, 'colunas' => $cols, 'relacoes' => $rel, 'indices' => $ind];
}
