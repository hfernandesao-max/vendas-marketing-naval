<?php
// Rotas propostas: geração (divisão de rota cheia e zona sem rota), impacto em visitas e peso, aprovação com
// atribuição dos clientes e conflitos, rejeição, substituição, activação pelas vendas e aplicação das atribuições.
naval_servico('rotas-propostas');

function rp_vista(): array
{
    $cs = [];
    // rota 1001: 6 clientes em dois grupos afastados (4 a oeste, 2 a leste)
    foreach ([[13.20, 'A'], [13.201, 'B'], [13.202, 'C'], [13.203, 'D'], [13.30, 'E'], [13.301, 'F']] as $i => [$lng, $n]) {
        $cs[] = ['c' => 'C' . $i, 'n' => 'Cliente ' . $n, 'lat' => -8.85, 'lng' => $lng, 'r' => '1001', 'd' => 2, 'f' => $i < 4 ? 1 : 2];
    }
    $cs[] = ['c' => 'X1', 'n' => 'Outra rota', 'lat' => -8.9, 'lng' => 13.25, 'r' => '1002', 'd' => 3, 'f' => 1];
    return ['parametros' => ['visitas_dia' => 30, 'dias_semana' => 6, 'limiar' => 0.9],
        'rotas' => [['rota' => '1001', 'ocupacao' => 0.95, 'visitas_semana' => 171], ['rota' => '1002', 'ocupacao' => 0.4, 'visitas_semana' => 72]],
        'clientes' => $cs, 'zonas_sem_rota' => [['lat' => -8.7, 'lng' => 13.4, 'potenciais' => 55]]];
}
function rp_agregados(): array
{
    // 14 dias: os clientes E e F compram 7 000 Kz e 10 unidades de SKU1 (2 kg) por dia
    $ag = [];
    for ($k = 0; $k < 14; $k++) {
        $ag[date('Y-m-d', strtotime('2026-11-01') + $k * 86400)] = ['clientes' => ['C4' => ['t' => 3000.0], 'C5' => ['t' => 4000.0]], 'ca' => ['C4' => ['SKU1' => 4], 'C5' => ['SKU1' => 6, 'SKU9' => 1]]];
    }
    return $ag;
}

teste('rotas propostas: impacto em visitas, vendas por semana e kg por dia face à capacidade', function () {
    $i = naval_rp_impacto([['c' => 'C4', 'f' => 2], ['c' => 'C5', 'f' => 2]], rp_agregados(), ['SKU1' => 2.0], 180, 1000.0, 6);
    igual(2, $i['clientes']);
    igual(4, $i['visitas_semana']);
    igual(49000.0, $i['vendas_semana'], '7 000 Kz por dia × 7');
    igual(23.3, $i['kg_dia'], '10 unidades × 2 kg × 7 dias ÷ 6 dias de visita');
    igual(0.023, $i['carga']);
    igual(1, $i['artigos_sem_peso']);
    // sem pesos dos artigos no Pinguim BO: peso por saber, nunca «0 kg»
    $s = naval_rp_impacto([['c' => 'C4', 'f' => 2]], rp_agregados(), [], 180, 1000.0, 6);
    igual([null, null], [$s['kg_dia'], $s['carga']]);
});

teste('rotas propostas: rota cheia propõe passar a parte menor do território; zona sem rota propõe uma rota nova; sem duplicados', function () {
    [$novas, $mantem] = naval_rp_gerar(rp_vista(), ['1001'], [], [], rp_agregados(), ['SKU1' => 2.0], 1000.0, '2026-11-20');
    igual(2, count($novas));
    $d = $novas[0];
    igual(['divisao', '1001'], [$d['tipo'], $d['origem_rota']]);
    igual(['C4', 'C5'], $d['clientes'], 'os dois clientes a leste passam para a rota nova');
    igual(4, $d['impacto']['visitas_semana']);
    igual(0.95, $d['impacto']['ocupacao_origem_antes']);
    verdade(count($d['territorio']) >= 3);
    $z = $novas[1];
    igual(['zona', 55, []], [$z['tipo'], $z['potenciais'], $z['clientes']]);
    igual(4, count($z['territorio']));
    igual(['divisao:1001', 'zona:-8.7:13.4'], $mantem);
    // com as duas pendentes já guardadas, não se repetem
    $g = naval_rp_juntar([], $novas, $mantem, [], '2026-11-20');
    [$outra] = naval_rp_gerar(rp_vista(), ['1001'], $g['propostas'], [], rp_agregados(), [], null, '2026-11-21');
    igual([], $outra);
    igual(['RP-0001', 'RP-0002'], array_column($g['propostas'], 'id'));
    // cliente já atribuído noutra proposta aprovada não entra numa proposta nova
    [$n3] = naval_rp_gerar(rp_vista(), ['1001'], [], ['C5' => ['r' => '1009', 'proposta' => 'RP-0009']], rp_agregados(), [], null, '2026-11-21');
    verdade(!in_array('C5', $n3[0]['clientes'], true));
});

