<?php
/**
 * Análise de gestão (nucleo/servicos/analise.php, alertas.php, previsao.php), com o âmbito de quem pede.
 *
 * GET v1/alertas[?dia=AAAA-MM-DD]   alertas de anomalias do dia (omissão: ontem) — recurso «alertas»
 * GET v1/previsao                   previsão dos próximos 7 dias por rota e qualidade medida — recurso «previsao»
 * GET v1/painel                     painel de gestão RTM (tendência, ABC, distribuição numérica, valor e artigos por compra,
 *                                   mapa de calor, carteira) — recurso «painel-gestao»
 * GET v1/indicadores                indicadores do dia para a app de gestão: hoje (ao vivo) face à previsão, ontem, semana,
 *                                   mês, classificação das rotas e número de alertas — recurso «app-gestao»
 */
defined('NAVAL_API') || exit;   // só pela API central (api/index.php): token JWT já validado
naval_servico('analise');
naval_servico('vendas');
@set_time_limit(300);

$f = (string) ($_GET['f'] ?? '');
$rotas = naval_rotas();
$veValores = naval_ve_vendas();

if ($f === 'alertas') {
    naval_exigir(['alertas']);
    naval_servico('alertas');
    $dia = (string) ($_GET['dia'] ?? '');
    if ($dia !== '' && (!naval_data_valida($dia) || $dia >= date('Y-m-d'))) throw new NavalErro('Dia inválido (AAAA-MM-DD, até ontem).', 400);
    $chave = 'alertas|' . ($dia ?: date('Y-m-d', time() - 86400)) . '|' . json_encode($rotas) . '|' . (int) $veValores . '|' . implode(',', naval_alertas_fontes());
    $fresco = ($_GET['fresco'] ?? '') === '1';
    $r = naval_cache($chave, 600, function () use ($dia, $rotas, $veValores) {
        return naval_alertas($dia ?: null, $rotas, $veValores, naval_alertas_fontes());
    }, $fresco);
    naval_json(['ok' => true] + $r);
}

if ($f === 'previsao') {
    naval_exigir(['previsao']);
    naval_servico('previsao');
    if (!$veValores) throw new NavalErro('A previsão de vendas precisa de acesso às páginas de vendas.', 403);
    $ult = date('Y-m-d', time() - 86400);
    $r = naval_cache('previsao|' . $ult . '|' . json_encode($rotas), 600, function () use ($ult, $rotas) {
        $ag = naval_analise_ambito(naval_analise_agregados(date('Y-m-d', time() - 90 * 86400), $ult), $rotas, true);
        return naval_previsao_vendas($ag, $ult, 7, naval_promotores());
    }, ($_GET['fresco'] ?? '') === '1');
    naval_json(['ok' => true] + $r);
}

if ($f === 'indicadores') {
    naval_exigir(['app-gestao']);
    naval_servico('previsao');
    naval_servico('alertas');
    if (!$veValores) throw new NavalErro('Os indicadores de gestão precisam de acesso às páginas de vendas.', 403);
    $r = naval_cache('indicadores|' . date('Y-m-d H') . floor((int) date('i') / 5) . '|' . json_encode($rotas), 300, function () use ($rotas) {
        return naval_indicadores(time(), $rotas);
    }, ($_GET['fresco'] ?? '') === '1');
    $r['alertas'] = null;
    if (naval_pode('alertas')) {
        try {
            $a = naval_cache('alertas|' . date('Y-m-d', time() - 86400) . '|' . json_encode($rotas) . '|1|' . implode(',', naval_alertas_fontes()), 600, function () use ($rotas) {
                return naval_alertas(null, $rotas, true, naval_alertas_fontes());
            });
            $cont = ['critico' => 0, 'aviso' => 0, 'info' => 0];
            foreach ($a['alertas'] as $x) $cont[$x['gravidade']]++;
            $r['alertas'] = $cont + ['principais' => array_map(function ($x) { return ['gravidade' => $x['gravidade'], 'titulo' => $x['titulo'], 'tipo' => $x['tipo'], 'p' => $x['p'] ?? []]; },
                array_slice(array_values(array_filter($a['alertas'], function ($x) { return $x['gravidade'] !== 'info'; })), 0, 5))];
        } catch (Throwable $e) {
            error_log('[indicadores alertas] ' . $e->getMessage());
        }
    }
    naval_json(['ok' => true] + $r);
}

if ($f === 'painel') {
    naval_exigir(['painel-gestao']);
    naval_servico('painel');
    if (!$veValores) throw new NavalErro('O painel de gestão precisa de acesso às páginas de vendas.', 403);
    $hoje = date('Y-m-d');
    $r = naval_cache('painel|' . $hoje . '|' . floor(time() / 900) . '|' . json_encode($rotas), 900, function () use ($hoje, $rotas) {
        $ag = naval_analise_ambito(naval_analise_agregados(date('Y-m-d', time() - NAVAL_PAINEL_DIAS * 86400), $hoje), $rotas, true);
        return naval_painel($ag, $hoje, naval_promotores());
    }, ($_GET['fresco'] ?? '') === '1');
    naval_json(['ok' => true] + $r);
}

throw new NavalErro('Recurso desconhecido.', 404);
