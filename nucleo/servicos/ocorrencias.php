<?php
/**
 * Ocorrências do terreno (Terreno › Operação do dia › Ocorrências): as excepções que pedem uma acção, juntas numa só
 * lista a partir do que o portal já calcula noutras páginas — nada de regras novas escondidas:
 *
 *   - venda longe do cliente          Percurso do dia: venda a mais de OLAP_RAIO_VISITA (150 m) do GPS do cliente
 *   - parado sem vender               Percurso do dia: paragem de OCORRENCIAS_PARADO_MIN (15) minutos ou mais sem venda
 *   - fora do território              Percurso do dia: 10 % ou mais dos pontos da rota fora do território
 *   - visita do plano não feita       Visitas do dia (carteira) sem visita registada no OLAP, depois das 18:00 ou dias passados
 *   - cliente regular parado          Prospecção › carteira: sem comprar há 14, 30 ou 60 dias ou mais
 *   - cliente sem GPS / duplicado     Prospecção › carteira: coordenadas por registar ou clientes no mesmo sítio
 *   - coordenadas a corrigir          Percurso do dia: 3 ou mais vendas noutro sítio que não o registo
 *
 * O tratamento é o dos alertas (assumir, resolver com nota, silenciar até uma data, reabrir), no mesmo registo
 * (alertas-tratamento.php), com identificadores estáveis começados por «oco|». Âmbito por rotas como o resto do terreno.
 */
defined('NAVAL_NUCLEO') || exit;
naval_servico('olap');
naval_servico('prospeccao');
naval_servico('alertas-tratamento');

const NAVAL_OCO_TIPOS = [
    'venda_longe' => 'Venda longe do cliente',
    'parado' => 'Parado sem vender',
    'fora_territorio' => 'Fora do território da rota',
    'visita_nao_feita' => 'Visita do plano não feita',
    'cliente_parado' => 'Cliente regular sem comprar',
    'sem_gps' => 'Cliente sem GPS',
    'duplicado' => 'Clientes no mesmo sítio',
    'coordenadas' => 'Coordenadas do cliente a corrigir',
];

/** Identificador estável (caracteres aceites pelo registo dos alertas). */
function naval_oco_id(string $tipo, string ...$partes): string
{
    $ref = implode('|', array_map(function ($p) { return preg_replace('/[^\w.:\-]/u', '', $p) ?: substr(hash('sha256', $p), 0, 12); }, $partes));
    return substr('oco|' . $tipo . '|' . $ref, 0, 160);
}

/**
 * Lista das ocorrências (função pura). $terreno: naval_olap_terreno do dia; $carteira: vista da prospecção
 * (esquecidos, sem_gps, duplicados); $plano: visitas do dia por rota (naval_cart_visitas); $visitados: códigos dos
 * clientes com visita registada no OLAP nesse dia; $fimDoDia: já se pode dizer que uma visita não foi feita.
 */
