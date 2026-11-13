<?php
// Base de dados do Pinguim BO: o portal só pode ler (SELECT).
naval_servico('bd');
naval_servico('stock');

teste('só leitura: SELECT e WITH aceites, com comentários e textos', function () {
    naval_bd_so_leitura('SELECT 1 AS OK');
    naval_bd_so_leitura("  -- comentário\n  select DH.ID from DOCHEADER DH where DH.DOC_DATE >= :de;");
    naval_bd_so_leitura("WITH X AS (SELECT ID FROM STORE) SELECT * FROM X");
    naval_bd_so_leitura("SELECT 'INSERT INTO; DROP TABLE' AS texto, [UPDATE] FROM T");   // palavras dentro de textos e nomes entre parênteses rectos
    naval_bd_so_leitura("SELECT * FROM WAREHOUSE W CROSS APPLY dbo.GET_STOCK(:d, P.ID, W.ENTITY_ID, 1, NULL, 1, 1) STK");
    verdade(true);
});

teste('só leitura: escrita, execução e várias instruções recusadas', function () {
    $recusadas = [
        'INSERT INTO T VALUES (1)', 'UPDATE DOCHEADER SET PAID = 1', 'DELETE FROM STORE', 'DROP TABLE X', 'EXEC sp_who', 'TRUNCATE TABLE X',
        'SELECT * INTO COPIA FROM DOCHEADER', 'SELECT 1; DELETE FROM STORE', "SELECT 1 /* */; UPDATE T SET A = 1", 'MERGE T USING S ON 1=1 WHEN MATCHED THEN DELETE;',
        'WITH X AS (SELECT 1 A) DELETE FROM X', 'SELECT * FROM OPENROWSET(BULK \'c:\\x\', SINGLE_CLOB) A', 'SELECT xp_cmdshell', 'DECLARE @a INT', 'SET NOCOUNT ON',
        'GRANT SELECT ON T TO U', 'select 1 waitfor delay \'00:00:05\'',
    ];
    foreach ($recusadas as $sql) {
        $ok = false;
        try {
            naval_bd_so_leitura($sql);
        } catch (RuntimeException $e) {
            $ok = true;
        }
        verdade($ok, 'devia recusar: ' . $sql);
    }
});

teste('sem configuração da base de dados: vendas e stock usam as alternativas', function () {
    verdade(!naval_bd_configurada());
    igual(null, naval_vendas_sql('20261024', '20261024', [], true));
    igual(null, naval_stock_sql('2026-10-24'));
    igual('2026-10-24', naval_bd_data('20261024'));
    igual('2026-10-24', naval_bd_data('2026-10-24'));
});

teste('stock: carrinhas (100010NN) separadas dos armazéns centrais; âmbito por rota', function () {
    $rows = [
        ['COD_ARMAZEM' => '10001001', 'ARMAZEM' => 'Armazém 1001 - FHR PIV', 'CODIGO_PRODUTO' => 'A1', 'PRODUTO' => 'Fraldas', 'QUANTIDADE' => 5],
        ['COD_ARMAZEM' => '10001001', 'ARMAZEM' => 'Armazém 1001 - FHR PIV', 'CODIGO_PRODUTO' => 'A2', 'PRODUTO' => 'Sabão', 'QUANTIDADE' => 3],
        ['COD_ARMAZEM' => '10001002', 'ARMAZEM' => 'Armazém 1002 - FHR PIV', 'CODIGO_PRODUTO' => 'A1', 'PRODUTO' => 'Fraldas', 'QUANTIDADE' => 2],
        ['COD_ARMAZEM' => 'MR01', 'ARMAZEM' => 'HIGITEC Central', 'CODIGO_PRODUTO' => 'A1', 'PRODUTO' => 'Fraldas', 'QUANTIDADE' => 100],
    ];
    $d = naval_stock_organizar($rows, '2026-10-24');
    igual(['1001', '1002'], array_column($d['carrinhas'], 'rota'));
    igual(8.0, $d['carrinhas'][0]['total_quantidade']);
    igual('HIGITEC', $d['armazens'][0]['centro']);
    $sup = naval_stock_ambito($d, ['1001'], false);
    igual(['1001'], array_column($sup['carrinhas'], 'rota'));
    igual([], $sup['armazens']);
    igual(['1001', '1001'], array_column($sup['linhas_carrinhas'], 'rota'));
});

