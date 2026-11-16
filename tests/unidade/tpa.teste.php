<?php
// Multicaixa por TPA (nucleo/servicos/tpa.php): registo dos terminais, talões de fecho na folha de caixa (TPA +
// período únicos), comparação com o Pinguim BO, totais por banco e conta, pagamentos a rever e resumo de fim de dia.
naval_servico('caixa');
naval_servico('tpa');

function tpa_limpar(): void
{
    foreach (['terminais.json', 'conferencias.json', 'config.json'] as $f) @unlink(naval_tpa_pasta() . '/' . $f);
}

teste('TPA: registo validado (8 dígitos, empresa, banco); sem registo a folha de caixa fica como antes', function () {
    tpa_limpar();
    verdade(!naval_tpa_em_uso(), 'sem terminais');
    lanca(400, function () { naval_tpa_gravar(['tpa' => '4441', 'empresa' => 'NAVAL', 'banco' => 'BPC'], 'fin'); });
    lanca(400, function () { naval_tpa_gravar(['tpa' => '00444134', 'empresa' => 'OUTRA', 'banco' => 'BPC'], 'fin'); });
    lanca(400, function () { naval_tpa_gravar(['tpa' => '00444134', 'empresa' => 'NAVAL', 'banco' => 'XPTO'], 'fin'); });
    $t = naval_tpa_gravar(['tpa' => '0044 4134', 'estab' => '0000287047', 'empresa' => 'naval', 'banco' => 'BPC', 'conta' => '0065023555 06011', 'rota' => '1004'], 'fin');
    igual(['00444134', 'NAVAL', '0065023555 06011', '1004', true], [$t['tpa'], $t['empresa'], $t['conta'], $t['rota'], $t['activo']]);
    naval_tpa_gravar(['tpa' => '00445736', 'empresa' => 'HIGITEC', 'banco' => 'BPC', 'rota' => '1007'], 'fin');
    igual(['00444134', '00445736'], array_keys(naval_tpa_terminais()));
    verdade(naval_tpa_em_uso(), 'com terminais');
    tpa_limpar();
});

teste('TPA: talão de fecho na folha de caixa com TPA, período e total; banco e conta do registo; TPA/período únicos', function () {
    tpa_limpar();
    naval_tpa_gravar(['tpa' => '00444134', 'empresa' => 'NAVAL', 'banco' => 'BPC', 'conta' => '0065023555 06011', 'rota' => '1004'], 'fin');
    naval_tpa_gravar(['tpa' => '00445736', 'empresa' => 'HIGITEC', 'banco' => 'BPC', 'rota' => '1007', 'activo' => false], 'fin');
    $d1 = date('Y-m-d', time() - 12 * 86400);
    $d2 = date('Y-m-d', time() - 11 * 86400);
    foreach ([$d1, $d2] as $d) @unlink(naval_caixa_ficheiro($d));
    lanca(400, function () use ($d1) { naval_caixa_gravar($d1, ['tpa' => [['rota' => '1004', 'tpa' => '00000001', 'periodo' => '1', 'valor' => 10]]], 0, 'josefa'); });   // não registado
    lanca(400, function () use ($d1) { naval_caixa_gravar($d1, ['tpa' => [['rota' => '1007', 'tpa' => '00445736', 'periodo' => '1', 'valor' => 10]]], 0, 'josefa'); });   // desactivado
    lanca(400, function () use ($d1) { naval_caixa_gravar($d1, ['tpa' => [['rota' => '1004', 'tpa' => '00444134', 'periodo' => '', 'valor' => 10]]], 0, 'josefa'); });   // sem período
    lanca(400, function () use ($d1) {
        naval_caixa_gravar($d1, ['tpa' => [['rota' => '1004', 'tpa' => '00444134', 'periodo' => '104', 'valor' => 10], ['rota' => '1004', 'tpa' => '00444134', 'periodo' => '0104', 'valor' => 5]]], 0, 'josefa');
    });
    $c = naval_caixa_gravar($d1, ['saldo_inicial' => 0, 'tpa' => [['rota' => '1004', 'tpa' => '00444134', 'periodo' => '0104', 'operacoes' => '2', 'valor' => 20000, 'banco' => 'BAI']]], 0, 'josefa');
    $l = $c['tpa'][0];
    igual(['00444134', '104', 2, 'BPC', 'NAVAL', '0065023555 06011', '00444134/104', 20000.0], [$l['tpa'], $l['periodo'], $l['operacoes'], $l['banco'], $l['empresa'], $l['conta'], $l['talao'], $l['valor']]);
    // o mesmo fecho noutro dia: recusado
    $e = lanca(400, function () use ($d2) { naval_caixa_gravar($d2, ['tpa' => [['rota' => '1004', 'tpa' => '00444134', 'periodo' => '104', 'valor' => 10]]], 0, 'josefa'); });
    verdade(strpos($e->getMessage(), 'já foi registado') !== false, 'repetido noutro dia');
    foreach ([$d1, $d2] as $d) @unlink(naval_caixa_ficheiro($d));
    tpa_limpar();
});