function naval_oco_montar(string $dia, array $terreno, array $carteira, array $plano, array $visitados, bool $fimDoDia, array $promotores = []): array
{
    $paradoMin = (int) naval_config('OCORRENCIAS_PARADO_MIN', '15');
    $prom = function ($r) use ($promotores) { return (string) ($promotores[(string) $r] ?? ''); };
    $link = function ($r) use ($dia) { return 'geo-terreno?data=' . $dia . ($r !== '' ? '&rota=' . rawurlencode((string) $r) : ''); };
    $out = [];
    foreach ((array) ($terreno['rotas'] ?? []) as $rt) {
        $r = (string) $rt['rota'];
        foreach ((array) ($rt['visitas'] ?? []) as $v) {
            if (($v['prova'] ?? '') !== 'longe') continue;
            $out[] = ['id' => naval_oco_id('venda_longe', $dia, $r, (string) ($v['doc'] ?: $v['t'])), 'tipo' => 'venda_longe', 'gravidade' => 'aviso', 'rota' => $r,
                'cliente' => (string) ($v['n'] ?? $v['cli'] ?? ''), 'titulo' => 'Venda a ' . (int) $v['dist'] . ' m do cliente',
                'detalhe' => implode(' · ', array_filter([substr((string) $v['t'], 11, 5), (string) ($v['doc'] ?? ''),
                    !empty($v['valor']) ? number_format((float) $v['valor'], 2, ',', ' ') . ' Kz' : ''], function ($x) { return $x !== ''; })),
                'lat' => $v['lat'], 'lng' => $v['lng'], 'dia' => $dia, 'pagina' => $link($r)];
        }
        foreach ((array) ($rt['paragens'] ?? []) as $p) {
            if ((int) $p['min'] < $paradoMin || !empty($p['cliente'])) continue;
            $out[] = ['id' => naval_oco_id('parado', $dia, $r, substr((string) $p['de'], 11, 5)), 'tipo' => 'parado', 'gravidade' => (int) $p['min'] >= 60 ? 'aviso' : 'info', 'rota' => $r,
                'cliente' => '', 'titulo' => 'Parado ' . (int) $p['min'] . ' min sem vender', 'detalhe' => substr((string) $p['de'], 11, 5) . '–' . substr((string) $p['ate'], 11, 5),
                'lat' => $p['lat'], 'lng' => $p['lng'], 'dia' => $dia, 'pagina' => $link($r)];
        }
        // com poucos pontos no dia a percentagem não diz nada: só a partir de 10 pontos
        if ((float) ($rt['fora_pct'] ?? 0) >= 0.1 && count((array) ($rt['percurso'] ?? [])) >= 10) {
            $out[] = ['id' => naval_oco_id('fora_territorio', $dia, $r), 'tipo' => 'fora_territorio', 'gravidade' => 'aviso', 'rota' => $r, 'cliente' => '',
                'titulo' => (int) round($rt['fora_pct'] * 100) . '% do percurso fora do território', 'detalhe' => (int) $rt['pontos_fora'] . ' pontos fora',
                'lat' => null, 'lng' => null, 'dia' => $dia, 'pagina' => $link($r)];
        }
    }
    foreach ((array) ($terreno['coordenadas'] ?? []) as $c) {
        $out[] = ['id' => naval_oco_id('coordenadas', (string) $c['c']), 'tipo' => 'coordenadas', 'gravidade' => 'info', 'rota' => (string) $c['r'], 'cliente' => (string) $c['n'],
            'titulo' => $c['vendas'] . ' vendas noutro sítio' . ($c['dist'] !== null ? ', a ' . $c['dist'] . ' m do registo' : ', sem coordenadas no registo'),
            'detalhe' => 'Sugerido ' . $c['lat'] . ', ' . $c['lng'] . ': confirmar e corrigir no Pinguim BO', 'lat' => $c['lat'], 'lng' => $c['lng'], 'dia' => $dia, 'pagina' => $link((string) $c['r'])];
    }
    if ($fimDoDia) {
        $vis = array_flip(array_map('strval', $visitados));
        foreach ($plano as $r => $cs) foreach ($cs as $c) {
            if (isset($vis[(string) $c['c']])) continue;
            $out[] = ['id' => naval_oco_id('visita_nao_feita', $dia, (string) $c['c']), 'tipo' => 'visita_nao_feita', 'gravidade' => ($c['estado'] ?? '') === 'parado' ? 'aviso' : 'info',
                'rota' => (string) $r, 'cliente' => (string) ($c['n'] ?? $c['c']), 'titulo' => 'Sem visita registada (' . ($c['motivo'] ?? 'no plano') . ')',
                'detalhe' => isset($c['media']) ? 'Compra média ' . number_format((float) $c['media'], 2, ',', ' ') . ' Kz' : '', 'lat' => null, 'lng' => null, 'dia' => $dia, 'pagina' => 'visitas-dia'];
        }
    }
    foreach ((array) ($carteira['esquecidos'] ?? []) as $c) {
        $out[] = ['id' => naval_oco_id('cliente_parado', (string) $c['c'], (string) $c['faixa']), 'tipo' => 'cliente_parado',
            'gravidade' => $c['faixa'] >= 60 ? 'critico' : ($c['faixa'] >= 30 ? 'aviso' : 'info'), 'rota' => (string) $c['r'], 'cliente' => (string) $c['n'],
            'titulo' => 'Sem comprar há ' . (int) $c['dias'] . ' dias', 'detalhe' => 'Última compra ' . naval_data_pt((string) $c['ult']) . ' · ' . (int) $c['compras'] . ' compras antes',
            'lat' => $c['lat'] ?? null, 'lng' => $c['lng'] ?? null, 'dia' => $dia, 'pagina' => 'geo-prospeccao'];
    }
    foreach ((array) ($carteira['sem_gps'] ?? []) as $c) {
        $out[] = ['id' => naval_oco_id('sem_gps', (string) $c['c']), 'tipo' => 'sem_gps', 'gravidade' => 'info', 'rota' => (string) $c['r'], 'cliente' => (string) $c['n'],
            'titulo' => 'Sem coordenadas no registo', 'detalhe' => (int) ($c['compras'] ?? 0) . ' compras · registar o GPS na próxima visita', 'lat' => null, 'lng' => null, 'dia' => $dia, 'pagina' => 'geo-prospeccao'];
    }
    // clientes no mesmo sítio: uma ocorrência por rota (a lista de cada grupo está na Prospecção), para não encher a página
    $dup = [];
    foreach ((array) ($carteira['duplicados'] ?? []) as $g) $dup[(string) ($g['r'] ?? '')][] = $g;
    ksort($dup);
    foreach ($dup as $r => $gs) {
        $n = array_sum(array_map(function ($g) { return count((array) ($g['clientes'] ?? [])); }, $gs));
        $nomes = array_slice(array_merge(...array_map(function ($g) { return array_column((array) ($g['clientes'] ?? []), 'n'); }, $gs)), 0, 4);
        $out[] = ['id' => naval_oco_id('duplicado', (string) $r), 'tipo' => 'duplicado', 'gravidade' => 'info', 'rota' => (string) $r,
            'cliente' => implode(' · ', $nomes) . ($n > count($nomes) ? ' …' : ''),
            'titulo' => count($gs) . ' sítios com mais de um cliente (' . $n . ' clientes)', 'detalhe' => 'Possíveis registos repetidos ou GPS do registo por corrigir: confirmar no Pinguim BO',
            'lat' => null, 'lng' => null, 'dia' => $dia, 'pagina' => 'geo-prospeccao'];
    }
    foreach ($out as &$o) {
        $o['tipo_nome'] = NAVAL_OCO_TIPOS[$o['tipo']];
        $o['promotor'] = $prom($o['rota']);
    }
    unset($o);
    $ordem = ['critico' => 0, 'aviso' => 1, 'info' => 2];
    usort($out, function ($a, $b) use ($ordem) { return $ordem[$a['gravidade']] <=> $ordem[$b['gravidade']] ?: strcmp($a['rota'], $b['rota']) ?: strcmp($a['id'], $b['id']); });
    return $out;
}