teste('stock: consulta só de leitura e só com as colunas que existem nesta base de dados', function () {
    $todas = ['WAREHOUSE' => ['ENTITY_ID' => true, 'DELETED' => true], 'ENTITY' => ['DELETED' => true], 'PRODUCT' => ['DELETED' => true],
        'DOCMOV' => ['DELETED' => true], 'INVENTORYDETAIL' => ['CCH_WAREHOUSE_ID' => true, 'PRODUCT_ID' => true, 'DELETED' => true],
        'CCH_PRODUCTSTOCK' => ['WAREHOUSE_ID' => true, 'PRODUCT_ID' => true]];
    foreach (['todos', '1001'] as $arm) {
        [$sql, $p] = naval_stock_consulta('2026-10-24', $arm, $todas);
        naval_bd_so_leitura($sql);
        igual('2026-10-24 23:59:59', $p[':data_fim_com_hora']);   // formato canónico do ODBC (sem «T»)
        foreach (['FROM WAREHOUSE W', 'W.DELETED = 0', 'E.DELETED = 0', 'FROM DOCMOV MV', 'FROM INVENTORYDETAIL ID', 'FROM CCH_PRODUCTSTOCK PS', 'P.DELETED = 0', 'dbo.GET_STOCK(:data_fim_com_hora'] as $x) {
            verdade(strpos($sql, $x) !== false, $arm . ': falta ' . $x);
        }
        foreach (array_keys($p) as $k) igual(1, preg_match_all('/' . preg_quote($k, '/') . '\b/', $sql), $k);
    }
    // base de dados sem colunas DELETED nem tabelas de inventário: nenhuma coluna inexistente na consulta
    [$sql] = naval_stock_consulta('2026-10-24', 'todos', ['DOCMOV' => ['DELETED' => true]]);
    naval_bd_so_leitura($sql);
    foreach (['W.DELETED', 'E.DELETED', 'P.DELETED', 'INVENTORYDETAIL', 'CCH_PRODUCTSTOCK', 'FROM WAREHOUSE'] as $x) verdade(strpos($sql, $x) === false, 'não devia ter ' . $x);
    verdade(strpos($sql, 'MV.DELETED = 0') !== false);
    igual('10001001', naval_stock_consulta('2026-10-24', '1001', [])[1][':armazem_carrinha']);
});

teste('stock: armazém da carrinha confirmado — só esse conta; outro com «10NN» no nome fica nos armazéns', function () {
    @unlink(naval_pasta('stock') . '/carrinhas.json');
    $rows = [
        ['COD_ARMAZEM' => '10001011', 'ARMAZEM' => 'Armazém 1011 - FHR PIV', 'CODIGO_PRODUTO' => 'A1', 'PRODUTO' => 'Fraldas', 'QUANTIDADE' => 5],
        ['COD_ARMAZEM' => 'MR11', 'ARMAZEM' => 'Depósito 1011 antigo', 'CODIGO_PRODUTO' => 'A2', 'PRODUTO' => 'Sabão', 'QUANTIDADE' => 7],
    ];
    // sem confirmação: os dois juntos na carrinha 1011, com a origem de cada um e o aviso «por confirmar»
    $d = naval_stock_organizar($rows, '2026-11-12');
    igual(1, count($d['carrinhas']));
    igual(false, $d['carrinhas'][0]['confirmado']);
    igual(['codigo', 'nome'], array_column($d['carrinhas'][0]['armazens_origem'], 'como'));
    igual(12.0, $d['carrinhas'][0]['total_quantidade']);
    // confirmado o 10001011: o outro passa para os armazéns
    naval_stock_confirmar_carrinha('1011', '10001011', 'Armazém 1011 - FHR PIV', 'teste');
    $d = naval_stock_organizar($rows, '2026-11-12');
    igual(true, $d['carrinhas'][0]['confirmado']);
    igual(5.0, $d['carrinhas'][0]['total_quantidade']);
    igual(['MR11'], array_column($d['armazens'], 'cod_armazem'));
    // a consulta de uma rota confirmada usa só o código, sem procurar no nome
    [$sql, $p] = naval_stock_consulta('2026-11-12', '1011', []);
    igual('10001011', $p[':armazem_carrinha']);
    verdade(!isset($p[':armazem_like']), 'sem LIKE no nome');
    [$sql, $p] = naval_stock_consulta('2026-11-12', 'todos', []);
    igual('10001011', $p[':conf0']);
    naval_bd_so_leitura($sql);
    // o mesmo armazém não pode ser de duas rotas; código vazio retira a confirmação
    $erro = '';
    try { naval_stock_confirmar_carrinha('1012', '10001011', '', 'teste'); } catch (NavalErro $e) { $erro = $e->getMessage(); }
    verdade(strpos($erro, 'rota 1011') !== false, $erro);
    igual([], naval_stock_confirmar_carrinha('1011', '', '', 'teste'));
    @unlink(naval_pasta('stock') . '/carrinhas.json');
});