teste('rotas propostas: aprovar atribui os clientes à rota nova; conflitos, código repetido e decisão dupla recusados', function () {
    [$novas, $mantem] = naval_rp_gerar(rp_vista(), ['1001'], [], [], rp_agregados(), [], null, '2026-11-20');
    $g = naval_rp_juntar([], $novas, $mantem, [], '2026-11-20');
    $eu = ['login' => 'ana.gestora', 'nome' => 'Ana Gestora'];
    lanca(400, function () use ($g, $eu) { naval_rp_decidir($g, [], 'RP-0001', 'aprovar', ['rota_nova' => ''], $eu, ['1001', '1002'], 'agora'); });
    lanca(409, function () use ($g, $eu) { naval_rp_decidir($g, [], 'RP-0001', 'aprovar', ['rota_nova' => '1002'], $eu, ['1001', '1002'], 'agora'); });
    lanca(404, function () use ($g, $eu) { naval_rp_decidir($g, [], 'RP-9999', 'aprovar', ['rota_nova' => '1010'], $eu, [], 'agora'); });
    $antes = ['C5' => ['r' => '1009', 'proposta' => 'RP-0009', 'em' => 'x', 'por' => 'y', 'origem' => '1001']];
    [$g2, $a2, $r] = naval_rp_decidir($g, $antes, 'RP-0001', 'aprovar', ['rota_nova' => '1010', 'promotor' => 'Novo Promotor'], $eu, ['1001', '1002'], '2026-11-20 10:00:00');
    igual(['aprovada', '1010', 1], [$r['estado'], $r['rota_nova'], $r['atribuidos']]);
    igual([['c' => 'C5', 'rota' => '1009', 'proposta' => 'RP-0009']], $r['conflitos']);
    igual('1010', $a2['C4']['r']);
    igual('1009', $a2['C5']['r'], 'a atribuição anterior mantém-se');
    igual('aprovada', $g2['propostas'][0]['estado']);
    lanca(409, function () use ($g2, $a2, $eu) { naval_rp_decidir($g2, $a2, 'RP-0001', 'rejeitar', ['nota' => 'já decidida'], $eu, [], 'agora'); });
    // o mesmo código de rota não serve para outra proposta
    lanca(409, function () use ($g2, $a2, $eu) { naval_rp_decidir($g2, $a2, 'RP-0002', 'aprovar', ['rota_nova' => '1010'], $eu, ['1001'], 'agora'); });
    // rejeitar exige motivo
    lanca(400, function () use ($g2, $a2, $eu) { naval_rp_decidir($g2, $a2, 'RP-0002', 'rejeitar', ['nota' => ''], $eu, [], 'agora'); });
    [$g3] = naval_rp_decidir($g2, $a2, 'RP-0002', 'rejeitar', ['nota' => 'Sem viatura disponível'], $eu, [], 'agora');
    igual('rejeitada', $g3['propostas'][1]['estado']);
});

teste('rotas propostas: pendente sem situação passa a substituída; aprovada passa a activa quando as vendas mostram a rota nova', function () {
    [$novas, $mantem] = naval_rp_gerar(rp_vista(), ['1001'], [], [], rp_agregados(), [], null, '2026-11-20');
    $g = naval_rp_juntar([], $novas, $mantem, [], '2026-11-20');
    [$g] = naval_rp_decidir($g, [], 'RP-0001', 'aprovar', ['rota_nova' => '1010'], ['login' => 'g'], ['1001'], 'agora');
    // no dia seguinte a zona já não aparece; os clientes ainda vendem na rota antiga
    $g = naval_rp_juntar($g, [], ['divisao:1001'], ['C4' => '1001', 'C5' => '1001'], '2026-11-21');
    igual(['aprovada', 'substituida'], array_column($g['propostas'], 'estado'));
    // já registados no Pinguim BO: as vendas passam a vir na rota 1010
    $g = naval_rp_juntar($g, [], [], ['C4' => '1010', 'C5' => '1010'], '2026-11-25');
    igual('activa', $g['propostas'][0]['estado']);
});

teste('rotas propostas: as atribuições mudam a rota efectiva dos clientes; territórios das zonas aprovadas', function () {
    $a = ['C4' => ['r' => '1010']];
    igual([['c' => 'C4', 'r' => '1010'], ['c' => 'C1', 'r' => '1001']], naval_rp_aplicar([['c' => 'C4', 'r' => '1001'], ['c' => 'C1', 'r' => '1001']], $a));
    igual('1010', naval_rp_rota('C4', '1001', $a));
    igual('1001', naval_rp_rota('C1', '1001', $a));
    $ps = [['tipo' => 'zona', 'estado' => 'aprovada', 'territorio' => [[1, 1]], 'decisao' => ['rota_nova' => '1020']],
        ['tipo' => 'zona', 'estado' => 'pendente', 'territorio' => [[2, 2]], 'decisao' => null]];
    igual(['1020' => [[1, 1]]], naval_rp_territorios_zona($ps));
});
