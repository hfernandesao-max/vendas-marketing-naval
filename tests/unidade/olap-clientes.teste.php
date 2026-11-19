<?php
// Ficha do cliente a partir do OLAP: normalização das linhas, dias de visita, GPS em Angola, NIF e âmbito por rotas.
naval_servico('olap-clientes');

function olapcli_bruto(): array
{
    return [
        'cliente' => ['ID' => '5697', 'CODE' => '0000000884', 'DESCRIPTION' => " Loja Bom Pre\xE7o ", 'FISCALNAME' => 'Bom Preço, Lda', 'TAX_NUMBER' => '5000000000',
            'TYPOLOGY' => 'MINI-MERCADO', 'STATE_PROVINCE' => 'VIANA', 'CITY' => '', 'LATITUDE' => '-8.9418567', 'LONGITUDE' => '13.4250833', 'UPDATE_DATE' => '2026-11-19 13:00:02.000'],
        'atribuicao' => ['START_DATE' => '2026-11-19 13:00:02.000', 'ROTA' => '1001', 'ROTA_NOME' => 'Rota 1001', 'VENDEDOR' => 'Promotor A', 'COORDENADOR' => 'AINDA SEM COORDENADOR',
            'GESTOR' => null, 'DISTRIBUIDOR' => 'Naval', 'CANAL' => 'Retalho', 'SUBCANAL' => '', 'VISIT_DAY1' => '0', 'VISIT_DAY2' => '1', 'VISIT_DAY3' => '0',
            'VISIT_DAY4' => '0', 'VISIT_DAY5' => '1', 'VISIT_DAY6' => '0', 'VISIT_DAY7' => '1'],
        'visitas' => [
            ['DIM_DATE_ID' => '20261118', 'HORA' => '13:08:53', 'ROTA' => '1001', 'VENDEDOR' => 'Promotor A', 'RESULTADO' => 'Venda', 'PRESENCIAL' => '1', 'REMOTE' => '0',
                'NON_GPS' => '0', 'DOC_TOTAL' => '135000.000', 'DOC_FULLNUMBER' => 'FR 1001/55', 'DISTANCE' => '6.000',
                // 13:08:53 → 13:20:53 (12 min no cliente); seguinte às 13:35:53, 1,1 km a norte (15 min de deslocação)
                'INICIO' => '47333', 'DIM_END_DATE_ID' => '20261118', 'FIM' => '48053', 'LAT' => '-8.8778', 'LNG' => '13.3307',
                'PROX_INICIO' => '48953', 'PROX_LAT' => '-8.8679', 'PROX_LNG' => '13.3307', 'PROX_CODIGO' => '0000000885', 'PROX_CLIENTE' => "Cantina S\xE3o Jos\xE9"],
            ['DIM_DATE_ID' => '20261111', 'HORA' => '16:00:57', 'ROTA' => '1001', 'VENDEDOR' => 'Promotor A', 'RESULTADO' => "Aus\xEAncia respons\xE1vel", 'PRESENCIAL' => '0',
                'REMOTE' => '1', 'NON_GPS' => '0', 'DOC_TOTAL' => null, 'DOC_FULLNUMBER' => null, 'DISTANCE' => '1767.000'],
            ['DIM_DATE_ID' => 'x', 'HORA' => '', 'RESULTADO' => 'Venda'],
        ],
    ];
}

teste('ficha OLAP: textos em UTF-8, município, GPS, dias de visita (1 = domingo) e visitas', function () {
    $f = naval_olapcli_ficha(olapcli_bruto(), true);
    igual('Loja Bom Preço', $f['cliente']['nome']);
    igual('5000000000', $f['cliente']['nif']);
    igual('VIANA', $f['localizacao']['municipio']);
    igual(null, $f['localizacao']['cidade']);
    igual(-8.941857, $f['localizacao']['lat']);
    igual(['Segunda-feira', 'Quinta-feira', 'Sábado'], $f['atribuicao']['dias_visita']);
    igual(null, $f['atribuicao']['gestor']);
    igual(null, $f['atribuicao']['coordenador'], 'marcador «AINDA SEM …» conta como vazio');
    igual(null, $f['atribuicao']['subcanal']);
    igual('2026-11-19', $f['atribuicao']['desde']);
    igual(2, count($f['visitas']), 'linha com data inválida fica de fora');
    igual(['data' => '2026-11-18', 'hora' => '13:08', 'venda' => true, 'modo' => 'presencial', 'valor' => 135000.0, 'distancia' => 6],
        array_intersect_key($f['visitas'][0], array_flip(['data', 'hora', 'modo', 'venda', 'valor', 'distancia'])));
    igual('Ausência responsável', $f['visitas'][1]['resultado']);
    igual(false, $f['visitas'][1]['venda']);
    igual(['total' => 2, 'com_venda' => 1, 'presenciais' => 1, 'remotas' => 1, 'distancia_mediana' => 1767, 'ultima' => '2026-11-18'],
        array_intersect_key($f['resumo_visitas'], array_flip(['total', 'com_venda', 'presenciais', 'remotas', 'distancia_mediana', 'ultima'])));
});

