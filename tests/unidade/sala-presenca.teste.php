<?php
// Presença dos telemóveis na sala de resultados (nucleo/servicos/sala-presenca.php): validação dos eventos da app,
// quem está na sala agora, saída e o modo quiosque (só quando o portal e a app estão nesse modo).
naval_servico('sala-presenca');

teste('sala: entrada e saída de um telemóvel; eventos inválidos recusados', function () {
    @unlink(naval_sala_pasta() . '/agora.json');
    lanca(400, function () { naval_sala_presenca(['evento' => 'abrir_tudo', 'app' => 'abc123def']); });
    lanca(400, function () { naval_sala_presenca(['evento' => 'entrada', 'app' => 'x']); });
    lanca(400, function () { naval_sala_presenca(['evento' => 'entrada', 'app' => 'abc123def', 'dispositivo_mdm' => '12; drop']); });
    $r = naval_sala_presenca(['evento' => 'entrada', 'app' => 'abc123def', 'modelo' => 'BV6200 Pro', 'modo' => 'app', 'wifi_sala' => 'true', 'winrest_aberto' => 'true']);
    igual(['na_sala' => true, 'kiosk_ate' => null], $r);
    igual(['abc123def'], array_keys(naval_sala_presentes()));
    igual('BV6200 Pro', naval_sala_presentes()['abc123def']['modelo']);
    // o registo do dia guarda cada evento
    $linhas = file(naval_sala_pasta() . '/' . date('Y-m-d') . '.jsonl', FILE_IGNORE_NEW_LINES);
    verdade(strpos((string) end($linhas), '"winrest_aberto":true') !== false);
    igual(['na_sala' => false], naval_sala_presenca(['evento' => 'saida', 'app' => 'abc123def']));
    igual([], naval_sala_presentes());
});

teste('sala: chegadas para o LCD — sincronização confirmada pelo Pinguim BO depois da entrada, a aguardar ou em falta; rede e rota', function () {
    $agora = strtotime('2026-11-27 15:20:00');
    $ev = [
        ['em' => '2026-11-27 15:05:00', 'evento' => 'entrada', 'rota' => '1001', 'rede' => 'wifi', 'dados_moveis' => true, 'winrest_aberto' => true],
        ['em' => '2026-11-27 15:08:00', 'evento' => 'entrada', 'rota' => '1005', 'rede' => 'dados_moveis', 'dados_moveis' => true],
        ['em' => '2026-11-27 15:17:00', 'evento' => 'entrada', 'rota' => '1006', 'rede' => 'wifi'],
        ['em' => '2026-11-27 15:09:00', 'evento' => 'entrada', 'rota' => '', 'rede' => 'wifi'],      // telemóvel sem rota configurada
        ['em' => '2026-11-27 15:12:00', 'evento' => 'saida', 'rota' => '1001'],
        ['em' => '2026-11-27 07:40:00', 'evento' => 'entrada', 'rota' => '1001', 'rede' => 'wifi'],  // de manhã: conta a última entrada
    ];
    $sincs = ['1001' => ['2026-11-27 07:47:00', '2026-11-27 15:06:00'], '1005' => ['2026-11-27 13:56:00'], '1006' => []];
    $c = naval_sala_chegadas($ev, $sincs, ['1001' => 'Andrea Santos'], $agora);
    $l = array_column($c['linhas'], null, 'rota');
    igual(['sincronizou', '2026-11-27 15:06:00', 1, 'Andrea Santos'], [$l['1001']['estado'], $l['1001']['sincronizou'], $l['1001']['minutos'], $l['1001']['promotor']]);
    igual(['sem_sincronizar', 'dados_moveis', '2026-11-27 13:56:00'], [$l['1005']['estado'], $l['1005']['rede'], $l['1005']['ultima_sinc']], 'a sincronização das 13:56 é de antes de entrar');
    igual('a_aguardar', $l['1006']['estado'], 'entrou há 3 minutos');
    igual([3, 1, 1, 1], [$c['entraram'], $c['sincronizaram'], $c['em_falta'], $c['sem_rota']]);
    igual(['1006', '1005', '1001'], array_column($c['linhas'], 'rota'), 'as entradas mais recentes primeiro');
    // a app envia a rota e a rede; valores fora da lista ficam vazios
    @unlink(naval_sala_pasta() . '/agora.json');
    naval_sala_presenca(['evento' => 'entrada', 'app' => 'tel000002', 'rota' => '1003', 'rede' => 'wifi_sem_internet', 'dados_moveis' => 'true']);
    naval_sala_presenca(['evento' => 'entrada', 'app' => 'tel000003', 'rota' => '1; drop', 'rede' => 'qualquer']);
    $hoje = naval_sala_eventos(date('Y-m-d'));
    $u = array_slice($hoje, -2);
    igual([['1003', 'wifi_sem_internet', true], ['', '', false]], array_map(function ($x) { return [$x['rota'], $x['rede'], $x['dados_moveis']]; }, $u));
    igual('1003', naval_sala_presentes()['tel000002']['rota']);
});

teste('sala: modo quiosque só com o portal em SALA_MODO=kiosk; sem o perfil configurado fica registado o erro e a presença continua', function () {
    @unlink(naval_sala_pasta() . '/agora.json');
    // o portal de testes está em modo «app» (por omissão): o pedido de quiosque da app é ignorado
    $r = naval_sala_presenca(['evento' => 'entrada', 'app' => 'tel000001', 'modo' => 'kiosk', 'dispositivo_mdm' => '901']);
    igual(null, $r['kiosk_ate']);
    verdade(!isset(naval_sala_presentes()['tel000001']['kiosk_erro']));
    // o perfil sem configurar recusa-se com uma mensagem clara (o portal nunca chama o MDM sem o perfil definido)
    $e = lanca(503, function () { naval_sala_kiosk('901', true); });
    verdade(strpos($e->getMessage(), 'SALA_MDM_PERFIL_KIOSK') !== false);
    igual(0, naval_sala_tick());
});
