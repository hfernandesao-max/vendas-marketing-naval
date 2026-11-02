<?php
// Camada de análise, alertas, previsão e resumo diário (nucleo/servicos/analise.php, alertas.php, previsao.php, resumo.php).
naval_servico('analise');
naval_servico('alertas');
naval_servico('previsao');
naval_servico('resumo');

/**
 * Histórico sintético: $dias dias até $fim; cada rota vende $base × perfil do dia da semana (domingo 0), com $f(dia, rota)
 * a alterar valores. Clientes: C<rota>-1 compra todas as segundas; C<rota>-2 comprava às quartas até $paraEm.
 */
function analise_historico(string $fim, int $dias, array $bases, ?callable $f = null, string $paraEm = '9999-12-31'): array
{
    $perfil = [1 => 1.0, 2 => 0.9, 3 => 1.1, 4 => 1.0, 5 => 1.2, 6 => 0.6, 7 => 0.0];
    $vendas = $artigos = [];
    for ($k = $dias - 1; $k >= 0; $k--) {
        $dia = date('Y-m-d', strtotime($fim . ' 12:00') - $k * 86400);
        $ds = (int) date('N', strtotime($dia . ' 12:00'));
        foreach ($bases as $rota => $base) {
            $v = $base * $perfil[$ds];
            if ($f) $v = $f($dia, (string) $rota, $v);
            if ($v <= 0) continue;
            $cli = $ds === 1 ? "C$rota-1" : ($ds === 3 && $dia < $paraEm ? "C$rota-2" : "C$rota-9");
            $vendas[] = ['data' => $dia, 'rota' => (string) $rota, 'total' => $v, 'nc' => false, 'cliente' => "Cliente $cli", 'cliente_codigo' => $cli];
            $artigos[] = ['data' => $dia, 'rota' => (string) $rota, 'codigo' => 'A1', 'artigo' => 'Fraldas', 'unidade' => 'CAR', 'quantidade' => $v / 1000, 'total' => $v];
        }
    }
    return ['vendas' => $vendas, 'artigos' => $artigos];
}

function analise_leitor(array $h): callable
{
    return function ($de, $ate) use ($h) {
        $dentro = function ($x) use ($de, $ate) { return $x['data'] >= $de && $x['data'] <= $ate; };
        return ['vendas' => array_values(array_filter($h['vendas'], $dentro)), 'artigos' => array_values(array_filter($h['artigos'], $dentro))];
    };
}

function analise_limpar(): void
{
    foreach (glob(naval_analise_pasta() . '/*.json') ?: [] as $f) unlink($f);
}

teste('Análise: resumo do dia com notas de crédito, clientes sem NIF e artigos por rota', function () {
    $r = naval_analise_resumir([
        ['rota' => '1001', 'total' => 1000.0, 'cliente' => 'A', 'cliente_codigo' => 'C1', 'nif' => '5000', 'nc' => false],
        ['rota' => '1001', 'total' => -200.0, 'cliente' => 'A', 'cliente_codigo' => 'C1', 'nc' => true],
        ['rota' => '1002', 'total' => 500.0, 'cliente' => 'B', 'cliente_codigo' => 'C2', 'nc' => false],
    ], [['rota' => '1001', 'codigo' => 'X', 'artigo' => 'Sabão', 'unidade' => 'CX', 'quantidade' => 3, 'total' => 300]]);
    igual(['t' => 800.0, 'd' => 1, 'nc' => 1], $r['rotas']['1001']);
    igual(1000.0, $r['clientes']['C1']['t'], 'notas de crédito não contam como compra do cliente');
    verdade(strpos(json_encode($r), '5000') === false, 'sem NIF nos agregados');
    igual(3.0, $r['artigos']['1001']['X']['q']);
});

teste('Análise: agregados gravados por dia, dias sem vendas a zero, só os dias em falta voltam a ler-se', function () {
    analise_limpar();
    $fim = date('Y-m-d', time() - 10 * 86400);
    $h = analise_historico($fim, 40, ['1001' => 10000]);
    $leituras = [];
    $leitor = function ($de, $ate) use ($h, &$leituras) { $leituras[] = "$de..$ate"; return analise_leitor($h)($de, $ate); };
    $de = date('Y-m-d', strtotime($fim . ' 12:00') - 39 * 86400);
    $a = naval_analise_agregados($de, $fim, $leitor);
    igual(40, count($a));
    igual(2, count($leituras), 'blocos de até 31 dias');
    $domingo = array_values(array_filter(array_keys($a), function ($d) { return date('N', strtotime($d)) === '7'; }))[0];
    igual([], $a[$domingo]['rotas'], 'domingo sem vendas');
    $leituras = [];
    naval_analise_agregados($de, $fim, $leitor);
    igual([], $leituras, 'dias fechados já não se lêem');
    // âmbito: só a rota permitida; sem valores, ficam só as quantidades
    $s = naval_analise_ambito($a, ['1002'], true);
    igual([], $s[$fim]['rotas']);
    $s = naval_analise_ambito($a, null, false);
    igual(null, $s[$fim]['rotas']['1001']['t']);
    igual([], $s[$fim]['clientes']);
    verdade($s[$fim]['artigos']['1001']['A1']['q'] > 0);
});

