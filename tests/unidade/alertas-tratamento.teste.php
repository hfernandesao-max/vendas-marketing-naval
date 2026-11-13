<?php
// Tratamento dos alertas (nucleo/servicos/alertas-tratamento.php): assumir, resolver com nota, silenciar até uma
// data com motivo, reabrir; resolvido que continua reabre em 24 h; silêncio acaba sozinho; tudo com histórico.
naval_servico('alertas-tratamento');

teste('alertas: assumir, resolver (nota obrigatória), reabrir sozinho em 24 h, silenciar até uma data', function () {
    @unlink(naval_alt_ficheiro());
    $t0 = strtotime('2026-11-13 09:00');
    $al = [['id' => 'agt|1005', 'gravidade' => 'critico'], ['id' => 'mdm|X1', 'gravidade' => 'aviso'], ['id' => 'cliente|C9', 'gravidade' => 'aviso']];
    try {
        $v = naval_alt_aplicar($al, $t0);
        igual(['novo', 'novo', 'novo'], array_column(array_column($v, 'tratamento'), 'estado'));
        igual([true, true, true], array_column($v, 'activo'));
        // assumir: continua activo, com quem o tem
        naval_alt_tratar('agt|1005', 'assumir', '', null, 'michael', $t0);
        $v = naval_alt_aplicar($al, $t0 + 60);
        igual(['assumido', 'michael', true], [$v[0]['tratamento']['estado'], $v[0]['tratamento']['por'], $v[0]['activo']]);
        // resolver exige a nota do que foi feito
        lanca(400, function () use ($t0) { naval_alt_tratar('agt|1005', 'resolver', 'ok', null, 'michael', $t0); });
        naval_alt_tratar('agt|1005', 'resolver', 'Documentos comunicados à AGT pelo Pinguim BO.', null, 'michael', $t0 + 120);
        $v = naval_alt_aplicar($al, $t0 + 3600);
        igual(['resolvido', false], [$v[0]['tratamento']['estado'], $v[0]['activo']]);
        // o problema continua 24 h depois: reabre sozinho
        $v = naval_alt_aplicar($al, $t0 + 120 + 25 * 3600);
        igual(['reaberto', true], [$v[0]['tratamento']['estado'], $v[0]['activo']]);
        // silenciar: motivo e data (até 30 dias) obrigatórios
        lanca(400, function () use ($t0) { naval_alt_tratar('mdm|X1', 'silenciar', 'Telemóvel em reparação', null, 'sara', $t0); });
        lanca(400, function () use ($t0) { naval_alt_tratar('mdm|X1', 'silenciar', 'Telemóvel em reparação', '2027-01-30', 'sara', $t0); });
        naval_alt_tratar('mdm|X1', 'silenciar', 'Telemóvel em reparação', '2026-11-20', 'sara', $t0);
        $v = naval_alt_aplicar($al, strtotime('2026-11-20 18:00'));
        igual(['silenciado', false], [$v[1]['tratamento']['estado'], $v[1]['activo']]);
        // acabou o silêncio: volta a novo e activo
        $v = naval_alt_aplicar($al, strtotime('2026-11-21 08:00'));
        igual(['novo', true], [$v[1]['tratamento']['estado'], $v[1]['activo']]);
        // histórico completo: quem, quando, nota
        $h = naval_alt_ler()['agt|1005']['hist'];
        igual(['assumir', 'resolver'], array_column($h, 'accao'));
        igual('Documentos comunicados à AGT pelo Pinguim BO.', $h[1]['nota']);
        // acções inválidas
        lanca(400, function () use ($t0) { naval_alt_tratar('agt|1005', 'apagar', '', null, 'x', $t0); });
        lanca(400, function () use ($t0) { naval_alt_tratar('<script>', 'assumir', '', null, 'x', $t0); });
    } finally {
        @unlink(naval_alt_ficheiro());
    }
});
