<?php
/** Localização dos IP nos registos de actividade (leitor .mmdb próprio, base de dados de teste). */
$fixture = dirname(__DIR__) . '/dados/geoip-teste.mmdb';
@mkdir(dirname(naval_geoip_ficheiro()), 0770, true);
copy($fixture, naval_geoip_ficheiro());

teste('leitor .mmdb: IPv4 dentro de base IPv6, IPv6 e endereço sem registo', function () use ($fixture) {
    $bd = new NavalMmdb($fixture);
    igual('Teste-City', $bd->meta['database_type']);
    igual('AO', $bd->procurar('102.218.182.231')['country']['iso_code']);
    igual('Lisboa', $bd->procurar('85.245.146.31')['city']['names']['pt-BR']);
    igual(1000, $bd->procurar('2c0f:f8f0::1')['location']['accuracy_radius']);
    igual(null, $bd->procurar('8.8.8.8'));
    igual(null, $bd->procurar('não é um IP'));
});

teste('naval_geoip: nomes em português, raio, rede interna e entradas inválidas', function () {
    $l = naval_geoip('85.245.146.31');
    igual(['PT', 'Portugal', 'Lisboa', 'Lisboa', 38.717, -9.133, 50], [$l['pais'], $l['pais_nome'], $l['regiao'], $l['cidade'], $l['lat'], $l['lon'], $l['raio_km']]);
    igual('Luanda', naval_geoip('102.218.182.231')['cidade']);
    igual(['rede' => 'interna'], naval_geoip('192.168.10.5'));
    igual(['rede' => 'interna'], naval_geoip('127.0.0.1'));
    igual([], naval_geoip('8.8.8.8'));
    igual([], naval_geoip(''));
    igual([], naval_geoip('<script>'));
});

teste('naval_registar grava a localização da entrada', function () {
    $_SERVER['REMOTE_ADDR'] = '102.218.182.231';
    naval_registar('entrada', ['teste' => true], 'geo-teste');
    $r = naval_ler_registos(1)[0];
    igual('geo-teste', $r['utilizador']);
    igual('AO', $r['local']['pais']);
    igual(20, $r['local']['raio_km']);
    verdade(!isset($r['local']['posterior']), 'gravada no momento');
});

teste('ficheiro inválido não estraga os registos', function () {
    $f = sys_get_temp_dir() . '/naval-geo-invalido.mmdb';
    file_put_contents($f, str_repeat('x', 4096));
    $erro = '';
    try { new NavalMmdb($f); } catch (RuntimeException $e) { $erro = $e->getMessage(); }
    verdade($erro !== '', 'deve recusar o ficheiro');
    @unlink($f);
});

teste('base de dados de localização: renovação automática só quando falta ou tem mais de 32 dias, de madrugada, uma vez por dia', function () {
    $f = naval_geoip_ficheiro();
    igual(null, naval_geoip_manter(), 'recente: nada a fazer');
    touch($f, time() - 40 * 86400);
    igual(null, naval_geoip_manter(strtotime('today 14:00')), 'antiga, mas fora da madrugada');
    // tentativa recente registada: não volta a tentar no mesmo dia (sem rede nos testes)
    naval_json_gravar(naval_pasta('geoip-tentativas') . '/ultima.json', ['quando' => strtotime('today 03:10')], false);
    igual(null, naval_geoip_manter(strtotime('today 04:00')), 'já tentou hoje');
    touch($f);
});
