<?php
// Mapa de clientes ao vivo (nucleo/servicos/visitas-app.php): regras do guia «Mapa de clientes em tempo real».
naval_servico('visitas-app');

function vapp_linha(array $x): array
{
    return $x + ['id' => 1, 'id_sell' => null, 'customer_id' => 10, 'original_route_id' => 1, 'motive_id' => null, 'result' => null, 'total_sell' => 0,
        'quant_sell' => 0, 'lat' => null, 'lng' => null, 'distance' => null, 'start_date' => '2026-11-28', 'start_hour' => '09:00:00', 'end_hour' => null,
        'deleted' => 0, 'updated_at' => '2026-11-28 09:00:00'];
}

teste('coordenadas: texto com vírgula aceite; 0, vazio e fora de Angola rejeitados', function () {
    igual([-8.8383, 13.2344], naval_vapp_coord('-8,8383', '13,2344'));
    igual(null, naval_vapp_coord('0', '0'));
    igual(null, naval_vapp_coord('', '13.2'));
    igual(null, naval_vapp_coord(38.7, -9.1), 'Lisboa não é Angola');
    igual('09:05', naval_vapp_hora('2026-11-28 09:05:33'));
    igual('', naval_vapp_hora(null));
});

teste('presença: < 50 m presencial pelos dois GPS; sem o GPS do cliente vale o tipo de check-in da aplicação', function () {
    $c = [-8.8383, 13.2344];
    igual('p', naval_vapp_presenca([-8.8385, 13.2345], $c, 'Remota')[0], 'os dois GPS mandam');
    igual('r', naval_vapp_presenca([-8.8400, 13.2344], $c, 'Presencial')[0], '~190 m');
    igual(['r', null], naval_vapp_presenca([-8.84, 13.23], null, 'Remota'));
    igual(['p', null], naval_vapp_presenca(null, null, 'Presencial'));
    igual(['g', null], naval_vapp_presenca(null, $c, 'Armazém'), 'sem GPS nem tipo de check-in');
});

teste('estado da visita: venda pelo documento, encomenda, em curso, recusa, sem venda; id_sell por ler conta como venda por confirmar', function () {
    $docs = ['501' => ['tipo' => 'FR'], '502' => ['tipo' => 'ENC'], '503' => ['tipo' => 'NC']];
    igual([NAVAL_VAPP_VENDA, false], naval_vapp_estado_visita(['id_sell' => '501', 'end_hour' => '09:10'], $docs, ['ENC']));
    igual([NAVAL_VAPP_ENCOMENDA, false], naval_vapp_estado_visita(['id_sell' => '502', 'end_hour' => '09:10'], $docs, ['ENC']));
    igual([NAVAL_VAPP_VENDA, true], naval_vapp_estado_visita(['id_sell' => '999', 'total_sell' => 1500, 'end_hour' => '09:10'], $docs, []));
    igual([NAVAL_VAPP_VENDA, true], naval_vapp_estado_visita(['id_sell' => null, 'result' => 'Venda', 'end_hour' => '09:10'], $docs, []), 'resultado Venda sem documento');
    igual([NAVAL_VAPP_EM_CURSO, false], naval_vapp_estado_visita(['id_sell' => null, 'end_hour' => '', 'result' => '', 'total_sell' => 0], $docs, []));
    igual([NAVAL_VAPP_RECUSA, false], naval_vapp_estado_visita(['end_hour' => '09:20', 'result' => 'Recusa com Motivo'], $docs, []));
    igual([NAVAL_VAPP_SEM_VENDA, false], naval_vapp_estado_visita(['id_sell' => '503', 'end_hour' => '09:20', 'result' => 'Visitado'], $docs, []), 'nota de crédito não é venda');
});

