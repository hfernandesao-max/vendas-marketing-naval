<?php
// Carteira de clientes (ritmo, encomenda, venda cruzada, risco, visitas, anomalias), fecho do dia e terreno (OLAP).
naval_servico('carteira');
naval_servico('operacao');
naval_servico('olap');

/** Agregados de teste: cliente A compra fraldas e sabão de 7 em 7 dias; B de 14 em 14, parado; C só sabonete. */
function cart_agregados(): array
{
    $ag = [];
    $dia = function (string $d, string $c, array $art, float $t, string $r = '1001') use (&$ag) {
        $ag[$d]['clientes'][$c] = ['n' => 'Loja ' . $c, 'r' => $r, 't' => $t, 'd' => 1];
        $ag[$d]['ca'][$c] = $art;
        foreach ($art as $cod => $q) $ag[$d]['artigos'][$r][$cod] = ['n' => ['FR' => 'Fraldas', 'SB' => 'Sabão', 'SN' => 'Sabonete'][$cod], 'q' => $q, 't' => 1];
    };
    foreach (['2026-10-06', '2026-10-13', '2026-10-20', '2026-10-27', '2026-11-02'] as $i => $d) $dia($d, 'A', ['FR' => 10 + $i, 'SB' => 5], 50000);
    foreach (['2026-09-01', '2026-09-15', '2026-09-29'] as $d) $dia($d, 'B', ['FR' => 4, 'SN' => 2], 20000);
    foreach (['2026-10-07', '2026-10-21', '2026-11-03'] as $d) $dia($d, 'C', ['SN' => 3], 9000);
    ksort($ag);
    return $ag;
}

teste('Carteira: ritmo próprio (em dia, a abrandar, parado) e próxima compra', function () {
    $r = naval_cart_ritmo(['2026-10-06', '2026-10-13', '2026-10-20', '2026-10-27'], '2026-11-03');
    igual(['intervalo' => 7.0, 'dias' => 7, 'estado' => 'em_dia', 'proxima' => '2026-11-03'], array_intersect_key($r, array_flip(['intervalo', 'dias', 'estado', 'proxima'])));
    igual('a_abrandar', naval_cart_ritmo(['2026-10-06', '2026-10-13', '2026-10-20'], '2026-11-02')['estado']);
    igual('parado', naval_cart_ritmo(['2026-09-01', '2026-09-15', '2026-09-29'], '2026-11-03')['estado']);
    igual(null, naval_cart_ritmo(['2026-10-01', '2026-10-02'], '2026-11-03'), 'menos de 3 compras: sem ritmo');
});

teste('Carteira: encomenda sugerida, a propor (venda cruzada) e visitas do dia', function () {
    $c = naval_cart_calcular(cart_agregados(), [], '2026-11-03')['clientes'];
    igual(['FR', 'SB'], array_column($c['A']['encomenda'], 'cod'));
    igual(12.0, $c['A']['encomenda'][0]['q'], 'mediana das últimas compras');
    igual(['SN'], array_column($c['A']['propor'], 'cod'), 'sabonete: 2 de 3 clientes da rota compram');
    igual('parado', $c['B']['estado']);
    igual(2, $c['A']['dia'], 'dia habitual: segunda (1) ou terça (2) pela contagem');
    $v = naval_cart_visitas(['clientes' => $c], '2026-11-03', null);
    igual('B', $v['1001'][0]['c'], 'o parado primeiro');
    igual([], naval_cart_visitas(['clientes' => $c], '2026-11-03', ['1002']), 'só as suas rotas');
});

teste('Carteira: risco de crédito pelas facturas em aberto', function () {
    $v = [
        ['cliente_codigo' => 'A', 'data' => '2026-09-20', 'total' => 100000, 'valor_pago' => 40000],
        ['cliente_codigo' => 'B', 'data' => '2026-10-14', 'total' => 10000, 'valor_pago' => 0],
        ['cliente_codigo' => 'C', 'data' => '2026-11-01', 'total' => 10000, 'valor_pago' => 10000],
        ['cliente_codigo' => 'C', 'data' => '2026-11-01', 'total' => -500, 'nc' => true],
    ];
    $r = naval_cart_risco($v, '2026-11-03');
    igual(['risco' => 'alto', 'aberto' => 60000.0, 'dias' => 44], $r['A']);
    igual('medio', $r['B']['risco']);
    igual('baixo', $r['C']['risco']);
});

teste('Anomalias: sem meio, notas de crédito altas, documentos repetidos e desconto fora do habitual', function () {
    $v = [];
    for ($i = 1; $i <= 3; $i++) $v[] = ['cliente_codigo' => 'X', 'cliente' => 'Loja X', 'rota' => '1001', 'documento' => 'FR ' . $i, 'data' => '2026-11-02', 'total' => 1000, 'pagamentos' => []];
    $v[] = ['cliente_codigo' => 'Y', 'cliente' => 'Loja Y', 'rota' => '1001', 'documento' => 'FR 9', 'data' => '2026-11-02', 'total' => 1000, 'pagamentos' => [['codigo' => '?', 'meio' => 'Sem meio indicado', 'valor' => 1000]]];
    $v[] = ['cliente_codigo' => 'Y', 'cliente' => 'Loja Y', 'rota' => '1001', 'documento' => 'NC 1', 'data' => '2026-11-02', 'total' => -500, 'nc' => true, 'pagamentos' => []];
    $a = [];
    foreach (range(1, 5) as $i) $a[] = ['codigo' => 'FR', 'artigo' => 'Fraldas', 'rota' => '1001', 'documento' => 'FR ' . $i, 'data' => '2026-11-02', 'valor_sem_iva' => 980, 'desconto' => 20];
    $a[] = ['codigo' => 'FR', 'artigo' => 'Fraldas', 'rota' => '1001', 'documento' => 'FR 7', 'data' => '2026-11-02', 'valor_sem_iva' => 700, 'desconto' => 300];
    $t = array_column(naval_cart_anomalias($v, $a), 'tipo');
    sort($t);
    igual(['desconto', 'notas_credito', 'repetidos', 'sem_meio'], $t);
});

