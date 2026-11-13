<?php
// Calendário de trabalho das vendas (nucleo/servicos/calendario.php): horário, folgas, feriados e fecho do dia.
naval_servico('calendario');

teste('calendário: segunda a sexta 07:30–15:00, sábado 07:30–13:00 (meio dia), domingo folga; fecho e carga a seguir', function () {
    $q = naval_cal_dia('2026-11-12');   // quinta-feira
    igual('completo', $q['tipo']);
    igual(['07:30', '15:00'], $q['vendas']);
    igual(['15:30', '16:00'], $q['fecho'], 'contas com a tesouraria, contagem e requisição');
    igual(['16:00', '17:00'], $q['carga'], 'carregamento da mercadoria');
    igual(1.0, $q['peso']);
    $s = naval_cal_dia('2026-11-14');   // sábado
    igual('meio', $s['tipo']);
    igual(['07:30', '13:00'], $s['vendas']);
    igual(['13:30', '14:00'], $s['fecho'], 'ao sábado o fecho segue o fim das vendas');
    igual(['14:00', '15:00'], $s['carga']);
    igual(round(5.5 / 7.5, 3), $s['peso']);
    $d = naval_cal_dia('2026-11-15');
    igual(['folga', 'domingo', 0.0], [$d['tipo'], $d['motivo'], $d['peso']]);
});

teste('calendário: feriados nacionais de Angola (fixos e móveis pela Páscoa)', function () {
    igual('2026-04-05', naval_cal_pascoa(2026));
    $f = naval_cal_feriados(2026);
    igual('Carnaval', $f['2026-02-17'] ?? null);
    igual('Sexta-feira Santa', $f['2026-04-03'] ?? null);
    igual(12, count($f), '12 feriados nacionais em 2026');
    $i = naval_cal_dia('2026-11-11');
    igual(['folga', 'feriado', 'Dia da Independência Nacional'], [$i['tipo'], $i['motivo'], $i['feriado']]);
    igual('2027-03-28', naval_cal_pascoa(2027));
});

teste('calendário: fases do dia para os prazos do fecho', function () {
    igual('antes', naval_cal_fase(strtotime('2026-11-12 07:00')));
    igual('vendas', naval_cal_fase(strtotime('2026-11-12 10:00')));
    igual('intervalo', naval_cal_fase(strtotime('2026-11-12 15:10')));
    igual('fecho', naval_cal_fase(strtotime('2026-11-12 15:45')));
    igual('carga', naval_cal_fase(strtotime('2026-11-12 16:30')));
    igual('fim', naval_cal_fase(strtotime('2026-11-12 17:05')));
    igual('fecho', naval_cal_fase(strtotime('2026-11-14 13:40')), 'sábado');
    igual('folga', naval_cal_fase(strtotime('2026-11-15 10:00')));
    igual(0.0, naval_cal_peso('2026-11-11'), 'feriado sem peso');
});

teste('previsão: dia de folga prevê zero e os feriados passados não entram na média', function () {
    naval_servico('previsao');
    $serie = [];
    foreach (naval_analise_dias('2026-09-01', '2026-11-10') as $d) $serie[$d] = naval_dia_semana($d) === 7 ? 0.0 : 100000.0;
    $serie['2026-11-02'] = 0.0;   // Dia dos Finados (segunda-feira)
    igual(0.0, naval_previsao_dia($serie, '2026-11-11', '2026-11-10')['previsto'], 'feriado');
    $seg = naval_previsao_dia($serie, '2026-11-16', '2026-11-10');
    verdade(abs($seg['previsto'] - 100000) < 15000, 'segunda-feira: o zero do feriado de 02-11 não puxa a média para baixo (' . $seg['previsto'] . ')');
});