teste('Estatística: mediana, MAD e mesmos dias da semana', function () {
    igual(3.0, naval_mediana([5, 1, 3]));
    igual(2.5, naval_mediana([1, 2, 3, 4]));
    igual(0.0, naval_mad([7, 7, 7]));
    $serie = ['2026-10-05' => 1, '2026-10-12' => 2, '2026-10-19' => 3, '2026-10-20' => 99];
    igual(['2026-10-19' => 3.0, '2026-10-12' => 2.0, '2026-10-05' => 1.0], naval_mesmos_dias($serie, '2026-10-26', 6));
});

teste('Alertas: rota sem vendas, queda do dia, queda e subida da semana; dia normal sem alertas', function () {
    $dia = '2026-10-28';   // quarta-feira
    $h = analise_historico($dia, 60, ['1001' => 10000, '1002' => 10000, '1003' => 10000, '1004' => 10000], function ($d, $r, $v) use ($dia) {
        if ($r === '1001' && $d === $dia) return 0;                                   // rota sem vendas
        if ($r === '1002' && $d === $dia) return $v * 0.3;                             // queda do dia
        if ($r === '1003' && $d > date('Y-m-d', strtotime($dia) - 7 * 86400)) return $v * 0.5;   // semana fraca
        if ($r === '1004' && $d > date('Y-m-d', strtotime($dia) - 7 * 86400)) return $v * 1.6;   // semana forte
        return $v;
    });
    $ag = [];
    foreach (naval_analise_dias('2026-08-30', $dia) as $d) $ag[$d] = naval_analise_resumir(
        array_filter($h['vendas'], function ($x) use ($d) { return $x['data'] === $d; }), []);
    $a = naval_alertas_rotas($ag, $dia, naval_alertas_parametros(), ['1001' => 'Andrea']);
    $tipos = [];
    foreach ($a as $x) $tipos[$x['rota']][] = $x['tipo'];
    igual(['rota-sem-vendas'], $tipos['1001'] ?? null, 'rota 1001');
    verdade(strpos($a[0]['titulo'], '1001 · Andrea') !== false, 'título com o promotor');
    igual(['queda-dia'], $tipos['1002'] ?? null, 'rota 1002 (a semana desce só 10 %)');
    verdade(in_array('queda-semana', $tipos['1003'] ?? [], true), 'rota 1003');
    igual(['subida-semana'], $tipos['1004'] ?? null, 'rota 1004');
    // ordem: crítico primeiro
    igual('critico', naval_alertas_ordenar($a)[0]['gravidade']);
    // dia normal: sem alertas
    $h2 = analise_historico($dia, 60, ['1005' => 10000]);
    $ag2 = [];
    foreach (naval_analise_dias('2026-08-30', $dia) as $d) $ag2[$d] = naval_analise_resumir(array_filter($h2['vendas'], function ($x) use ($d) { return $x['data'] === $d; }), []);
    igual([], naval_alertas_rotas($ag2, $dia, naval_alertas_parametros()));
});

teste('Alertas: cliente regular sem compras; cliente ocasional e cliente activo sem alerta', function () {
    $dia = '2026-10-28';
    $h = analise_historico($dia, 120, ['1001' => 10000], null, '2026-09-20');   // C1001-2 deixou de comprar em setembro
    $ag = [];
    foreach (naval_analise_dias('2026-07-01', $dia) as $d) $ag[$d] = naval_analise_resumir(array_filter($h['vendas'], function ($x) use ($d) { return $x['data'] === $d; }), []);
    $a = naval_alertas_clientes($ag, $dia, naval_alertas_parametros());
    igual(['C1001-2'], array_column($a, 'cliente'));
    verdade(strpos($a[0]['detalhe'], 'a cada 7 dias') !== false, $a[0]['detalhe']);
});

teste('Alertas: AGT por comunicar e MDM sem contacto', function () {
    $p = naval_alertas_parametros();
    $a = naval_alertas_agt([
        ['rota' => '1001', 'estado' => 'por_comunicar', 'data' => '2026-10-20', 'total' => 100, 'anulado' => false],
        ['rota' => '1001', 'estado' => 'por_comunicar', 'data' => '2026-10-27', 'total' => 100, 'anulado' => false],   // recente: ainda no prazo
        ['rota' => '1001', 'estado' => 'por_comunicar', 'data' => '2026-10-10', 'total' => 100, 'anulado' => true],
        ['rota' => '1002', 'estado' => 'comunicado', 'data' => '2026-10-01', 'total' => 100, 'anulado' => false],
    ], '2026-10-28', $p);
    igual(1, count($a));
    igual('critico', $a[0]['gravidade'], '8 dias');
    $agora = strtotime('2026-10-28 12:00');
    $m = naval_alertas_mdm([
        ['device_id' => '1', 'device_name' => '1001 - Andrea', 'last_contact_time' => ($agora - 3 * 86400) * 1000],
        ['device_id' => '2', 'device_name' => '1002 - Cristina', 'last_contact_time' => $agora - 3600],
        ['device_id' => '3', 'device_name' => 'Sem campo'],
    ], $agora, $p);
    igual(['1001'], array_column($m, 'rota'));
});

