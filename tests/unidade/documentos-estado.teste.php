<?php
// Documentos válidos e anulados (nucleo/servicos/documentos-estado.php): estado de cada linha, motivo da anulação,
// cliente só para quem vê os clientes e totais por documento (por promotor e por motivo).
naval_servico('documentos-estado');

teste('documentos válidos e anulados: estado pelo cancelamento, pelo motivo ou pelo estado configurado; totais por documento', function () {
    $l = [
        // FR com duas linhas, válida
        ['DOC_ID' => 1, 'DIA' => '2026-11-26', 'HORA' => '2026-11-26 10:15:00', 'DOC' => 'FR FR0126S77768N/54', 'TIPO' => 'FR', 'TIPO_NOME' => 'Factura-recibo', 'ROTA' => '1005',
            'LOJA' => '1005 - FHR PIV', 'CODIGO' => '10000332', 'ARTIGO' => 'FRALDAS NAVAL', 'QNT' => 100, 'VALOR' => 1500000, 'CANCELADO' => 0, 'ESTADO_DOCUMENTO' => 8002,
            'ENTIDADE' => 'CLIENTE A', 'VENDA' => 1, 'MOTIVO' => null, 'MOTIVO_EM' => null],
        ['DOC_ID' => 1, 'DIA' => '2026-11-26', 'HORA' => '2026-11-26 10:15:00', 'DOC' => 'FR FR0126S77768N/54', 'TIPO' => 'FR', 'TIPO_NOME' => 'Factura-recibo', 'ROTA' => '1005',
            'LOJA' => '1005 - FHR PIV', 'CODIGO' => '10000385', 'ARTIGO' => 'SABÃO', 'QNT' => 5, 'VALOR' => 137500, 'CANCELADO' => 0, 'ESTADO_DOCUMENTO' => 8002,
            'ENTIDADE' => 'CLIENTE A', 'VENDA' => 1],
        // anulada com motivo (escolhido no telemóvel)
        ['DOC_ID' => 2, 'DIA' => '2026-11-27', 'HORA' => '2026-11-27 09:02:00', 'DOC' => 'FR FR0126S77768N/60', 'TIPO' => 'FR', 'TIPO_NOME' => 'Factura-recibo', 'ROTA' => '',
            'LOJA' => '1005 - FHR PIV', 'CODIGO' => '10000015', 'ARTIGO' => 'FRALDAS MIMO', 'QNT' => 50, 'VALOR' => 700000, 'CANCELADO' => 1, 'ESTADO_DOCUMENTO' => 8002,
            'ENTIDADE' => 'CLIENTE B', 'VENDA' => 1, 'MOTIVO' => 'Armazém errado', 'MOTIVO_EM' => '2026-11-27 09:12:44'],
        // anulada só pelo estado configurado (PINGUIM_ESTADO_ANULADO)
        ['DOC_ID' => 3, 'DIA' => '2026-11-27', 'HORA' => '2026-11-27 11:00:00', 'DOC' => 'FR FR0126S78519N/40', 'TIPO' => 'FR', 'TIPO_NOME' => 'Factura-recibo', 'ROTA' => '1001',
            'LOJA' => '1001 - FHR PIV', 'CODIGO' => '10000332', 'ARTIGO' => 'FRALDAS NAVAL', 'QNT' => 10, 'VALOR' => 150000, 'CANCELADO' => 0, 'ESTADO_DOCUMENTO' => 8009,
            'ENTIDADE' => 'CLIENTE C', 'VENDA' => 1],
    ];
    $x = naval_docest_linhas($l, ['1005' => 'Olindo Baião', '1001' => 'Andrea Santos'], [8009], false);
    igual(['valido', 'valido', 'anulado', 'anulado'], array_column($x, 'estado'));
    igual(['1005', 'Olindo Baião', 'Armazém errado', '2026-11-27 09:12'], [$x[2]['rota'], $x[2]['promotor'], $x[2]['motivo'], $x[2]['anulado_em']], 'rota pela descrição da loja');
    igual('', $x[0]['cliente'], 'sem permissão de clientes: sem o nome do cliente das vendas');
    igual('CLIENTE A', naval_docest_linhas([$l[0]], [], [], true)[0]['cliente']);
    $r = naval_docest_resumo($x);
    igual([3, 4, 1, 2, 1637500.0, 850000.0], [$r['resumo']['documentos'], $r['resumo']['linhas'], $r['resumo']['validos'], $r['resumo']['anulados'],
        $r['resumo']['valor_valido'], $r['resumo']['valor_anulado']], 'por documento: a FR de duas linhas conta uma vez');
    $p = array_column($r['por_promotor'], null, 'rota');
    igual([2, 1, 0.5], [$p['1005']['documentos'], $p['1005']['anulados'], $p['1005']['pct_anulados']]);
    igual([['Armazém errado', 1], ['Sem motivo registado', 1]], array_map(function ($m) { return [$m['motivo'], $m['documentos']]; }, $r['por_motivo']));
    // período: até 366 dias
    lanca(400, function () { naval_docest_periodo('2025-01-01', '2026-11-27'); });
    igual(['2026-11-20', '2026-11-27'], naval_docest_periodo('2026-11-20', '2026-11-27'));
    igual([], naval_docest_tipos('todos'), 'todos os tipos');
    verdade(in_array('NC', naval_docest_tipos('vendas'), true) && in_array('GTC', naval_docest_tipos('cargas'), true));
});