/** Ocorrências de um dia no âmbito de quem pede, com o tratamento de cada uma. */
function naval_oco_lista(string $dia, ?array $rotas, ?int $agora = null): array
{
    $agora = $agora ?? time();
    naval_servico('carteira');
    $d = naval_olap_dia($dia, $rotas);
    $g = naval_json_ler(naval_geo_pasta() . '/vista.json');
    $clientes = array_values(array_filter((array) ($g['clientes'] ?? []), function ($c) use ($rotas) { return $rotas === null || in_array((string) $c['r'], $rotas, true); }));
    $terr = [];
    foreach ((array) ($g['rotas'] ?? []) as $r) $terr[(string) $r['rota']] = (array) ($r['territorio'] ?? []);
    $terreno = $d['pontos'] ? naval_olap_terreno($d['pontos'], $clientes, $terr, (int) naval_geo_num('GEO_VISITAS_DIA', 30)) : ['rotas' => [], 'coordenadas' => []];
    $carteira = (array) ($g['carteira'] ?? []);
    if ($rotas !== null) {
        foreach (['esquecidos', 'sem_gps', 'duplicados'] as $k) {
            $carteira[$k] = array_values(array_filter((array) ($carteira[$k] ?? []), function ($x) use ($rotas) { return in_array((string) ($x['r'] ?? ''), $rotas, true); }));
        }
    }
    $hoje = date('Y-m-d', $agora);
    $fimDoDia = $dia < $hoje || date('H', $agora) >= '18';
    $plano = $fimDoDia ? naval_cart_visitas(naval_cart_ler(), $dia, $rotas) : [];
    $visitados = array_values(array_unique(array_filter(array_column($d['pontos'], 'cli'))));
    $lista = naval_alt_aplicar(naval_oco_montar($dia, $terreno, $carteira, $plano, $visitados, $fimDoDia, naval_promotores()), $agora);
    return ['dia' => $dia, 'origem' => $d['origem'], 'erro' => $d['erro'], 'ocorrencias' => $lista, 'tipos' => NAVAL_OCO_TIPOS,
        'abertas' => count(array_filter($lista, function ($o) { return $o['activo']; })), 'fim_do_dia' => $fimDoDia];
}
