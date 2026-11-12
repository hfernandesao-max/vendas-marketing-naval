<?php
// Objectivos de vendas automáticos (nucleo/servicos/objectivos.php): base estatística, nunca descem, congelados no mês.
naval_servico('objectivos');

/** Agregados de teste: vendas por dia útil (segunda a sábado) entre $de e $ate, com o valor de $valor($dia). */
function obj_ag(string $de, string $ate, callable $valor, string $rota = '1001'): array
{
    $ag = [];
    foreach (naval_analise_dias($de, $ate) as $d) {
        $ag[$d] = ['rotas' => naval_dia_semana($d) === 7 ? [] : [$rota => ['t' => $valor($d), 'd' => 3, 'nc' => 0]]];
    }
    return $ag;
}

teste('objectivo: previsão do mês + crescimento, nunca abaixo do ritmo do mês anterior + 3%; dias úteis sem domingos', function () {
    $par = ['crescimento_min' => 0.03, 'crescimento_alvo' => 0.05];
    $ag = obj_ag('2026-06-01', '2026-08-31', function () { return 100000.0; });
    $o = naval_obj_calcular(naval_analise_serie($ag, '1001'), '2026-09', null, $par);
    igual(26, $o['dias_uteis'], 'setembro de 2026: 30 dias − 4 domingos');
    igual(0, (int) round(array_sum($o['dias']) - $o['objectivo']), 'a soma dos dias dá o objectivo');
    verdade($o['objectivo'] >= 100000 * 1.05 * 26 - 1000, 'base × 1,05');
    igual(0.0, fmod($o['objectivo'], 1000.0), 'arredondado ao milhar');
    igual(null, naval_obj_calcular([], '2026-09', null, $par), 'sem histórico, sem objectivo');
});

teste('objectivo sobe sempre: mês fraco não baixa o objectivo seguinte (ritmo por dia útil do objectivo anterior + 3%)', function () {
    $par = ['crescimento_min' => 0.03, 'crescimento_alvo' => 0.05];
    $ag = obj_ag('2026-06-01', '2026-08-31', function () { return 100000.0; });
    $set = naval_obj_calcular(naval_analise_serie($ag, '1001'), '2026-09', null, $par);
    // setembro corre mal: metade das vendas
    $ag2 = $ag + obj_ag('2026-09-01', '2026-09-30', function () { return 50000.0; });
    $out = naval_obj_calcular(naval_analise_serie($ag2, '1001'), '2026-10', $set, $par);
    $ritmoSet = $set['objectivo'] / $set['dias_uteis'];
    $ritmoOut = $out['objectivo'] / $out['dias_uteis'];
    verdade($ritmoOut >= $ritmoSet * 1.03 - 40, 'ritmo do objectivo de outubro ≥ ritmo de setembro + 3%');
    igual('minimo', $out['criterio'], 'foi a regra do mínimo crescente que decidiu');
});

teste('objectivos congelados no mês e acompanhamento: cumprimento, ritmo, falta por dia e projecção', function () {
    $ag = obj_ag('2026-03-01', '2026-05-31', function () { return 10000.0; }, '1007');
    $g = naval_objectivos_mes('2026-06', $ag, ['1007' => 'Teste'], ['crescimento_min' => 0.03, 'crescimento_alvo' => 0.05]);
    $o = $g['rotas']['1007'];
    $outra = naval_objectivos_mes('2026-06', obj_ag('2026-03-01', '2026-05-31', function () { return 99999.0; }, '1007'));
    igual((float) $o['objectivo'], (float) $outra['rotas']['1007']['objectivo'], 'não muda durante o mês');
    $junho = $ag + obj_ag('2026-06-01', '2026-06-15', function () { return 12000.0; }, '1007');
    $e = naval_objectivos_estado($g, $junho, '2026-06-15', null);
    $r = $e['rotas'][0];
    igual('acima', $r['estado'], 'a vender acima do objectivo diário');
    verdade($r['ritmo'] > 1, 'ritmo acima de 100%');
    igual(round($r['falta'] / $r['dias_restantes'], 2), $r['necessario_dia']);
    igual((float) $o['objectivo'], (float) $e['total']['objectivo'], 'total = soma das rotas no âmbito');
    igual([], naval_objectivos_estado($g, $junho, '2026-06-15', ['9999'])['rotas'], 'âmbito por rota');
});