teste('Plano de cargas: rotas que costumam sair nesse dia da semana; fecho do dia por rota sem a compensação', function () {
    igual(['1001', '1002'], naval_op_rotas_do_dia('2099-01-05', [['rota' => '1001'], ['rota' => '1002']]), 'sem histórico: todas as sugeridas');
    $f = naval_op_fecho([
        ['rota' => '1001', 'promotor' => 'Ana', 'total' => 10000, 'valor_pago' => 10000, 'pagamentos' => [['codigo' => 'NU', 'meio' => 'Numerário', 'valor' => 4000], ['codigo' => 'MC', 'meio' => 'Multicaixa', 'valor' => 6000]]],
        ['rota' => '1001', 'promotor' => 'Ana', 'total' => 3000, 'valor_pago' => 0, 'pagamentos' => []],
        ['rota' => '1001', 'promotor' => 'Ana', 'total' => -1000, 'nc' => true, 'pagamentos' => [['codigo' => 'CMP', 'meio' => 'Compensação com nota de crédito', 'valor' => -1000]]],
    ]);
    igual(['docs' => 2, 'vendas' => 12000.0, 'nc' => 1000.0, 'recebido' => 10000.0, 'aberto' => 3000.0], array_intersect_key($f['1001'], array_flip(['docs', 'vendas', 'nc', 'recebido', 'aberto'])));
    igual(['Numerário' => 4000.0, 'Multicaixa' => 6000.0], $f['1001']['meios']);
    verdade(strpos(naval_op_fecho_html('2026-11-03', $f, ['1001' => 'aprovada']), 'Fecho de 03-11-2026') !== false);
});

teste('OLAP: contrato das colunas, validação e terreno do dia (paragens, prova de visita, tempos, fora da zona, coordenadas)', function () {
    $l = [
        ['DATA_HORA' => '2026-11-03 08:00:00', 'ROTA' => '1001', 'LATITUDE' => -8.80000, 'LONGITUDE' => 13.20000, 'PRECISAO' => 10],
        ['DATA_HORA' => '2026-11-03 08:05:00', 'ROTA' => '1001', 'LATITUDE' => -8.80010, 'LONGITUDE' => 13.20010, 'DOCUMENTO' => 'FR 1', 'CLIENTE' => 'A'],
        ['DATA_HORA' => '2026-11-03 08:20:00', 'ROTA' => '1001', 'LATITUDE' => -8.80020, 'LONGITUDE' => 13.20000],
        ['DATA_HORA' => '2026-11-03 09:00:00', 'ROTA' => '1001', 'LATITUDE' => -8.81000, 'LONGITUDE' => 13.21000, 'DOCUMENTO' => 'FR 2', 'CLIENTE' => 'B'],
        ['DATA_HORA' => '2026-11-03 09:30:00', 'ROTA' => '1001', 'LATITUDE' => -8.90000, 'LONGITUDE' => 13.30000],
        ['DATA_HORA' => '2026-11-03 09:40:00', 'ROTA' => '1001', 'LATITUDE' => 38.7, 'LONGITUDE' => -9.1],                 // fora de Angola
        ['DATA_HORA' => '2026-11-03 09:50:00', 'ROTA' => '1001', 'LATITUDE' => -8.8, 'LONGITUDE' => 13.2, 'PRECISAO' => 900], // imprecisa
        ['ROTA' => '1001', 'LATITUDE' => -8.8, 'LONGITUDE' => 13.2],                                                       // sem hora
    ];
    $p = naval_olap_normalizar($l);
    igual(5, count($p));
    igual('venda', $p[1]['tipo']);
    $cl = [['c' => 'A', 'n' => 'Loja A', 'lat' => -8.80005, 'lng' => 13.20005, 'r' => '1001'], ['c' => 'B', 'n' => 'Loja B', 'lat' => -8.82, 'lng' => 13.22, 'r' => '1001']];
    $terr = ['1001' => [[-8.79, 13.19], [-8.79, 13.23], [-8.83, 13.23], [-8.83, 13.19]]];
    $t = naval_olap_terreno($p, $cl, $terr, 30);
    $r = $t['rotas'][0];
    igual(['ok', 'longe'], array_column($r['visitas'], 'prova'));
    igual(1, $r['vendas_longe']);
    igual(1, $r['pontos_fora'], 'o ponto das 09:30 fora do território');
    igual(2, $r['clientes']);
    igual('08:00', $r['inicio']);
    igual(20, $r['paragens'][0]['min'], 'primeira paragem 08:00–08:20');
    igual('Loja A', $r['paragens'][0]['cliente']);
    // três vendas no mesmo sítio, longe do registo: coordenadas sugeridas
    $v = [];
    foreach (['10:00', '11:00', '12:00'] as $h) $v[] = ['t' => '2026-11-03 ' . $h . ':00', 'r' => '1001', 'lat' => -8.8505, 'lng' => 13.2505, 'p' => 5, 'doc' => 'FR', 'cli' => 'B', 'tipo' => 'venda'];
    $s = naval_olap_terreno($v, $cl, [], 30)['coordenadas'];
    igual('B', $s[0]['c']);
    verdade($s[0]['dist'] > 50);
});
