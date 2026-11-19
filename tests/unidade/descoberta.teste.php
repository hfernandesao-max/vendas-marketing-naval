<?php
// Descoberta automática de potenciais clientes (nucleo/servicos/descoberta.php) e radar do dia (nucleo/servicos/radar.php).
naval_servico('prospeccao');
naval_servico('radar');

/** Ponto a $dn metros a norte e $de metros a leste de (-8.84, 13.23), em Luanda. */
function dc_ponto(float $dn, float $de): array
{
    return ['lat' => -8.84 + $dn / 111320, 'lng' => 13.23 + $de / (111320 * cos(deg2rad(-8.84)))];
}

teste('descoberta: taxonomia do portfólio e palavras do nome (armazém, cantina, padaria, drogaria)', function () {
    igual(['t' => 'supermarket', 'a' => 'ambos', 'b' => 0], naval_desc_classificar('supermarket', 'Kero'));
    igual('warehouse', naval_desc_classificar('store', 'Armazém do Povo')['t'], 'loja genérica com «armazém» no nome é armazém');
    igual('general', naval_desc_classificar('', 'Cantina da Tia Rosa')['t'], 'sem tipo: o nome decide');
    igual('alimentar', naval_desc_classificar('bakery', 'Padaria Kianda')['a']);
    igual('higiene', naval_desc_classificar('', 'Drogaria Higiene Total')['a']);
    igual('ambos', naval_desc_classificar('restaurant', 'Restaurante O Kota')['a'], 'restauração: alimentar e limpeza');
    igual(null, naval_desc_classificar('car_repair', 'Oficina do Zé'), 'fora do portfólio');
    igual(null, naval_desc_classificar('', ''), 'sem tipo nem nome');
});

teste('descoberta: telefone angolano, sítio e regras de qualidade (área, portfólio, nome)', function () {
    igual('923456789', naval_desc_telefone('+244 923 456 789'));
    igual('923456789', naval_desc_telefone('00244923456789'));
    igual('222333444', naval_desc_telefone('222 333 444'));
    igual('', naval_desc_telefone('12345'), 'curto demais');
    igual('kero.co.ao', naval_desc_sitio('https://www.kero.co.ao/'));
    igual('', naval_desc_sitio('não tem'));
    $p = dc_ponto(0, 0) + ['n' => 'Mini Mercado Kianda', 't' => 'convenience'];
    igual('', naval_desc_qualidade($p), 'aceite');
    igual('fora de Luanda e Ícolo e Bengo', naval_desc_qualidade(['lat' => -12.5, 'lng' => 13.5] + $p), 'Benguela');
    igual('coordenadas nulas', naval_desc_qualidade(['lat' => 0, 'lng' => 0] + $p));
    igual('sem coordenadas', naval_desc_qualidade(['n' => 'X', 't' => 'supermarket']));
    igual('fora do portfólio (higiene, limpeza e alimentar)', naval_desc_qualidade(['t' => 'car_repair', 'n' => 'Oficina'] + $p));
    igual('nome inválido', naval_desc_qualidade(['n' => '123'] + $p));
    igual('sem nome e tipo de pouco volume', naval_desc_qualidade(['n' => '', 't' => 'bar'] + $p));
    igual('', naval_desc_qualidade(['n' => '', 't' => 'supermarket'] + $p), 'sem nome mas supermercado: fica');
    verdade(naval_desc_na_area(-9.05, 13.75), 'Ícolo e Bengo (Catete)');
});