teste('TPA: relatório — fecho × Pinguim BO por rota, sem fecho, totais por banco e conta, conferido e a rever', function () {
    $ter = [
        '00444134' => ['tpa' => '00444134', 'empresa' => 'NAVAL', 'banco' => 'BPC', 'conta' => 'C1', 'rota' => '1004', 'activo' => true, 'estab' => ''],
        '00516385' => ['tpa' => '00516385', 'empresa' => 'NAVAL', 'banco' => 'BAI', 'conta' => 'C2', 'rota' => '1011', 'activo' => true, 'estab' => ''],
        '00517752' => ['tpa' => '00517752', 'empresa' => 'NAVAL', 'banco' => 'ATLANTICO', 'conta' => 'C3', 'rota' => '1005', 'activo' => true, 'estab' => ''],
    ];
    $caixas = ['2026-11-13' => ['estado' => 'aprovada', 'tpa' => [
        ['rota' => '1004', 'tpa' => '00444134', 'periodo' => '103', 'operacoes' => 6, 'banco' => 'BPC', 'empresa' => 'NAVAL', 'conta' => 'C1', 'valor' => 845000],
        ['rota' => '1011', 'tpa' => '00516385', 'periodo' => '87', 'operacoes' => 4, 'banco' => 'BAI', 'empresa' => 'NAVAL', 'conta' => 'C2', 'valor' => 412000],
        ['rota' => '1002', 'banco' => 'BPC', 'talao' => 'antigo', 'valor' => 1],   // talão antigo: fica de fora
    ]]];
    $pinguim = ['2026-11-13' => ['1004' => 845000, '1011' => 452000, '1005' => 25000]];
    $conf = ['2026-11-13|00444134|103' => ['ref' => 'MOV-1', 'data' => '2026-11-16']];
    $pag = [
        ['dia' => '2026-11-13', 'rota' => '1011', 'documento' => 'FR 1', 'valor' => 40000, 'ref' => ''],
        ['dia' => '2026-11-13', 'rota' => '1011', 'documento' => 'FR 2', 'valor' => 12000, 'ref' => '87/003'],
        ['dia' => '2026-11-13', 'rota' => '1011', 'documento' => 'FR 3', 'valor' => 9000, 'ref' => '8703'],
        ['dia' => '2026-11-13', 'rota' => '1004', 'documento' => 'FR 4', 'valor' => 5000, 'ref' => '102/001'],
    ];
    $r = naval_tpa_montar($caixas, $pinguim, $ter, $conf, $pag);
    igual(2, count($r['fechos']));
    igual([-40000.0, 0.0], array_column($r['fechos'], 'diferenca'));   // por empresa, banco e TPA: BAI antes de BPC
    igual(['ref' => 'MOV-1', 'data' => '2026-11-16'], $r['fechos'][1]['conferido'] ?? $r['fechos'][0]['conferido']);
    igual([['dia' => '2026-11-13', 'rota' => '1005', 'pinguim' => 25000.0, 'tpa' => '00517752']], $r['sem_fecho']);
    igual(['fechos' => 1257000.0, 'pinguim' => 1322000.0, 'diferenca' => -65000.0, 'conferido' => 845000.0, 'por_conferir' => 412000.0, 'n_fechos' => 2, 'n_conferidos' => 1, 'operacoes' => 10], $r['totais']);
    igual(2, count($r['bancos']));
    igual([['FR 1', 'sem_referencia'], ['FR 3', 'formato'], ['FR 4', 'fora_do_periodo']], array_map(function ($x) { return [$x['documento'], $x['motivo']]; }, $r['rever']));
    // sem a coluna da referência: «a rever» fica por fazer (null)
    verdade(naval_tpa_montar($caixas, $pinguim, $ter, $conf, null)['rever'] === null, 'sem referências');
    // resumo de fim de dia: os valores, o banco, a conta e o que está a rever
    $h = naval_tpa_resumo_html('2026-11-13', ['fechada_por' => 'josefa', 'aprovada_por' => 'dir', 'total_entregas' => 2150000, 'total_saidas' => 2150000, 'contagem_fisica' => 120000], $r, 'https://x/reports/#multicaixa-tpa');
    foreach (['Fecho de caixa de 13-11-2026', '1 257 000,00 Kz', 'BAI', '00516385 · 87', 'diferença de -40 000,00 Kz', 'Rota 1005', 'Abrir «Multicaixa por TPA»'] as $txt) verdade(strpos($h, $txt) !== false, $txt);
    verdade(strpos($h, '<script') === false, 'sem código');
});

teste('TPA: conferência com o extracto e destinatários do resumo', function () {
    tpa_limpar();
    lanca(400, function () { naval_tpa_conferir('2026-11-13', '00444134', '103', true, '', '2026-11-16', 'fin'); });
    $f = naval_tpa_conferir('2026-11-13', '00444134', '103', true, 'MOV-1', '2026-11-16', 'fin');
    igual('MOV-1', $f['2026-11-13|00444134|103']['ref']);
    igual([], naval_tpa_conferir('2026-11-13', '00444134', '103', false, '', '', 'fin'));
    lanca(400, function () { naval_tpa_destinatarios(['nao-e-email'], 'fin'); });
    igual(['financeiro@naval.co.ao', 'vanda@naval.co.ao'], naval_tpa_destinatarios(['Financeiro@naval.co.ao', 'vanda@naval.co.ao', 'financeiro@naval.co.ao'], 'fin'));
    tpa_limpar();
});