teste('texto da base de dados em UTF-8: Windows-1252 do ODBC convertido, UTF-8 mantido', function () {
    igual('Armazém 1002 - FHR PIV', naval_bd_utf8("Armaz\xE9m 1002 - FHR PIV"));
    igual('SABÃO VERDE CONFIANÇA', naval_bd_utf8("SAB\xC3O VERDE CONFIAN\xC7A"));
    igual('FRALDAS DE BEBÉ', naval_bd_utf8('FRALDAS DE BEBÉ'));
    igual('10000333', naval_bd_utf8('10000333'));
});

teste('texto enviado pelo ODBC em Windows-1252 (acentos nas consultas e parâmetros)', function () {
    igual("9999-GRUPO NAVAL FORMA\xC7\xC3O", naval_bd_para_odbc('9999-GRUPO NAVAL FORMAÇÃO'));
    igual('SELECT 1', naval_bd_para_odbc('SELECT 1'));
    igual('9999-GRUPO NAVAL FORMAÇÃO', naval_bd_utf8(naval_bd_para_odbc('9999-GRUPO NAVAL FORMAÇÃO')));
});

teste('ligação: DSN de referência (cifra, certificado validado, só leitura, nome da aplicação, tempos)', function () {
    $c = ['host' => 'srv-sql', 'port' => '1433', 'nome' => 'PingWinBO', 'timeout' => '5', 'app' => 'PortalRTM'];
    $d18 = naval_bd_dsn($c, 'ODBC Driver 18 for SQL Server', true, true);
    igual('odbc:Driver={ODBC Driver 18 for SQL Server};Server=srv-sql,1433;Database=PingWinBO;Encrypt=yes;TrustServerCertificate=no;LoginTimeout=5;APP=PortalRTM;ApplicationIntent=ReadOnly;', $d18);
    verdade(strpos(naval_bd_dsn($c, 'ODBC Driver 17 for SQL Server', true, false), 'TrustServerCertificate=yes') !== false);
    // o controlador antigo não conhece ApplicationIntent
    verdade(strpos(naval_bd_dsn($c, 'SQL Server', true, true), 'ApplicationIntent') === false);
    $s = naval_bd_dsn($c + [], 'sqlsrv', true, true);
    verdade(strpos($s, 'sqlsrv:Server=srv-sql,1433;Database=PingWinBO;Encrypt=true;TrustServerCertificate=false') === 0);
    verdade(strpos($s, 'ApplicationIntent=ReadOnly') !== false && strpos($s, 'APP=PortalRTM') !== false);
    igual('ODBC Driver 18 for SQL Server', NAVAL_BD_ODBC[0]);
});

teste('ligação: controlos de conformidade (certificado não validado e controlador antigo ficam fora da norma)', function () {
    $GLOBALS['naval_bd_ligacao']['TESTE'] = ['fonte' => 'TESTE', 'cifra' => true, 'app' => 'PortalRTM', 'tempo_ligacao' => 5, 'tempo_consulta' => 30,
        'controlador' => 'ODBC Driver 18 for SQL Server', 'certificado_validado' => true, 'intencao' => true];
    $r = naval_bd_conformidade('TESTE');
    verdade($r['conforme'] === true);
    $GLOBALS['naval_bd_ligacao']['TESTE']['certificado_validado'] = false;
    $GLOBALS['naval_bd_ligacao']['TESTE']['controlador'] = 'SQL Server';
    $r = naval_bd_conformidade('TESTE');
    verdade($r['conforme'] === false);
    $fora = array_column(array_filter($r['controlos'], function ($x) { return !$x['ok']; }), 'id');
    igual(['controlador', 'certificado', 'intencao'], array_values($fora));
    unset($GLOBALS['naval_bd_ligacao']['TESTE']);
    verdade(naval_bd_erro_controlador(new RuntimeException('SQLSTATE[IM002] [Microsoft][ODBC Driver Manager] Data source name not found')));
    verdade(!naval_bd_erro_controlador(new RuntimeException('SSL Provider: The certificate chain was issued by an authority that is not trusted')));
});
