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
