<?php
// Prospecção e territórios das rotas (nucleo/servicos/prospeccao.php).
naval_servico('prospeccao');

/** Ponto a $dn metros a norte e $de metros a leste de (-8.84, 13.23). */
function pp_ponto(float $dn, float $de): array
{
    return ['lat' => -8.84 + $dn / 111320, 'lng' => 13.23 + $de / (111320 * cos(deg2rad(-8.84)))];
}

teste('geometria: distância, coordenadas válidas em Angola, invólucro e ponto dentro', function () {
    $a = pp_ponto(0, 0);
    $b = pp_ponto(1000, 0);
    verdade(abs(naval_geo_dist($a['lat'], $a['lng'], $b['lat'], $b['lng']) - 1000) < 2, 'distância de 1 km');
    verdade(naval_geo_valida(-8.84, 13.23), 'Luanda');
    verdade(!naval_geo_valida(0, 0), 'ponto (0, 0)');
    verdade(!naval_geo_valida(38.7, -9.1), 'Lisboa');
    $h = naval_geo_involucro([[0, 0], [0, 2], [2, 2], [2, 0], [1, 1]]);
    igual(4, count($h), 'o ponto interior não entra no invólucro');
    $t = naval_geo_territorio([pp_ponto(0, 0) + ['c' => 1], pp_ponto(0, 1000) + ['c' => 2], pp_ponto(1000, 500) + ['c' => 3]], 300);
    $c = pp_ponto(300, 500);
    verdade(naval_geo_dentro($c['lat'], $c['lng'], $t), 'centro dentro do território');
    $m = pp_ponto(-200, 500);
    verdade(naval_geo_dentro($m['lat'], $m['lng'], $t), 'a margem de 300 m alarga o território');
    $f = pp_ponto(3000, 500);
    verdade(!naval_geo_dentro($f['lat'], $f['lng'], $t), 'longe fica fora');
});

teste('território cresce sozinho com os clientes e ignora um cliente mal georreferenciado', function () {
    $cs = [];
    foreach ([[0, 0], [0, 800], [800, 0], [800, 800]] as $i => [$n, $e]) $cs[] = pp_ponto($n, $e) + ['c' => $i];
    $antes = naval_geo_territorio($cs);
    $novo = pp_ponto(1800, 400);
    verdade(!naval_geo_dentro($novo['lat'], $novo['lng'], $antes), 'antes do cliente novo');
    $cs[] = $novo + ['c' => 9];
    verdade(naval_geo_dentro($novo['lat'], $novo['lng'], naval_geo_territorio($cs)), 'depois do cliente novo');
    $cs[] = pp_ponto(40000, 0) + ['c' => 10];   // 40 km: erro de GPS
    $t = naval_geo_territorio($cs);
    verdade(max(array_column($t, 0)) < -8.80, 'o cliente a 40 km não estica o território');
});

teste('potenciais: fontes juntas, sem os que já são clientes; conversão quando passa a cliente', function () {
    $cl = [pp_ponto(0, 0) + ['c' => 'C1', 'n' => 'Cantina Esperança', 'r' => '1001', 'd' => 2, 'f' => 1]];
    $osm = [
        pp_ponto(10, 5) + ['id' => 'osm:n1', 'n' => 'Esperança', 't' => 'convenience', 'f' => ['osm']],       // é o cliente (10 m)
        pp_ponto(60, 0) + ['id' => 'osm:n2', 'n' => 'Loja Esperanca', 't' => 'convenience', 'f' => ['osm']], // 60 m, nome parecido: cliente
        pp_ponto(400, 0) + ['id' => 'osm:n3', 'n' => 'Mini Mercado Kianda', 't' => 'supermarket', 'f' => ['osm']],
    ];
    $google = [pp_ponto(410, 8) + ['id' => 'g:ChIJabcdefghij', 'n' => 'Kianda', 't' => 'supermarket', 'f' => ['google']]];
    [$pot, $conv] = naval_geo_potenciais(array_merge($osm, $google), $cl, ['osm:n1' => '1001']);
    igual(1, count($pot), 'só o Kianda é potencial');
    igual(['osm', 'google'], $pot[0]['f'], 'confirmado nas duas fontes');
    igual(['osm:n3', 'g:ChIJabcdefghij'], $pot[0]['ids']);
    igual(['osm:n1' => 'C1'], $conv, 'o que estava na lista e agora é cliente conta como convertido');
});