teste('ICP Score: portfólio, volume, contacto, fontes e viabilidade na zona', function () {
    [$s1, $a1] = naval_desc_icp(['t' => 'wholesale', 'n' => 'Armazém Grossista Luanda', 'f' => ['osm', 'google'], 'tel' => '923456789'], 150, 3);
    [$s2] = naval_desc_icp(['t' => 'kiosk', 'n' => 'Quiosque', 'f' => ['osm']], 700, 0);
    [$s3] = naval_desc_icp(['t' => 'bar', 'n' => 'Bar', 'f' => ['osm']], null, 0);
    igual('ambos', $a1);
    verdade($s1 > $s2 && $s2 > $s3, "ordem: grossista perto ($s1) > quiosque ($s2) > bar longe ($s3)");
    verdade($s1 <= 100 && $s3 >= 0, 'entre 0 e 100');
    [$sx] = naval_desc_icp(['t' => 'supermarket', 'n' => 'S', 'f' => ['x:apify']], null, 0);
    [$so] = naval_desc_icp(['t' => 'supermarket', 'n' => 'S', 'f' => ['osm']], null, 0);
    verdade($sx > $so, 'fonte externa vale mais do que só o OpenStreetMap');
});

teste('entrada externa: normaliza, recusa o que não presta e guarda sem duplicar', function () {
    @unlink(naval_desc_externos_ficheiro());
    @unlink(naval_pasta('geo') . '/recalcular.json');
    $p = dc_ponto(0, 0);
    $itens = [
        ['id_externo' => 'A1', 'nome' => 'Cash and Carry Viana', 'lat' => $p['lat'], 'lng' => $p['lng'], 'categoria' => 'store', 'telefone' => '+244 923 111 222'],
        ['nome' => 'Oficina', 'lat' => $p['lat'], 'lng' => $p['lng'], 'categoria' => 'car_repair'],
        ['nome' => 'Padaria Sem GPS'],
        'lixo',
    ];
    $r = naval_desc_receber($itens, 'Apify Diretório', 1000000);
    igual(['recebidos' => 4, 'aceites' => 1, 'novos' => 1], array_intersect_key($r, ['recebidos' => 1, 'aceites' => 1, 'novos' => 1]));
    igual(['fora do portfólio (higiene, limpeza e alimentar)', 'sem coordenadas', 'item inválido'], array_column($r['rejeitados'], 'motivo'));
    $pts = naval_desc_externos_pontos();
    igual(1, count($pts));
    igual('cash_and_carry', $pts[0]['t'], 'o nome corrige a categoria genérica');
    igual(['x:apifydiretrio'], $pts[0]['f'], 'nome da fonte limpo');
    igual('923111222', $pts[0]['tel']);
    verdade((bool) preg_match('/^x:[a-f0-9]{16}$/', $pts[0]['id']), 'identificador estável');
    igual(0, naval_desc_receber([$itens[0]], 'Apify Diretório', 1000100)['novos'], 'o mesmo outra vez não é novo');
    verdade(naval_desc_recalculo_pendente(1000100 + 601, '1970-01-01 00:00:00'), 'pede novo cálculo 10 minutos depois');
    verdade(!naval_desc_recalculo_pendente(1000100 + 60, '1970-01-01 00:00:00'), 'ainda a juntar envios');
    $muitos = array_fill(0, 501, $itens[0]);
    $erro = null;
    try { naval_desc_receber($muitos, 'x'); } catch (NavalErro $e) { $erro = $e->estado(); }
    igual(413, $erro, 'no máximo 500 por pedido');
    @unlink(naval_desc_externos_ficheiro());
    @unlink(naval_pasta('geo') . '/recalcular.json');
});

teste('deduplicação: o mesmo telefone até 500 m é o mesmo estabelecimento, noutro nome', function () {
    $pts = [
        dc_ponto(0, 0) + ['id' => 'osm:n1', 'n' => 'Supermercado Kianda', 't' => 'supermarket', 'f' => ['osm'], 'tel' => '923000111'],
        dc_ponto(200, 0) + ['id' => 'x:0000000000000001', 'n' => 'Kianda Comercial Lda', 't' => 'general', 'f' => ['x:apify'], 'tel' => '923000111', 'mor' => 'Rua 1'],
        dc_ponto(3000, 0) + ['id' => 'x:0000000000000002', 'n' => 'Kianda Filial', 't' => 'general', 'f' => ['x:apify'], 'tel' => '923000111'],
    ];
    [$pot] = naval_geo_potenciais($pts, []);
    igual(2, count($pot), 'a 3 km com o mesmo telefone é outra loja');
    igual(['osm', 'x:apify'], $pot[0]['f']);
    igual('Rua 1', $pot[0]['mor'], 'a morada da fonte externa completa');
});