teste('leitura incremental: actualiza a visita, tira as apagadas e as que mudaram de dia; completa substitui tudo', function () {
    $e = naval_vapp_fundir([], [vapp_linha(['id' => 1]), vapp_linha(['id' => 2, 'updated_at' => '2026-11-28 09:30:00'])], '2026-11-28', true);
    igual(['1', '2'], array_map('strval', array_keys($e['visitas'])));
    igual('2026-11-28 09:30:00', $e['marca']);
    $e = naval_vapp_fundir($e, [vapp_linha(['id' => 1, 'end_hour' => '09:40:00', 'result' => 'Venda', 'updated_at' => '2026-11-28 09:41:00']),
        vapp_linha(['id' => 2, 'deleted' => 1, 'updated_at' => '2026-11-28 09:42:00']), vapp_linha(['id' => 3, 'start_date' => '2026-11-27'])], '2026-11-28', false);
    igual(['1'], array_map('strval', array_keys($e['visitas'])), 'apagada e de outro dia saem');
    igual('09:40', $e['visitas']['1']['end_hour']);
    igual('2026-11-28 09:42:00', $e['marca']);
    igual([], naval_vapp_fundir($e, [], '2026-11-28', true)['visitas'], 'completa sem linhas esvazia');
});

teste('mapa por cliente: junção por pingwin_id, prioridade (venda > encomenda > em curso > recusa), GPS da visita ou do cliente, sem GPS e consumidor final à parte', function () {
    $estado = naval_vapp_fundir([], [
        // cliente A (pingwin 7001): recusa de manhã e venda à tarde → com venda, posição da última visita
        vapp_linha(['id' => 1, 'customer_id' => 10, 'start_hour' => '08:30', 'end_hour' => '08:40', 'result' => 'Recusa com Motivo', 'motive_id' => 3, 'lat' => '-8,8383', 'lng' => '13,2344']),
        vapp_linha(['id' => 2, 'customer_id' => 10, 'start_hour' => '15:00', 'end_hour' => '15:10', 'id_sell' => '501', 'total_sell' => 900, 'quant_sell' => 4, 'lat' => -8.8385, 'lng' => 13.2345]),
        // cliente B (pingwin 7002): em curso, sem GPS da visita → GPS do cliente (Pinguim BO)
        vapp_linha(['id' => 3, 'customer_id' => 11, 'start_hour' => '10:00']),
        // cliente C sem pingwin_id: recusa, sem GPS nenhum → «app:12», sem GPS
        vapp_linha(['id' => 4, 'customer_id' => 12, 'start_hour' => '11:00', 'end_hour' => '11:05', 'result' => 'Recusa com Motivo', 'motive_id' => 4]),
    ], '2026-11-28', true);
    $estado['clientes'] = ['10' => ['C10', 'Loja A', '-8.8383', '13.2344', '7001'], '11' => ['C11', 'Loja B', null, null, '7002'], '12' => ['C12', 'Loja C', '0', '0', '']];
    $estado['rotas'] = ['1' => ['1001', 'Rota 1001', '55']];
    $estado['motivos'] = ['3' => 'Sem dinheiro', '4' => 'Loja fechada'];
    $docs = [
        '501' => ['tipo' => 'FR', 'cliente' => '7001', 'nome' => 'Loja A', 'nif' => '5000', 'rota' => '1001', 'hora' => '15:09', 'total' => 1026.0],
        // venda sem visita (entrega) ao cliente D, com GPS no Pinguim BO
        '502' => ['tipo' => 'FR', 'cliente' => '7004', 'nome' => 'Loja D', 'nif' => '5004', 'rota' => '1002', 'hora' => '12:00', 'total' => 300.0],
        // consumidor final: fora do mapa, contado
        '503' => ['tipo' => 'FR', 'cliente' => '1', 'nome' => 'Consumidor Final', 'nif' => '999999999', 'rota' => '1001', 'hora' => '13:00', 'total' => 50.0],
        '504' => ['tipo' => 'NC', 'cliente' => '7001', 'nome' => 'Loja A', 'nif' => '5000', 'rota' => '1001', 'hora' => '16:00', 'total' => -100.0],
    ];
    $gps = ['7002' => [-8.90, 13.20, 'Loja B'], '7004' => ['-8,95', '13,25', 'Loja D']];
    $m = naval_vapp_montar($estado, $docs, $gps);
    $p = array_column($m['pontos'], null, 'k');
    $ks = array_column($m['pontos'], 'k');
    sort($ks);
    igual(['7001', '7002', '7004', 'app:12'], $ks, 'chaves em texto');
    igual(NAVAL_VAPP_VENDA, $p['7001']['e'], 'venda ganha à recusa');
    igual(2, $p['7001']['vis']);
    igual(1026.0, $p['7001']['vv'], 'valor do documento do Pinguim BO (c/ IVA)');
    igual(4.0, $p['7001']['cx']);
    igual('15:10', $p['7001']['h']);
    igual([-8.8385, 13.2345, 'v', 'p'], [$p['7001']['lat'], $p['7001']['lng'], $p['7001']['og'], $p['7001']['p']]);
    igual('Sem dinheiro', $p['7001']['m'], 'motivo da recusa guardado');
    igual('1001', $p['7001']['r']);
    igual([NAVAL_VAPP_EM_CURSO, -8.9, 13.2, 'c', 'g'], [$p['7002']['e'], $p['7002']['lat'], $p['7002']['lng'], $p['7002']['og'], $p['7002']['p']]);
    igual([NAVAL_VAPP_RECUSA, null, 'Loja fechada'], [$p['app:12']['e'], $p['app:12']['lat'], $p['app:12']['m']]);
    igual([NAVAL_VAPP_VENDA, true, 0, 300.0, -8.95, '1002'], [$p['7004']['e'], $p['7004']['sv'], $p['7004']['vis'], $p['7004']['vv'], $p['7004']['lat'], $p['7004']['r']]);
    $t = $m['totais'];
    igual([4, 4, 1, 1, 1, 1326.0], [$t['clientes'], $t['visitas'], $t['sem_gps'], $t['sem_pingwin'], $t['eventuais'], $t['valor']]);
    igual(['1' => 2, '3' => 0, '2' => 1, '0' => 1, '4' => 0], $t['estados']);
    igual('15:10', $m['pontos'][0]['h'], 'o mais recente primeiro');
    igual($m['versao'], naval_vapp_montar($estado, $docs, $gps)['versao'], 'mesma versão sem mudanças');
    verdade($m['versao'] !== naval_vapp_montar($estado, [], $gps)['versao'], 'versão muda com os dados');
});