teste('atribuição: rota e dia dos clientes mais próximos; longe das rotas fica sem rota; prioridade', function () {
    $cl = [];
    foreach ([[0, 0], [100, 0], [0, 100]] as $i => [$n, $e]) $cl[] = pp_ponto($n, $e) + ['c' => "A$i", 'n' => "A$i", 'r' => '1001', 'd' => 3, 'f' => 1];
    foreach ([[5000, 0], [5100, 0]] as $i => [$n, $e]) $cl[] = pp_ponto($n, $e) + ['c' => "B$i", 'n' => "B$i", 'r' => '1002', 'd' => 5, 'f' => 1];
    $pot = [
        pp_ponto(200, 50) + ['id' => 'osm:n1', 'n' => 'Perto', 't' => 'supermarket', 'f' => ['osm', 'google'], 'ids' => ['osm:n1']],
        pp_ponto(4900, 0) + ['id' => 'osm:n2', 'n' => 'Do outro', 't' => 'kiosk', 'f' => ['osm'], 'ids' => ['osm:n2']],
        pp_ponto(-20000, 0) + ['id' => 'osm:n3', 'n' => 'Longe', 't' => 'bar', 'f' => ['osm'], 'ids' => ['osm:n3']],
    ];
    $a = array_column(naval_geo_atribuir($pot, $cl, 1500), null, 'id');
    igual('1001', $a['osm:n1']['r']);
    igual(3, $a['osm:n1']['d'], 'dia em que a rota visita a zona');
    igual('1002', $a['osm:n2']['r']);
    igual(5, $a['osm:n2']['d']);
    igual('', $a['osm:n3']['r'], 'fora das rotas');
    verdade($a['osm:n1']['s'] > $a['osm:n2']['s'] && $a['osm:n2']['s'] > $a['osm:n3']['s'], 'prioridade: duas fontes, tipo e proximidade');
    igual('osm:n1', naval_geo_atribuir($pot, $cl)[0]['id'], 'ordenado pela prioridade');
});

teste('capacidade: 30 visitas × 6 dias; rota cheia com proposta de divisão; histórico de 14 dias seguidos', function () {
    $cl = [];
    for ($i = 0; $i < 170; $i++) $cl[] = pp_ponto(($i % 2) * 3000 + ($i % 7) * 30, intdiv($i, 7) * 20) + ['c' => "C$i", 'n' => "C$i", 'r' => '1001', 'd' => 1, 'f' => 1];
    for ($i = 0; $i < 40; $i++) $cl[] = pp_ponto(9000 + $i * 10, 0) + ['c' => "D$i", 'n' => "D$i", 'r' => '1002', 'd' => 2, 'f' => 2];
    $r = array_column(naval_geo_rotas($cl, [['r' => '1001'], ['r' => '1001'], ['r' => '']], ['1001' => 'Ana']), null, 'rota');
    igual(180, $r['1001']['capacidade']);
    igual(170, $r['1001']['visitas_semana']);
    igual(true, $r['1001']['cheia'], '94 % ≥ 90 %');
    igual(2, $r['1001']['potenciais']);
    igual(170, $r['1001']['divisao'][0]['clientes'] + $r['1001']['divisao'][1]['clientes']);
    verdade(abs($r['1001']['divisao'][0]['clientes'] - $r['1001']['divisao'][1]['clientes']) <= 2, 'divide pelos dois grupos de clientes');
    igual(80, $r['1002']['visitas_semana'], 'clientes que compram 2 vezes por semana contam 2 visitas');
    igual(false, $r['1002']['cheia']);
    $h = ['1001' => [], '1002' => []];
    for ($k = 0; $k < 14; $k++) {
        $d = date('Y-m-d', strtotime('2026-11-03 12:00') - $k * 86400);
        $h['1001'][$d] = 0.94;
        $h['1002'][$d] = $k === 5 ? 0.85 : 0.95;
    }
    igual(['1001'], naval_geo_rotas_cheias($h, 0.9, 14, '2026-11-03'), 'um dia abaixo do limiar recomeça a contagem');
});

teste('zonas sem rota: células com muitos potenciais clientes fora dos territórios', function () {
    $pot = [];
    for ($i = 0; $i < 45; $i++) $pot[] = pp_ponto(-20000 + $i * 10, 0) + ['r' => ''];
    for ($i = 0; $i < 45; $i++) $pot[] = pp_ponto($i * 10, 0) + ['r' => '1001'];
    $z = naval_geo_zonas_sem_rota($pot, 40);
    igual(1, count($z));
    verdade($z[0]['potenciais'] >= 40, 'zona com potencial');
});

