<?php
// Estrutura de dados: tipos legíveis, montagem do catálogo e consultas só de leitura.
naval_servico('dicionario');

teste('estrutura de dados: tipos ao estilo do SQL Server', function () {
    igual('nvarchar(50)', naval_dic_tipo('nvarchar', 100, 0, 0));
    igual('varchar(max)', naval_dic_tipo('varchar', -1, 0, 0));
    igual('decimal(18,4)', naval_dic_tipo('decimal', 9, 18, 4));
    igual('datetime2(7)', naval_dic_tipo('datetime2', 8, 27, 7));
    igual('int', naval_dic_tipo('INT', 4, 10, 0));
});

teste('estrutura de dados: chaves primárias, relações por coluna e índices agrupados', function () {
    $d = naval_dic_montar('PingWinBO', 'Microsoft SQL Server 16.0',
        [['ESQUEMA' => 'dbo', 'OBJECTO' => 'DOCHEADER', 'TIPO' => 'U ', 'LINHAS' => '10'], ['ESQUEMA' => 'dbo', 'OBJECTO' => 'STORE', 'TIPO' => 'U', 'LINHAS' => 2],
         ['ESQUEMA' => 'dbo', 'OBJECTO' => 'V_VENDAS', 'TIPO' => 'V', 'LINHAS' => null]],
        [['ESQUEMA' => 'dbo', 'OBJECTO' => 'DOCHEADER', 'ORDEM' => 1, 'COLUNA' => 'ID', 'TIPO' => 'int', 'BYTES' => 4, 'PRECISAO' => 10, 'ESCALA' => 0, 'NULO' => 0, 'IDENTIDADE' => 1, 'CALCULADA' => 0, 'CHAVE_PRIMARIA' => 1],
         ['ESQUEMA' => 'dbo', 'OBJECTO' => 'DOCHEADER', 'ORDEM' => 2, 'COLUNA' => 'STORE_ID', 'TIPO' => 'int', 'BYTES' => 4, 'PRECISAO' => 10, 'ESCALA' => 0, 'NULO' => 0, 'IDENTIDADE' => 0, 'CALCULADA' => 0, 'CHAVE_PRIMARIA' => 0],
         ['ESQUEMA' => 'dbo', 'OBJECTO' => 'STORE', 'ORDEM' => 1, 'COLUNA' => 'ID', 'TIPO' => 'int', 'BYTES' => 4, 'PRECISAO' => 10, 'ESCALA' => 0, 'NULO' => 0, 'IDENTIDADE' => 1, 'CALCULADA' => 0, 'CHAVE_PRIMARIA' => 1]],
        [['RELACAO' => 'FK_DOC_STORE', 'ESQUEMA' => 'dbo', 'TABELA' => 'DOCHEADER', 'COLUNA' => 'STORE_ID', 'ESQUEMA_REF' => 'dbo', 'TABELA_REF' => 'STORE', 'COLUNA_REF' => 'ID',
          'AO_APAGAR' => 'NO_ACTION', 'AO_ALTERAR' => 'NO_ACTION', 'ORDEM' => 1]],
        [['ESQUEMA' => 'dbo', 'TABELA' => 'DOCHEADER', 'INDICE' => 'IX_DOC', 'TIPO' => 'NONCLUSTERED', 'UNICO' => 0, 'CHAVE_PRIMARIA' => 0, 'COLUNA' => 'STORE_ID', 'ORDEM' => 1, 'DESCENDENTE' => 1, 'INCLUIDA' => 0],
         ['ESQUEMA' => 'dbo', 'TABELA' => 'DOCHEADER', 'INDICE' => 'IX_DOC', 'TIPO' => 'NONCLUSTERED', 'UNICO' => 0, 'CHAVE_PRIMARIA' => 0, 'COLUNA' => 'ID', 'ORDEM' => 0, 'DESCENDENTE' => 0, 'INCLUIDA' => 1]]);
    igual(['Tabela', 'Tabela', 'Vista'], array_column($d['objectos'], 'tipo'));
    igual(10, $d['objectos'][0]['linhas']);
    igual(null, $d['objectos'][2]['linhas']);
    igual('ID', $d['objectos'][0]['chave_primaria']);
    igual(2, $d['objectos'][0]['colunas']);
    igual(1, $d['objectos'][0]['relacoes']);
    igual('dbo.STORE.ID', $d['colunas'][1]['chave_estrangeira']);
    igual('', $d['colunas'][0]['chave_estrangeira']);
    igual(['STORE_ID DESC', 'ID'], [$d['indices'][0]['colunas'], $d['indices'][0]['incluidas']]);
});

teste('estrutura de dados: todas as consultas ao catálogo passam a guarda de só leitura', function () {
    $src = (string) file_get_contents(dirname(__DIR__, 2) . '/nucleo/servicos/dicionario.php');
    preg_match_all('/naval_bd_consultar\((["\'])(.*?)\1\)/s', $src, $m);
    verdade(count($m[2]) >= 7, 'consultas encontradas: ' . count($m[2]));
    foreach ($m[2] as $sql) naval_bd_so_leitura(stripslashes($sql));
});