teste('Previsão: ciclo semanal, tendência limitada, intervalo e precisão medida', function () {
    $ult = '2026-10-28';
    $h = analise_historico($ult, 90, ['1001' => 10000]);
    $ag = [];
    foreach (naval_analise_dias('2026-07-31', $ult) as $d) $ag[$d] = naval_analise_resumir(array_filter($h['vendas'], function ($x) use ($d) { return $x['data'] === $d; }), []);
    $p = naval_previsao_vendas($ag, $ult, 7);
    igual(7, count($p['rotas'][0]['dias']));
    $porDia = array_column($p['rotas'][0]['dias'], 'previsto', 'dia');
    igual(12000.0, $porDia['2026-10-30'], 'sexta-feira (×1,2)');
    igual(0.0, $porDia['2026-11-01'], 'domingo');
    igual(1.0, $p['rotas'][0]['tendencia']);
    igual(1.0, $p['rotas'][0]['qualidade']['precisao'], 'série regular: erro zero');
    // tendência: vendas a dobrar → limitada a +20 %
    $serie = [];
    foreach (naval_analise_dias('2026-08-31', $ult) as $i => $d) $serie[$d] = $d > '2026-09-30' ? 2000.0 : 1000.0;
    igual(1.2, naval_previsao_tendencia($serie, $ult));
    // pouco histórico: sem previsão
    igual(null, naval_previsao_dia(['2026-10-21' => 5.0], '2026-11-04', $ult));
});

teste('Previsão: quantidades do plano de cargas pela média ponderada das últimas cargas × tendência', function () {
    $planos = [];
    foreach ([['2026-10-27', 10], ['2026-10-26', 20], ['2026-10-24', 30], ['2026-10-01', 99]] as $i => [$d, $q]) {
        $planos[] = ['data' => $d, 'publicado' => ['rotas' => [['rota' => '1001', 'sai' => true, 'quantidades' => ['fraldas' => $q, 'sabao' => 5]],
            ['rota' => '1002', 'sai' => $i === 0, 'quantidades' => ['fraldas' => 7]]]]];
    }
    $r = naval_previsao_cargas($planos, '2026-10-28', ['1001' => 1.1]);
    // (6×10 + 5×20 + 4×30 + 3×99) / 18 = 32,06 × 1,1 = 35,3 → 36
    igual(36, $r['1001']['quantidades']['fraldas']);
    igual(6, $r['1001']['quantidades']['sabao'], '5 × 1,1 = 5,5 → 6');
    igual(4, $r['1001']['cargas']);
    verdade(!isset($r['1002']), 'uma só carga: sem previsão');
    // planos do próprio dia ou posteriores não contam
    // (6×20 + 5×30 + 4×99) / 15 = 44,4 → 45
    igual(45, naval_previsao_cargas($planos, '2026-10-27', [])['1001']['quantidades']['fraldas'], 'só cargas anteriores');
});

teste('Resumo diário: números, âmbito por rota e correio em HTML justificado', function () {
    $dia = '2026-10-28';
    $h = analise_historico($dia, 120, ['1001' => 10000, '1002' => 20000]);
    $ag = [];
    foreach (naval_analise_dias('2026-07-01', $dia) as $d) $ag[$d] = naval_analise_resumir(array_filter($h['vendas'], function ($x) use ($d) { return $x['data'] === $d; }), []);
    $r = naval_resumo_dados($ag, $dia, null, [], ['1001' => 'Andrea']);
    igual(33000.0, $r['ontem']['total'], 'quarta-feira ×1,1');
    igual(0.0, $r['ontem']['variacao']);
    igual(2, count($r['rotas']));
    $s = naval_resumo_dados($ag, $dia, ['1001'], [], []);
    igual(11000.0, $s['ontem']['total'], 'supervisor: só as suas rotas');
    $html = naval_resumo_html($r, "Parágrafo da IA.\n\nSegundo <b>parágrafo</b>.", 'https://portal.teste/reports/');
    verdade(strpos($html, 'text-align:justify') !== false);
    verdade(strpos($html, '&lt;b&gt;') !== false, 'texto da IA escapado');
    verdade(strpos($html, '33 000 Kz') !== false);
    verdade(strpos($html, '28-10-2026') !== false, 'datas dd-mm-aaaa');
});