teste('hábitos de compra: rota, dia da semana e ritmo pelas vendas', function () {
    $ag = [];
    for ($k = 0; $k < 28; $k++) {
        $d = date('Y-m-d', strtotime('2026-10-05 12:00') + $k * 86400);   // 05-10-2026 é segunda-feira
        $ds = (int) date('N', strtotime($d . ' 12:00'));
        $ag[$d] = ['clientes' => []];
        if ($ds === 1 || $ds === 4) $ag[$d]['clientes']['X'] = ['r' => '1003'];
        if ($ds === 2) $ag[$d]['clientes']['Y'] = ['r' => '1004'];
    }
    $h = naval_geo_habitos($ag);
    igual('1003', $h['X']['r']);
    igual(2, $h['X']['f'], 'duas compras por semana');
    igual(2, $h['Y']['d'], 'terça-feira');
    igual(1, $h['Y']['f']);
});

teste('Google Places: limite mensal, só células por renovar e apagados ao fim de 30 dias', function () {
    $f = naval_geo_pasta() . '/google.json';
    @unlink($f);
    $agora = strtotime('2026-11-03 02:00');
    naval_json_gravar($f, ['lugares' => ['velho' => ['n' => 'V', 't' => 'market', 'lat' => -8.8, 'lng' => 13.2, 'visto' => $agora - 31 * 86400]],
        'celulas' => [], 'meses' => ['2026-11' => 0]]);
    $pedidos = 0;
    $http = function ($m, $url, $o) use (&$pedidos) {
        $pedidos++;
        $c = json_decode($o['corpo'], true)['locationRestriction']['circle']['center'];
        return ['corpo' => json_encode(['places' => [['id' => 'P' . $pedidos . 'abcdefghijk', 'displayName' => ['text' => 'Loja ' . $pedidos],
            'location' => ['latitude' => $c['latitude'], 'longitude' => $c['longitude']], 'primaryType' => 'grocery_store']]]), 'codigo' => 200, 'erro' => ''];
    };
    // sem chave: não faz pedidos, mas apaga o que passou dos 30 dias
    igual(0, naval_geo_google_passagem(['-884:1323', '-884:1324'], 10, $http, $agora, ['chave' => '']));
    igual([], naval_json_ler($f)['lugares']);
    $cfg = ['chave' => 'chave-teste', 'limite' => 3];
    igual(2, naval_geo_google_passagem(['-884:1323', '-884:1324'], 10, $http, $agora, $cfg));
    igual(0, naval_geo_google_passagem(['-884:1323', '-884:1324'], 10, $http, $agora + 3600, $cfg), 'células vistas há menos de 25 dias');
    igual(1, naval_geo_google_passagem(['-884:1323', '-884:1324', '-884:1325', '-884:1326'], 10, $http, $agora + 7200, $cfg), 'limite de 3 pedidos no mês');
    igual(3, count(naval_geo_google_pontos()));
});

teste('cálculo completo, âmbito por rota e «não existe» tira o potencial do mapa', function () {
    @unlink(naval_geo_pasta() . '/estados.json');
    @unlink(naval_geo_pasta() . '/vista.json');
    $cl = ['origem' => 'teste', 'clientes' => [
        pp_ponto(0, 0) + ['c' => 'C1', 'n' => 'Um', 'r' => '1001', 'd' => 1, 'f' => 1],
        pp_ponto(5000, 0) + ['c' => 'C2', 'n' => 'Dois', 'r' => '1002', 'd' => 2, 'f' => 1],
    ]];
    $fontes = [pp_ponto(200, 0) + ['id' => 'osm:n7', 'n' => 'Sete', 't' => 'kiosk', 'f' => ['osm']], pp_ponto(5200, 0) + ['id' => 'osm:n8', 'n' => 'Oito', 't' => 'kiosk', 'f' => ['osm']]];
    $v = naval_geo_calcular([], $fontes, $cl);
    igual(2, count($v['potenciais']));
    $s = naval_geo_vista(['1001']);
    igual(['1001'], array_column($s['rotas'], 'rota'));
    igual(['osm:n7'], array_column($s['potenciais'], 'id'));
    verdade(!isset($s['potenciais'][0]['ids']), 'sem os identificadores internos');
    $eu = ['login' => 'pro1'];
    lanca(404, function () use ($eu) { naval_geo_marcar('osm:n8', 'nao_existe', $eu, ['1001']); });
    lanca(400, function () use ($eu) { naval_geo_marcar('osm:n7', 'xpto', $eu, ['1001']); });
    naval_geo_marcar('osm:n7', 'nao_existe', $eu, ['1001']);
    $v = naval_geo_calcular([], $fontes, $cl);
    igual(['osm:n8'], array_column($v['potenciais'], 'id'));
});
