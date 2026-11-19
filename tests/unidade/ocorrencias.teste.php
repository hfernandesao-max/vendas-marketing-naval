<?php
// Ocorrências do terreno: cada tipo a partir dos dados já calculados, gravidade, identificadores estáveis, visitas
// não feitas só no fim do dia e ordenação (crítico primeiro).
naval_servico('ocorrencias');

function oco_terreno(): array
{
    return ['rotas' => [[
        'rota' => '1001', 'fora_pct' => 0.25, 'pontos_fora' => 5, 'percurso' => array_fill(0, 20, [-8.8, 13.2, '10:00']),
        'visitas' => [
            ['t' => '2026-11-19 10:23:00', 'doc' => 'FR FR0126S78519N/17', 'cli' => 'A', 'n' => 'Loja A', 'lat' => -8.8, 'lng' => 13.2, 'valor' => 810000, 'dist' => 113, 'prova' => 'ok'],
            ['t' => '2026-11-19 11:14:00', 'doc' => 'FR FR0126S78519N/18', 'cli' => 'B', 'n' => 'Loja B', 'lat' => -8.81, 'lng' => 13.21, 'valor' => 156000, 'dist' => 222, 'prova' => 'longe'],
        ],
        'paragens' => [
            ['de' => '2026-11-19 12:00:00', 'ate' => '2026-11-19 13:10:00', 'min' => 70, 'cliente' => null, 'lat' => -8.82, 'lng' => 13.22],
            ['de' => '2026-11-19 14:00:00', 'ate' => '2026-11-19 14:20:00', 'min' => 20, 'cliente' => 'Loja A', 'lat' => -8.8, 'lng' => 13.2],
            ['de' => '2026-11-19 15:00:00', 'ate' => '2026-11-19 15:05:00', 'min' => 5, 'cliente' => null, 'lat' => -8.83, 'lng' => 13.23],
        ],
    ]], 'coordenadas' => [['c' => 'B', 'n' => 'Loja B', 'r' => '1001', 'lat' => -8.81, 'lng' => 13.21, 'vendas' => 3, 'dist' => 222]]];
}
function oco_carteira(): array
{
    return [
        'esquecidos' => [['c' => 'C', 'n' => 'Loja C', 'r' => '1002', 'ult' => '2026-09-01', 'dias' => 79, 'faixa' => 60, 'compras' => 6, 'lat' => -8.9, 'lng' => 13.3]],
        'sem_gps' => [['c' => 'D', 'n' => 'Loja D', 'r' => '1001', 'compras' => 4]],
        'duplicados' => [['lat' => -8.8, 'lng' => 13.2, 'r' => '1001', 'clientes' => [['c' => 'A', 'n' => 'Loja A'], ['c' => 'E', 'n' => 'Loja E']]]],
    ];
}

teste('ocorrências: cada tipo a partir do terreno, da carteira e do plano; crítico primeiro', function () {
    $plano = ['1001' => [['c' => 'A', 'n' => 'Loja A', 'estado' => 'em_dia', 'motivo' => 'dia habitual de compra', 'media' => 1000], ['c' => 'F', 'n' => 'Loja F', 'estado' => 'parado', 'motivo' => 'compra em atraso', 'media' => 5000]]];
    $o = naval_oco_montar('2026-11-19', oco_terreno(), oco_carteira(), $plano, ['A', 'B'], true, ['1001' => 'Promotor Um']);
    $tipos = array_count_values(array_column($o, 'tipo'));
    ksort($tipos);
    igual(['cliente_parado' => 1, 'coordenadas' => 1, 'duplicado' => 1, 'fora_territorio' => 1, 'parado' => 1, 'sem_gps' => 1, 'venda_longe' => 1, 'visita_nao_feita' => 1], $tipos);
    igual('cliente_parado', $o[0]['tipo'], 'crítico primeiro (60 dias ou mais sem comprar)');
    igual('critico', $o[0]['gravidade']);
    $porTipo = array_column($o, null, 'tipo');
    igual('Venda a 222 m do cliente', $porTipo['venda_longe']['titulo']);
    igual('11:14 · FR FR0126S78519N/18 · 156 000,00 Kz', $porTipo['venda_longe']['detalhe']);
    igual('geo-terreno?data=2026-11-19&rota=1001', $porTipo['venda_longe']['pagina']);
    igual('Promotor Um', $porTipo['venda_longe']['promotor']);
    igual('Parado 70 min sem vender', $porTipo['parado']['titulo'], 'só a paragem longa e sem venda');
    igual('aviso', $porTipo['parado']['gravidade'], '60 min ou mais: aviso');
    igual('Loja F', $porTipo['visita_nao_feita']['cliente'], 'a Loja A foi visitada');
    igual('aviso', $porTipo['visita_nao_feita']['gravidade'], 'cliente parado: aviso');
    igual('25% do percurso fora do território', $porTipo['fora_territorio']['titulo']);
    igual('Loja A · Loja E', $porTipo['duplicado']['cliente']);
    igual('1 sítios com mais de um cliente (2 clientes)', $porTipo['duplicado']['titulo'], 'uma ocorrência por rota');
    // com menos de 10 pontos no dia, a percentagem fora do território não conta
    $t = oco_terreno();
    $t['rotas'][0]['percurso'] = array_fill(0, 4, [-8.8, 13.2, '10:00']);
    verdade(!in_array('fora_territorio', array_column(naval_oco_montar('2026-11-19', $t, [], [], [], false), 'tipo'), true));
    foreach ($o as $x) verdade((bool) preg_match('/^[\w|.:\-]{3,160}$/u', $x['id']), 'id aceite pelo registo dos alertas: ' . $x['id']);
    igual('oco|venda_longe|2026-11-19|1001|FRFR0126S78519N18', $porTipo['venda_longe']['id']);
});

teste('ocorrências: visitas não feitas só no fim do dia; sem excepções, lista vazia', function () {
    $plano = ['1001' => [['c' => 'F', 'n' => 'Loja F', 'estado' => 'parado']]];
    $o = naval_oco_montar('2026-11-19', ['rotas' => [], 'coordenadas' => []], [], $plano, [], false);
    igual([], $o, 'antes das 18:00 ainda pode ser visitado');
    igual(1, count(naval_oco_montar('2026-11-19', ['rotas' => [], 'coordenadas' => []], [], $plano, [], true)));
    // o identificador não depende da ordem nem do texto: o tratamento guardado continua a aplicar-se
    $a = naval_oco_montar('2026-11-19', oco_terreno(), [], [], [], false);
    $b = naval_oco_montar('2026-11-19', oco_terreno(), [], [], [], false);
    igual(array_column($a, 'id'), array_column($b, 'id'));
});
