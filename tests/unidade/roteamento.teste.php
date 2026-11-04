<?php
// Optimização automática das rotas: sequência (vizinho mais próximo, 2-opt, Or-opt), horas, jornada e plano do dia.
naval_servico('prospeccao');
naval_servico('roteamento');
naval_servico('carteira');

/** Pontos numa linha recta (de oeste para leste), baralhados. */
function rot_pontos(): array
{
    $pts = [];
    foreach ([3, 0, 5, 1, 4, 2] as $i) $pts[] = ['lat' => -8.80, 'lng' => 13.20 + $i * 0.01, 'i' => $i];
    return $pts;
}

teste('Rotas: sequência óptima numa linha, a partir do armazém e sem ele', function () {
    $pts = rot_pontos();
    // sem ponto de partida: caminho aberto de uma ponta à outra
    $o = naval_rot_sequencia($pts, null, false, 1.0);
    $seq = array_map(function ($k) use ($pts) { return $pts[$k]['i']; }, $o);
    verdade($seq === [0, 1, 2, 3, 4, 5] || $seq === [5, 4, 3, 2, 1, 0], 'percorre a linha sem voltar atrás: ' . implode(',', $seq));
    // armazém a oeste: começa no mais próximo e vai para leste
    $o = naval_rot_sequencia($pts, ['lat' => -8.80, 'lng' => 13.19], true, 1.0);
    igual([0, 1, 2, 3, 4, 5], array_map(function ($k) use ($pts) { return $pts[$k]['i']; }, $o));
    // nunca pior do que a ordem original
    $orig = naval_rot_comprimento(array_keys($pts), $pts, null, false, 1.0);
    verdade(naval_rot_comprimento(naval_rot_sequencia($pts, null, false, 1.0), $pts, null, false, 1.0) <= $orig);
});

teste('Rotas: percurso com hora de chegada, quilómetros, regresso e aviso de jornada', function () {
    $cfg = ['velocidade' => 20.0, 'servico' => 10.0, 'factor' => 1.0, 'jornada' => 9.0, 'inicio' => '08:00', 'regressa' => true];
    $coord = [];
    $visitas = [];
    foreach (rot_pontos() as $p) {
        $coord['C' . $p['i']] = ['lat' => $p['lat'], 'lng' => $p['lng']];
        $visitas[] = ['c' => 'C' . $p['i'], 'n' => 'Loja ' . $p['i'], 'estado' => 'em_dia'];
    }
    $visitas[] = ['c' => 'SEM', 'n' => 'Sem GPS', 'estado' => 'parado'];
    $x = naval_rot_percurso($visitas, $coord, ['lat' => -8.80, 'lng' => 13.19], $cfg);
    igual(['C0', 'C1', 'C2', 'C3', 'C4', 'C5'], array_column($x['paragens'], 'c'));
    igual([1, 2, 3, 4, 5, 6], array_column($x['paragens'], 'ordem'));
    igual([['c' => 'SEM', 'n' => 'Sem GPS']], $x['sem_coordenadas']);
    // 0,01° de longitude ≈ 1,1 km: 1,1 km a 20 km/h ≈ 3 minutos até ao primeiro cliente
    igual('08:03', $x['paragens'][0]['chegada']);
    verdade($x['km'] > 12 && $x['km'] < 14, 'ida e volta de ~6,6 km: ' . $x['km']);
    igual(false, $x['acima_jornada']);
    $x = naval_rot_percurso($visitas, $coord, null, ['servico' => 120.0] + $cfg);
    igual(true, $x['acima_jornada'], '6 clientes × 2 h passam as 9 h');
});

teste('Rotas: plano do dia sai das visitas da carteira (mesma regra das Visitas do dia), sem domingo', function () {
    $cart = ['clientes' => []];
    foreach (rot_pontos() as $p) {
        $cart['clientes']['C' . $p['i']] = ['c' => 'C' . $p['i'], 'n' => 'Loja ' . $p['i'], 'r' => '1001', 'dia' => 3, 'proxima' => '2026-11-11', 'estado' => 'em_dia', 'media' => 100];
    }
    $coord = [];
    foreach (rot_pontos() as $p) $coord['C' . $p['i']] = ['lat' => $p['lat'], 'lng' => $p['lng']];
    $cfg = naval_rot_cfg();
    $plano = naval_rot_planear($cart, $coord, ['2026-11-04', '2026-11-05', '2026-11-08'], [], $cfg);   // qua, qui, dom
    igual(['2026-11-04'], array_keys($plano), 'só a quarta-feira (dia habitual) tem visitas; domingo nunca');
    igual(6, count($plano['2026-11-04']['1001']['paragens']));
    igual(null, $plano['2026-11-04']['1001']['partida'], 'sem partida configurada nem OLAP: caminho aberto');
});

teste('Rotas: ponto de partida do .env e hora somada', function () {
    igual(['lat' => -8.8383, 'lng' => 13.2344], naval_rot_ponto('-8.8383, 13.2344'));
    igual(null, naval_rot_ponto('0,0'));
    igual(null, naval_rot_ponto('texto'));
    igual('09:35', naval_rot_hora('08:00', 95));
});
