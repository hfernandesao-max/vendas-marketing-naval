<?php
// Análises de gestão: RFM, coortes, CLV, preço-volume-mix, ABC-XYZ, cesto, concentração, produtividade e cobertura.
naval_servico('analises-gestao');

/** Agregados sintéticos: $compras = [[dia, cliente, rota, valor, {artigo: [q, t]}], ...]. */
function ag_sint(array $compras): array
{
    $ag = [];
    foreach ($compras as [$d, $c, $rt, $v, $arts]) {
        if (!isset($ag[$d])) $ag[$d] = ['rotas' => [], 'clientes' => [], 'artigos' => [], 'ca' => []];
        $ag[$d]['rotas'][$rt]['t'] = ($ag[$d]['rotas'][$rt]['t'] ?? 0) + $v;
        $ag[$d]['clientes'][$c] = ['n' => 'Cliente ' . $c, 'r' => $rt, 't' => ($ag[$d]['clientes'][$c]['t'] ?? 0) + $v, 'ti' => 0, 'd' => 1];
        foreach ($arts as $cod => [$q, $t]) {
            $x = $ag[$d]['artigos'][$rt][$cod] ?? ['n' => 'Artigo ' . $cod, 'u' => 'UN', 'q' => 0, 't' => 0, 'ti' => 0];
            $x['q'] += $q;
            $x['t'] += $t;
            $ag[$d]['artigos'][$rt][$cod] = $x;
            $ag[$d]['ca'][$c][$cod] = ($ag[$d]['ca'][$c][$cod] ?? 0) + $q;
        }
    }
    return $ag;
}

teste('análises: ponte preço-volume-mix fecha sempre na variação, com preço, volume, novos e perdidos', function () {
    $a0 = ['A' => ['n' => 'A', 'u' => '', 'q' => 10.0, 't' => 1000.0], 'B' => ['n' => 'B', 'u' => '', 'q' => 10.0, 't' => 500.0], 'P' => ['n' => 'P', 'u' => '', 'q' => 1.0, 't' => 300.0]];
    $a1 = ['A' => ['n' => 'A', 'u' => '', 'q' => 12.0, 't' => 1320.0], 'B' => ['n' => 'B', 'u' => '', 'q' => 10.0, 't' => 500.0], 'N' => ['n' => 'N', 'u' => '', 'q' => 2.0, 't' => 200.0]];
    $r = naval_ag_pvm_calc($a0, $a1);
    igual(120.0, $r['preco'], 'A: de 100 para 110 Kz × 12');
    igual([200.0, -300.0], [$r['novos'], $r['perdidos']]);
    igual(round($r['actual'] - $r['anterior'], 2), round($r['preco'] + $r['volume'] + $r['mix'] + $r['novos'] + $r['perdidos'], 2), 'a ponte fecha');
    igual(150.0, $r['volume'], '+2 unidades ao preço médio de 75');
    igual(50.0, $r['mix'], 'as unidades a mais são do artigo caro');
});

teste('análises: RFM separa campeões de clientes perdidos e calcula o valor anual em risco', function () {
    $c = [];
    for ($i = 0; $i < 20; $i++) {
        // 10 clientes bons e recentes, 10 que compravam muito e pararam há 4 meses
        for ($k = 0; $k < 8; $k++) $c[] = [date('Y-m-d', strtotime('2026-11-20') - $k * 7 * 86400), 'b' . $i, '1004', 5000.0 + $i, ['A' => [1, 100]]];
        for ($k = 0; $k < 8; $k++) $c[] = [date('Y-m-d', strtotime('2026-07-20') + $k * 7 * 86400), 'p' . $i, '1007', 4000.0 + $i, ['A' => [1, 100]]];
    }
    $r = naval_ag_rfm(ag_sint($c), '2026-11-22');
    $s = array_column($r['segmentos'], null, 'segmento');
    igual(40, $r['clientes']);
    verdade($s['campeoes']['clientes'] + $s['leais']['clientes'] >= 10, 'os recentes são campeões ou leais');
    verdade($s['nao_perder']['clientes'] + $s['em_risco']['clientes'] >= 8, 'os que pararam estão em risco');
    verdade($r['valor_anual_em_risco'] > 0);
    igual('b19', $r['lista'][0]['cliente_codigo'], 'maior valor primeiro');
});

