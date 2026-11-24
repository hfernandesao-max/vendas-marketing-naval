<?php
// Facturas fora do horário de vendas (nucleo/servicos/fora-horario.php): classificação, autorizações e indicador.
naval_servico('fora-horario');

teste('fora do horário: depois do fim das vendas (com tolerância), antes do início e em dia de descanso; notas de crédito não contam', function () {
    $janela = function (string $d) { return $d === '2026-11-29' ? null : ($d === '2026-11-28' ? ['07:30', '12:00'] : ['07:30', '15:00']); };
    $v = function ($doc, $dia, $hora, $rota = '1009', $total = 1000.0, $nc = false) {
        return ['documento' => $doc, 'data' => $dia, 'hora' => $hora, 'rota' => $rota, 'promotor' => 'P' . $rota, 'cliente' => 'C', 'total' => $total, 'nc' => $nc];
    };
    $vendas = [
        $v('FR 1', '2026-11-25', '14:59'),                  // dentro
        $v('FR 2', '2026-11-25', '15:10'),                  // dentro da tolerância (10 min)
        $v('FR 13', '2026-11-25', '16:18', '1009', 1231557.0),
        $v('FR 14', '2026-11-25', '16:42', '1009', 741379.5),
        $v('FR 3', '2026-11-25', '06:50', '1010'),          // antes do início
        $v('FR 4', '2026-11-28', '12:30', '1011'),          // sábado: vendas até às 12:00
        $v('FR 5', '2026-11-29', '10:00', '1011'),          // domingo
        $v('NC 1', '2026-11-25', '17:00', '1009', -500.0, true),
    ];
    $l = naval_fh_classificar($vendas, $janela, [], 10);
    igual(['FR 5', 'FR 4', 'FR 14', 'FR 13', 'FR 3'], array_column($l, 'documento'));
    igual(['descanso', 'depois', 'depois', 'depois', 'antes'], array_column($l, 'motivo'));
    igual([102, 78], [$l[2]['minutos'], $l[3]['minutos']], 'minutos depois das 15:00');
    // autorização do coordenador: a rota 1009 pode facturar até às 16:30 nesse dia → a FR 13 fica autorizada, a FR 14 não
    $a = [['rota' => '1009', 'dia' => '2026-11-25', 'ate' => '16:30', 'motivo' => 'Entrega combinada', 'por' => 'coord']];
    $l = naval_fh_classificar($vendas, $janela, $a, 10);
    $porDoc = array_column($l, null, 'documento');
    igual([true, false], [$porDoc['FR 13']['autorizada'], $porDoc['FR 14']['autorizada']]);
    igual('Entrega combinada', $porDoc['FR 13']['autorizacao']['motivo']);
    // indicador por promotor: só as não autorizadas contam
    $p = naval_fh_por_promotor($l);
    $porRota = array_column($p, null, 'rota');
    igual([1, 741379.5, 1, 1, '16:42'], [$porRota['1009']['facturas'], $porRota['1009']['valor'], $porRota['1009']['autorizadas'], $porRota['1009']['dias'], $porRota['1009']['mais_tarde']]);
    igual(2, $porRota['1011']['facturas']);
    igual('1011', $p[0]['rota'], 'pior primeiro');
});

teste('fora do horário: autorização só com rota, dia de hoje em diante, hora e motivo', function () {
    $dia = date('Y-m-d');
    lanca(400, function () use ($dia) { naval_fh_autorizar('9999', $dia, '17:00', 'Entrega combinada', 'c'); });
    lanca(400, function () { naval_fh_autorizar('1009', date('Y-m-d', time() - 86400), '17:00', 'Entrega combinada', 'c'); });
    lanca(400, function () use ($dia) { naval_fh_autorizar('1009', $dia, '25:00', 'Entrega combinada', 'c'); });
    lanca(400, function () use ($dia) { naval_fh_autorizar('1009', $dia, '17:00', 'ok', 'c'); });
    $a = naval_fh_autorizar('1009', $dia, '17:00', 'Entrega combinada com o cliente', 'coord');
    igual(['1009', $dia, '17:00', 'coord'], [$a['rota'], $a['dia'], $a['ate'], $a['por']]);
    igual(1, count(array_filter(naval_fh_autorizacoes($dia, $dia), function ($x) use ($a) { return $x['id'] === $a['id']; })));
});

teste('fora do horário: factura emitida num dia posterior à sua data (turno do Winrest aberto) — rota 1002, 19-11 emitida a 23-11', function () {
    $janela = function (string $d) { return ['07:30', '15:00']; };
    $vendas = [
        ['documento' => 'FR 14', 'data' => '2026-11-19', 'emitida' => '2026-11-23', 'hora' => '10:31', 'rota' => '1002', 'promotor' => 'P', 'cliente' => 'C', 'total' => 90300.0],
        ['documento' => 'FR 20', 'data' => '2026-11-23', 'hora' => '10:40', 'rota' => '1002', 'promotor' => 'P', 'cliente' => 'C', 'total' => 1000.0],
    ];
    // a autorização de venda fora de horas não cobre a data errada
    $l = naval_fh_classificar($vendas, $janela, [['rota' => '1002', 'dia' => '2026-11-19', 'ate' => '23:59', 'motivo' => 'x', 'por' => 'c']], 10);
    igual(1, count($l));
    igual(['FR 14', 'turno_aberto', '2026-11-23', false], [$l[0]['documento'], $l[0]['motivo'], $l[0]['emitida'], $l[0]['autorizada']]);
    igual(4 * 1440, $l[0]['minutos']);
});