teste('encomenda nunca soma às vendas; âmbito por rota e sem valores para quem não vê vendas', function () {
    $estado = naval_vapp_fundir([], [vapp_linha(['id' => 1, 'customer_id' => 10, 'end_hour' => '09:10', 'id_sell' => '601', 'total_sell' => 500])], '2026-11-28', true);
    $estado['clientes'] = ['10' => ['C10', 'Loja A', null, null, '7001']];
    $estado['rotas'] = ['1' => ['1001', 'Rota 1001', '']];
    $docs = ['601' => ['tipo' => 'ENC', 'cliente' => '7001', 'nome' => 'Loja A', 'nif' => '1', 'rota' => '1001', 'hora' => '09:09', 'total' => 500.0],
        '602' => ['tipo' => 'FR', 'cliente' => '7009', 'nome' => 'Loja Z', 'nif' => '2', 'rota' => '1002', 'hora' => '10:00', 'total' => 80.0]];
    $m = naval_vapp_montar($estado, $docs, [], ['ENC']);
    $p = array_column($m['pontos'], null, 'k');
    igual([NAVAL_VAPP_ENCOMENDA, 0.0, 500.0], [$p['7001']['e'], $p['7001']['vv'], $p['7001']['ve']]);
    igual(80.0, $m['totais']['valor'], 'só a venda');
    $a = naval_vapp_ambito($m, ['1001'], false);
    igual(['7001'], array_column($a['pontos'], 'k'));
    igual([null, null, null], [$a['pontos'][0]['vv'], $a['pontos'][0]['ve'], $a['totais']['valor']]);
    igual(1, $a['totais']['clientes']);
    igual($m, naval_vapp_ambito($m, null, true), 'sem âmbito nem restrição fica igual');
});