teste('análises: coortes, CLV, ABC-XYZ, cesto, concentração, produtividade e cobertura de stock', function () {
    $c = [];
    $t0 = strtotime('2026-06-01 12:00');
    for ($d = 0; $d < 175; $d++) {
        $dia = date('Y-m-d', $t0 + $d * 86400);
        if (date('N', $t0 + $d * 86400) == 7) continue;
        for ($i = 0; $i < 30; $i++) {
            if (($d + $i) % 5) continue;
            if ($i >= 20 && $d < 60) continue;   // 10 clientes novos a partir de agosto
            $arts = ['A' => [10, 1000.0], 'B' => [2, 400.0]];
            if ($i % 2) $arts['C'] = [1, 50.0];
            if ($i % 3 === 0) $arts['Z'] = [$d % 4 ? 0.0001 : 20, $d % 4 ? 0.01 : 2000.0];
            $c[] = [$dia, 'c' . $i, $i % 3 ? '1004' : '1007', array_sum(array_column($arts, 1)), $arts];
        }
    }
    $ag = ag_sint($c);
    $fim = '2026-11-22';
    $x = naval_ag_analises($ag, $fim, ['1004' => 'Ana', '1007' => 'Rui'], [
        ['codigo' => 'A', 'artigo' => 'A', 'quantidade' => 30, 'onde' => 'armazem'], ['codigo' => 'A', 'artigo' => 'A', 'quantidade' => 5, 'onde' => 'carrinha'],
        ['codigo' => 'B', 'artigo' => 'B', 'quantidade' => 5000, 'onde' => 'armazem'], ['codigo' => 'Q', 'artigo' => 'Q', 'quantidade' => 7, 'onde' => 'armazem'],
    ]);
    // coortes: os novos de agosto formam coorte; junho (primeiro mês) não
    $co = array_column($x['coortes']['coortes'], null, 'coorte');
    verdade(isset($co['2026-07']) || isset($co['2026-08']), 'coorte dos novos');
    verdade(!isset($co['2026-06']), 'o primeiro mês não forma coorte');
    igual(1.0, $x['coortes']['coortes'][0]['retencao'][0]);
    // CLV por rota
    verdade(count($x['clv']) === 2 && $x['clv'][0]['clv'] > 0);
    // ABC-XYZ: A e B regulares (X); Z irregular
    $abc = array_column($x['abc_xyz']['artigos'], null, 'codigo');
    igual('X', $abc['A']['xyz']);
    igual('A', $abc['A']['abc']);
    igual(9, count($x['abc_xyz']['matriz']));
    // cesto: C vende-se com A metade das vezes; as regras têm lift ≥ 1,2
    foreach ($x['cesto']['regras'] as $r) verdade($r['lift'] >= 1.2);
    verdade($x['cesto']['cestos'] > 100);
    // concentração
    verdade($x['concentracao']['clientes']['hhi'] > 0 && $x['concentracao']['rotas']['n'] === 2);
    // produtividade: a rota de referência não tem potencial
    $p = $x['produtividade'];
    igual(2, count($p['rotas']));
    verdade(min(array_column($p['rotas'], 'potencial')) >= 0);
    // cobertura: A em stock curto, B em excesso, Q parado, C em rotura
    $cb = array_column($x['cobertura']['artigos'], null, 'codigo');
    igual(['critico', 'excesso', 'parado', 'rotura'], [$cb['A']['estado'], $cb['B']['estado'], $cb['Q']['estado'], $cb['C']['estado']]);
    igual(35.0, $cb['A']['stock'], 'armazém + carrinhas');
    verdade($x['cobertura']['capital']['excesso'] > 0);
    igual('rotura', $x['cobertura']['artigos'][0]['estado'], 'roturas primeiro');
    // destaques para o relatório semanal: roturas e o resumo da ponte
    $dq = naval_ag_destaques($x);
    igual(['Artigo C', 'Artigo Z'], $dq['roturas'], 'sem stock e com venda');
    igual($x['pvm']['total']['preco'], $dq['pvm']['preco']);
    verdade($dq['valor_anual_em_risco'] >= 0);
    // sem stock (sem acesso): sem cobertura
    igual(null, naval_ag_analises($ag, $fim)['cobertura']);
});
