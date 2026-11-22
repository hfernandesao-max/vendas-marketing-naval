<?php
// Eventos entre módulos: só os declarados publicam; os ouvintes correm só nos módulos ligados que os escutam.
naval_servico('eventos');

teste('eventos: publica os declarados, chama os ouvintes dos módulos ligados que escutam, nunca trava quem publica', function () {
    $chamados = [];
    $GLOBALS['ev_ouvinte'] = function ($nome, $dados) use (&$chamados) { $chamados[] = [$nome, $dados['rota'] ?? '']; };
    $ouvintes = ['carrinha.descarga_conciliada' => [
        ['relatorio-vendas', '', $GLOBALS['ev_ouvinte']],
        ['relatorio-vendas', '', function () { throw new RuntimeException('falha do ouvinte'); }],
        ['clientes', '', $GLOBALS['ev_ouvinte']],   // não escuta este evento
    ]];
    $ligados = array_fill_keys(array_keys(naval_modulos()), 'ligado');
    igual(1, naval_evento('carrinha.descarga_conciliada', ['rota' => '1004'], ['ouvintes' => $ouvintes, 'estados' => $ligados, 'registar' => false]));
    igual([['carrinha.descarga_conciliada', '1004']], $chamados);
    // módulo desligado: o ouvinte não corre
    $chamados = [];
    igual(0, naval_evento('carrinha.descarga_conciliada', ['rota' => '1004'], ['ouvintes' => $ouvintes, 'estados' => ['relatorio-vendas' => 'desligado'] + $ligados, 'registar' => false]));
    igual([], $chamados);
    // evento que nenhum módulo declara: erro de programação
    $erro = null;
    try {
        naval_evento('nao.existe', [], ['registar' => false]);
    } catch (LogicException $e) {
        $erro = $e;
    }
    verdade($erro !== null);
});

teste('eventos: todos os ouvintes do mapa escutam eventos declarados por algum módulo e estão na ficha de quem escuta', function () {
    $decl = naval_eventos_declarados();
    $mods = naval_modulos();
    foreach (NAVAL_EVENTOS_OUVINTES as $ev => $ls) {
        verdade(isset($decl[$ev]), 'declarado: ' . $ev);
        foreach ($ls as [$m]) verdade(in_array($ev, (array) ($mods[$m]['eventos']['escuta'] ?? []), true), $m . ' escuta ' . $ev);
    }
});

teste('relatório de vendas: o fecho de uma carrinha ou da caixa pede ao ciclo que veja o relatório já', function () {
    naval_servico('relatorio-vendas');
    @unlink(naval_pasta('relatorio-vendas') . '/_rever.json');
    $agora = strtotime('2030-01-10 18:07:00');   // fora dos quartos de hora
    igual(false, naval_rv_rever_agora($agora));
    naval_rv_evento_rever('caixa.aprovada', ['dia' => '2030-01-10']);
    igual(true, naval_rv_rever_agora($agora));
    igual(false, naval_rv_rever_agora($agora), 'o pedido consome-se');
    igual(true, naval_rv_rever_agora(strtotime('2030-01-10 18:16:00')), 'de 15 em 15 minutos');
});