teste('ficha OLAP: tempo no cliente, deslocação e distância até ao cliente seguinte; horas de fim absurdas ficam de fora', function () {
    $f = naval_olapcli_ficha(olapcli_bruto(), true);
    $v = $f['visitas'][0];
    igual(['permanencia_min' => 12, 'deslocacao_min' => 15, 'intervalo_min' => 27, 'lat' => -8.8778, 'lng' => 13.3307],
        array_intersect_key($v, array_flip(['permanencia_min', 'deslocacao_min', 'intervalo_min', 'lat', 'lng'])));
    verdade($v['proximo_m'] > 1050 && $v['proximo_m'] < 1150, 'distância em linha recta ≈ 1,1 km: ' . $v['proximo_m']);
    igual('Cantina São José', $v['proximo_cliente']);
    igual(null, $f['visitas'][1]['permanencia_min'], 'sem hora de fim');
    igual(12, $f['resumo_visitas']['permanencia_mediana_min']);
    igual(15, $f['resumo_visitas']['deslocacao_mediana_min']);
    igual(1, $f['resumo_visitas']['com_tempo']);
    // fim no dia seguinte ou mais de 4 h depois: esquecimento de fechar a visita; fica só o intervalo entre inícios
    $x = ['DIM_DATE_ID' => '20261118', 'INICIO' => '36000', 'DIM_END_DATE_ID' => '20261118', 'FIM' => '60000', 'PROX_INICIO' => '61000'];
    igual([null, null, 417], array_values(array_intersect_key(naval_olapcli_tempos($x), array_flip(['permanencia_min', 'deslocacao_min', 'intervalo_min']))));
    $x['DIM_END_DATE_ID'] = '20261119';
    $x['FIM'] = '36600';
    igual(null, naval_olapcli_tempos($x)['permanencia_min']);
    // seguinte a 10 m (encomendas remotas seguidas no mesmo sítio): sem deslocação
    $y = ['DIM_DATE_ID' => '20261118', 'INICIO' => '36000', 'DIM_END_DATE_ID' => '20261118', 'FIM' => '36300', 'PROX_INICIO' => '36400',
        'LAT' => '-8.8778', 'LNG' => '13.3307', 'PROX_LAT' => '-8.87789', 'PROX_LNG' => '13.3307'];
    $ty = naval_olapcli_tempos($y);
    igual([5, null, true], [$ty['permanencia_min'], $ty['deslocacao_min'], $ty['proximo_mesmo_sitio']]);
});

teste('ficha OLAP: sem permissão de NIF, sem NIF; GPS fora de Angola não se mostra', function () {
    $b = olapcli_bruto();
    $b['cliente']['LATITUDE'] = '38.72';
    $b['cliente']['LONGITUDE'] = '-9.14';
    $b['atribuicao'] = null;
    $f = naval_olapcli_ficha($b, false);
    igual(null, $f['cliente']['nif']);
    igual(null, $f['localizacao']['lat']);
    igual(true, $f['localizacao']['gps_invalido']);
    igual(null, $f['atribuicao']);
});

teste('ficha OLAP: âmbito por rotas (rota do OLAP ou da carteira) e códigos aceites', function () {
    $f = naval_olapcli_ficha(olapcli_bruto(), true);
    verdade(naval_olapcli_no_ambito($f, null));
    verdade(naval_olapcli_no_ambito($f, ['1001']));
    verdade(!naval_olapcli_no_ambito($f, ['1002']));
    verdade(naval_olapcli_no_ambito($f, ['1002'], ['1002']));
    verdade(naval_olapcli_codigo_valido('0000000884'));
    verdade(!naval_olapcli_codigo_valido("0' OR 1=1"));
    verdade(!naval_olapcli_codigo_valido(''));
});