teste('radar: lugares livres, recuperar e conquistar perto do caminho, adiar acima da capacidade', function () {
    $plano = ['1001' => [['c' => 'A', 'n' => 'A', 'estado' => 'parado'], ['c' => 'B', 'n' => 'B', 'estado' => 'em_dia']]];
    $coord = ['A' => array_values(dc_ponto(0, 0)), 'B' => array_values(dc_ponto(500, 0))];
    $pot = [
        dc_ponto(100, 0) + ['id' => 'osm:n1', 'n' => 'Perto', 'r' => '1001', 'd' => 4, 's' => 70, 'a' => 'ambos', 't' => 'supermarket'],
        dc_ponto(200, 0) + ['id' => 'osm:n2', 'n' => 'Outro dia', 'r' => '1001', 'd' => 2, 's' => 90],
        dc_ponto(5000, 0) + ['id' => 'osm:n3', 'n' => 'Longe', 'r' => '1001', 'd' => null, 's' => 95],
        dc_ponto(150, 0) + ['id' => 'osm:n4', 'n' => 'Recusou', 'r' => '1001', 'd' => null, 's' => 80, 'e' => 'nao_interessado'],
        dc_ponto(120, 0) + ['id' => 'osm:n5', 'n' => 'Sem dia', 'r' => '1001', 'd' => null, 's' => 60],
        dc_ponto(100, 0) + ['id' => 'osm:n6', 'n' => 'De outra rota', 'r' => '1002', 'd' => 4, 's' => 99],
    ];
    $esq = [
        dc_ponto(300, 0) + ['c' => 'E1', 'n' => 'Parado', 'r' => '1001', 'dias' => 20, 'media' => 50000, 'compras' => 5],
        dc_ponto(400, 0) + ['c' => 'E2', 'n' => 'Muito parado', 'r' => '1001', 'dias' => 70, 'media' => 10000, 'compras' => 3],
        ['c' => 'B', 'n' => 'Já no plano', 'r' => '1001', 'dias' => 30, 'media' => 1, 'compras' => 3],
    ];
    $l = array_column(naval_radar_montar($plano, $coord, $pot, $esq, ['1001', '1002'], 4, ['capacidade' => 30, 'max' => 8, 'desvio' => 1000]), null, 'rota');
    igual(['planeadas' => 2, 'livres' => 28, 'excesso' => 0], array_intersect_key($l['1001'], ['planeadas' => 1, 'livres' => 1, 'excesso' => 1]));
    igual(['E2', 'osm:n1', 'E1', 'osm:n5'], array_column($l['1001']['sugestoes'], 'id'), 'recuperar (60+ dias primeiro) intercalado com conquistar');
    igual(100, $l['1001']['sugestoes'][1]['desvio_m'], 'desvio até à visita mais perto');
    igual(['osm:n6'], array_column($l['1002']['sugestoes'], 'id'), 'rota sem plano: sugere pelo ICP (sem caminho)');
    // poucos lugares livres: só os primeiros
    $l2 = naval_radar_montar($plano, $coord, $pot, $esq, [], 4, ['capacidade' => 3, 'max' => 8, 'desvio' => 1000]);
    igual(['E2'], array_column($l2[0]['sugestoes'], 'id'), '1 lugar livre, 1 sugestão');
    // acima da capacidade: adia as «em dia» de menor valor, nunca as paradas
    $l3 = naval_radar_montar($plano, $coord, $pot, $esq, [], 4, ['capacidade' => 1, 'max' => 8, 'desvio' => 1000]);
    igual(['livres' => 0, 'excesso' => 1], array_intersect_key($l3[0], ['livres' => 1, 'excesso' => 1]));
    igual(['B'], array_column($l3[0]['adiar'], 'id'));
    igual([], $l3[0]['sugestoes']);
});
