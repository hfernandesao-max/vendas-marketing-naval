<?php
// Apresentação do portal: indicadores de negócio a partir dos agregados e contagem do que o portal fez sozinho.
naval_servico('apresentacao');

teste('Apresentação: vendas por mês, mês até hoje face ao mesmo período e clientes activos', function () {
    $d = function (float $t, int $docs, array $cli, string $rota = '1001') {
        $c = [];
        foreach ($cli as $k => $v) $c[$k] = ['n' => 'Loja ' . $k, 'r' => $rota, 't' => $v, 'd' => 1];
        return ['rotas' => [$rota => ['t' => $t, 'd' => $docs, 'nc' => 0]], 'clientes' => $c];
    };
    $ag = [
        '2026-06-10' => $d(100, 2, ['A' => 100]),
        '2026-10-02' => $d(1000, 4, ['A' => 600, 'B' => 400]),
        '2026-10-20' => $d(5000, 9, ['C' => 5000]),   // depois do dia 3: fora do mesmo período do mês anterior
        '2026-11-02' => $d(800, 3, ['A' => 800], '1002'),
        '2026-11-03' => $d(400, 1, ['B' => 400]),
    ];
    $r = naval_apx_negocio($ag, '2026-11-03');
    igual(['2026-06', '2026-07', '2026-08', '2026-09', '2026-10', '2026-11'], array_column($r['meses'], 'mes'));
    igual(6000.0, $r['meses'][4]['total']);
    igual(3, $r['meses'][4]['clientes']);
    verdade($r['meses'][5]['parcial'] && !$r['meses'][4]['parcial'], 'só o mês actual é parcial');
    igual(1200.0, $r['mes']['total']);
    igual(1000.0, $r['mes']['anterior']);
    igual(0.2, $r['mes']['variacao']);
    igual(['documentos' => 4, 'clientes' => 2, 'rotas' => 2, 'valor_medio' => 600.0], array_intersect_key($r['mes'], array_flip(['documentos', 'clientes', 'rotas', 'valor_medio'])));
    igual(3, $r['clientes_30'], 'A, B e C nos últimos 30 dias');
    igual(5, $r['dias_com_dados']);
    igual('2026-06-10', $r['desde']);
    igual(null, naval_apx_negocio([], '2026-11-03')['mes']['variacao'], 'sem mês anterior: sem variação');
});

teste('Apresentação: conta só os ficheiros da rotina dos últimos dias e sem erro', function () {
    $p = sys_get_temp_dir() . '/naval-apx-' . getmypid();
    @mkdir($p);
    file_put_contents($p . '/2026-11-03.json', '{"ok":1}');
    file_put_contents($p . '/2026-11-02.json', '{"erro":"falhou"}');
    file_put_contents($p . '/2026-09-01.json', '{"ok":1}');
    file_put_contents($p . '/plano-2026-11-01.json', '{}');
    igual(1, naval_apx_contar($p, '', '2026-11-03', 30, function ($x) { return empty($x['erro']); }));
    igual(2, naval_apx_contar($p, '', '2026-11-03', 30));
    igual(1, naval_apx_contar($p, 'plano-', '2026-11-03', 30));
    foreach (glob($p . '/*') ?: [] as $f) unlink($f);
    rmdir($p);
});
