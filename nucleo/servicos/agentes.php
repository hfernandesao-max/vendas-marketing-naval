<?php
/**
 * Agentes autónomos do portal: fazem sozinhos o trabalho de rotina e só chamam as pessoas para as excepções. Correm no
 * ciclo periódico (de 5 em 5 minutos) e cada um regista o que fez; nenhum escreve no Pinguim BO (só leitura).
 *
 *   - Fecho do dia (AGENTE_FECHO=0 desliga): ao fim da janela do fecho (16:00 pelo calendário), cada vendedor com
 *     vendas hoje recebe no telemóvel o passo exacto que lhe falta: sincronizar o telemóvel, entregar o dinheiro na
 *     app ou a contagem da carrinha (esta é da Logística, que já a tem nos pendentes).
 *   - Reposição (REQ_EMISSAO_AUTOMATICA=0 desliga): a PO criada pela contagem validada (reposição pelo vendido) sai
 *     emitida sozinha; as regras aprovam-na também sozinha quando não há nada a explicar (requisicoes.php).
 *   - Risco por vendedor (RISCO_LIMIAR, 60): pontuação diária de 0 a 100 a partir das perdas dos últimos 30 dias
 *     (anulados, faltas, falta de dinheiro na entrega, descontos e notas de crédito face às outras rotas); acima do
 *     limiar, a Direcção recebe o aviso com a verificação sugerida (recontagem surpresa da carrinha).
 *   - Relatório semanal (RELATORIO_SEMANAL=0 desliga): à segunda-feira, às 07:30, a Direcção recebe a semana anterior
 *     por rota face à semana de antes, as perdas e os riscos, com um parágrafo escrito pela IA (se configurada).
 */
defined('NAVAL_NUCLEO') || exit;

const NAVAL_RISCO_INDICADORES = [
    'anulados' => 'anulados', 'faltas' => 'faltas na carrinha', 'entrega' => 'falta de dinheiro na entrega',
    'descontos' => 'descontos', 'nc' => 'notas de crédito',
];

/** Marca de «já feito hoje» de um agente (devolve false se já estava feito). */
function naval_ag_marcar(string $agente, string $chave): bool
{
    $f = naval_pasta('agentes') . '/' . $agente . '.json';
    $m = naval_json_ler($f);
    if (($m['feito'] ?? '') === $chave) return false;
    naval_json_gravar($f, ['feito' => $chave, 'em' => date('Y-m-d H:i:s')], false);
    return true;
}

/**
 * Passos em falta de cada rota no fecho (função pura, testada). $dados: {rota: {ULTIMA_VENDA, ULTIMA_SINCRONIZACAO}},
 * $carrinhas: [{rota, resumo: {estado}}], $declaradas e $contadas: [rota]. Só rotas com vendas hoje.
 * Devolve {rota: [passo…]} com passo em sincronizar | dinheiro | carrinha.
 */
function naval_ag_fecho_faltas(array $dados, array $carrinhas, array $declaradas, array $contadas, string $dia, string $fimVendas): array
{
    $estado = [];
    foreach ($carrinhas as $c) $estado[(string) $c['rota']] = (string) ($c['resumo']['estado'] ?? '');
    $out = [];
    foreach ($dados as $rota => $d) {
        $rota = (string) $rota;
        if (substr((string) ($d['ULTIMA_VENDA'] ?? ''), 0, 10) !== $dia) continue;
        $f = [];
        if ((string) ($d['ULTIMA_SINCRONIZACAO'] ?? '') < $dia . ' ' . $fimVendas . ':00') $f[] = 'sincronizar';
        if (!in_array($rota, $declaradas, true) && !in_array($rota, $contadas, true)) $f[] = 'dinheiro';
        if (($estado[$rota] ?? '') === 'por_contar') $f[] = 'carrinha';
        if ($f) $out[$rota] = $f;
    }
    ksort($out);
    return $out;
}

/** Agente do fecho do dia. $o (testes): dados, carrinhas, declaradas, contadas, contas, notificar. */
function naval_ag_fecho(int $agora, array $o = []): ?array
{
    if (naval_config('AGENTE_FECHO', '1') === '0') return null;
    naval_servico('calendario');
    $dia = date('Y-m-d', $agora);
    $cal = naval_cal_dia($dia);
    if ($cal['tipo'] === 'folga') return null;
    $hora = naval_config('AGENTE_FECHO_HORA', (string) ($cal['fecho'][1] ?? '16:00'));
    if (date('H:i', $agora) < $hora) return null;
    if (!isset($o['dados']) && !naval_ag_marcar('fecho', $dia)) return null;
    $dados = $o['dados'] ?? (function () {
        naval_servico('sincronizacao-alertas');
        return naval_sync_rotas_dados() ?? [];
    })();
    if (!$dados) return ['rotas' => 0];
    naval_servico('caixa-entregas');
    $carrinhas = $o['carrinhas'] ?? (function () use ($dia) {
        naval_servico('carrinha');
        return naval_carr_vista($dia, null, false)['rotas'] ?? [];
    })();
    $declaradas = $o['declaradas'] ?? array_map('strval', array_keys(naval_cxe_ler($dia)));
    $contadas = $o['contadas'] ?? (function () use ($dia) {
        $c = naval_caixa_ler($dia);
        return array_values(array_unique(array_map(function ($l) { return (string) $l['rota']; }, array_filter((array) ($c['entregas'] ?? []), function ($l) { return ($l['valor'] ?? null) !== null; }))));
    })();
    $faltas = naval_ag_fecho_faltas($dados, $carrinhas, $declaradas, $contadas, $dia, (string) ($cal['vendas'][1] ?? '15:00'));
    naval_servico('notificacoes');
    $notificar = $o['notificar'] ?? function (array $logins, array $tx, string $chave) {
        naval_notificar('fecho_rota', $tx['pt'][0], $tx['pt'][1], ['so' => $logins, 'textos' => $tx, 'chave' => $chave, 'url' => '../operacoes/']);
    };
    $porRota = naval_perfis_por_rota();
    $contas = $o['contas'] ?? naval_utilizadores();
    $passosTxt = ['sincronizar' => 'sincronize o telemóvel no Winrest', 'dinheiro' => 'registe na app as notas que entrega', 'carrinha' => 'a carrinha ainda não foi contada pela Logística'];
    $enviados = 0;
    foreach ($faltas as $rota => $f) {
        $rota = (string) $rota;   // as chaves numéricas do PHP voltam inteiras
        $logins = [];
        foreach ($contas as $login => $u) {
            if (!naval_conta_valida($u + ['login' => $login]) || !in_array($u['perfil'] ?? '', $porRota, true)) continue;
            if (in_array($rota, array_map('strval', (array) ($u['rotas'] ?? [])), true)) $logins[] = (string) $login;
        }
        if (!$logins) continue;
        $tx = naval_notif_txt('fecho_rota', ['r' => $rota, 'p' => implode('; ', array_map(function ($x) use ($passosTxt) { return $passosTxt[$x]; }, $f))]);
        $notificar($logins, $tx, 'fecho-' . $dia . '-' . $rota);
        $enviados++;
    }
    naval_registar('agente_fecho', ['dia' => $dia, 'rotas' => count($faltas), 'avisadas' => $enviados]);
    return ['rotas' => count($faltas), 'avisadas' => $enviados, 'faltas' => $faltas];
}

/**
 * Pontuação de risco por rota (função pura, testada): cada indicador (anulados, faltas, falta de dinheiro na entrega,
 * descontos, notas de crédito) em proporção das vendas, comparado com a mediana das rotas; cada um vale até 20 pontos
 * (o dobro da mediana dá 10, o triplo ou mais 20). Devolve {rota: {pontos, fatores: [{indicador, rota, mediana}]}}.
 */
function naval_ag_risco(array $rotas): array
{
    $ind = [];
    foreach ($rotas as $r) {
        $v = (float) ($r['vendas'] ?? 0);
        if ($v <= 0) continue;
        $ind[(string) $r['rota']] = [
            'anulados' => (float) ($r['anulados_kz'] ?? 0) / $v, 'faltas' => (float) ($r['falta_kz'] ?? 0) / $v,
            'entrega' => max(0.0, -(float) ($r['entrega_dif_kz'] ?? 0)) / $v, 'descontos' => (float) ($r['desconto_kz'] ?? 0) / $v,
            'nc' => (float) ($r['nc_kz'] ?? 0) / $v];
    }
    $med = [];
    foreach (array_keys(NAVAL_RISCO_INDICADORES) as $k) {
        $l = array_column($ind, $k);
        sort($l);
        $n = count($l);
        $med[$k] = $n ? ($n % 2 ? $l[intdiv($n, 2)] : ($l[$n / 2 - 1] + $l[$n / 2]) / 2) : 0.0;
    }
    $out = [];
    foreach ($ind as $rota => $x) {
        $pontos = 0.0;
        $fatores = [];
        foreach ($x as $k => $val) {
            if ($val <= 0) continue;
            // sem mediana (quase ninguém tem): conta a partir de 0,5 % das vendas
            $ref = $med[$k] > 0 ? $med[$k] : 0.005;
            $razao = $val / $ref;
            if ($razao <= 1) continue;
            $p = min(20.0, ($razao - 1) * 10);
            $pontos += $p;
            if ($p >= 5) $fatores[] = ['indicador' => $k, 'rota' => round($val, 4), 'mediana' => round($med[$k], 4)];
        }
        $out[$rota] = ['pontos' => (int) round(min(100.0, $pontos)), 'fatores' => $fatores];
    }
    uasort($out, function ($a, $b) { return $b['pontos'] <=> $a['pontos']; });
    return $out;
}

/** Agente do risco: uma vez por dia de trabalho (RISCO_HORA, 07:45), os últimos 30 dias; avisa a Direcção acima do limiar. */
function naval_ag_risco_ciclo(int $agora, ?callable $perdas = null, ?callable $notificar = null): ?array
{
    naval_servico('calendario');
    $dia = date('Y-m-d', $agora);
    if (naval_cal_folga($dia) || date('H:i', $agora) < naval_config('RISCO_HORA', '07:45')) return null;
    if (!naval_ag_marcar('risco', $dia)) return null;
    $de = date('Y-m-d', strtotime($dia . ' 12:00') - 30 * 86400);
    $ate = date('Y-m-d', strtotime($dia . ' 12:00') - 86400);
    $p = $perdas ? $perdas($de, $ate) : (function () use ($de, $ate) {
        naval_servico('perdas');
        return naval_perdas_vista($de, $ate, null);
    })();
    $risco = naval_ag_risco((array) ($p['rotas'] ?? []));
    naval_json_gravar(naval_pasta('agentes') . '/risco-ultimo.json', ['dia' => $dia, 'de' => $de, 'ate' => $ate, 'rotas' => $risco], false);
    $limiar = (int) naval_config('RISCO_LIMIAR', '60');
    $altos = array_filter($risco, function ($r) use ($limiar) { return $r['pontos'] >= $limiar; });
    if ($altos) {
        naval_servico('notificacoes');
        $lista = implode(', ', array_map(function ($r, $x) { return $r . ' (' . $x['pontos'] . ')'; }, array_keys($altos), $altos));
        $tx = naval_notif_txt('risco', ['n' => count($altos), 'l' => $lista]);
        $notificar = $notificar ?? function ($tx, $chave) {
            naval_notificar('risco_vendedor', $tx['pt'][0], $tx['pt'][1], ['textos' => $tx, 'chave' => $chave, 'url' => '../reports/#perdas']);
        };
        $notificar($tx, 'risco-' . $dia);
    }
    return ['rotas' => count($risco), 'altos' => array_map('strval', array_keys($altos))];
}

/** Último cálculo do risco (para a página das perdas e a ficha da rota). */
function naval_ag_risco_ultimo(): array
{
    return naval_json_ler(naval_pasta('agentes') . '/risco-ultimo.json');
}

/**
 * Relatório semanal para a Direcção (função de montagem pura, testada): totais da semana e da anterior, por rota, com a
 * variação, as perdas e os riscos. $vendas e $anteriores: [{rota, total, nc}].
 */
function naval_ag_semana_dados(array $vendas, array $anteriores, array $perdas, array $risco, array $promotores): array
{
    $soma = function (array $l) {
        $t = [];
        foreach ($l as $v) if (empty($v['nc'])) $t[(string) $v['rota']] = ($t[(string) $v['rota']] ?? 0) + (float) $v['total'];
        return $t;
    };
    $a = $soma($vendas);
    $b = $soma($anteriores);
    $rotas = [];
    foreach (array_unique(array_merge(array_keys($a), array_keys($b))) as $r) {
        $r = (string) $r;
        $x = round($a[$r] ?? 0, 2);
        $y = round($b[$r] ?? 0, 2);
        $rotas[] = ['rota' => $r, 'promotor' => (string) ($promotores[$r] ?? $r), 'semana' => $x, 'anterior' => $y, 'variacao' => $y > 0 ? round($x / $y - 1, 4) : null,
            'risco' => (int) ($risco[$r]['pontos'] ?? 0)];
    }
    usort($rotas, function ($p, $q) { return $q['semana'] <=> $p['semana']; });
    $ts = array_sum($a);
    $ta = array_sum($b);
    return ['semana' => round($ts, 2), 'anterior' => round($ta, 2), 'variacao' => $ta > 0 ? round($ts / $ta - 1, 4) : null, 'rotas' => $rotas,
        'perdas_kz' => (float) ($perdas['total']['perdas_kz'] ?? 0), 'falta_kz' => (float) ($perdas['total']['falta_kz'] ?? 0),
        'parado_min' => (int) ($perdas['total']['parado_min'] ?? 0)];
}

/** Agente do relatório semanal: segunda-feira (ou o primeiro dia de trabalho da semana), RELATORIO_SEMANAL_HORA (07:30). */
function naval_ag_semanal(int $agora): ?array
{
    if (naval_config('RELATORIO_SEMANAL', '1') === '0') return null;
    naval_servico('calendario');
    $dia = date('Y-m-d', $agora);
    if (date('N', $agora) !== '1' || naval_cal_folga($dia) || date('H:i', $agora) < naval_config('RELATORIO_SEMANAL_HORA', '07:30')) return null;
    if (!naval_ag_marcar('semanal', $dia)) return null;
    naval_servico('vendas');
    naval_servico('rotas');
    naval_servico('perdas');
    $fim = date('Y-m-d', strtotime($dia . ' 12:00') - 86400);   // domingo
    $ini = date('Y-m-d', strtotime($fim . ' 12:00') - 6 * 86400);
    $aFim = date('Y-m-d', strtotime($ini . ' 12:00') - 86400);
    $aIni = date('Y-m-d', strtotime($aFim . ' 12:00') - 6 * 86400);
    $p = naval_promotores();
    $v = naval_vendas_sql(str_replace('-', '', $ini), str_replace('-', '', $fim), $p, false);
    $w = naval_vendas_sql(str_replace('-', '', $aIni), str_replace('-', '', $aFim), $p, false);
    if ($v === null || $w === null) return ['erro' => 'Sem ligação ao Pinguim BO.'];
    $perdas = naval_perdas_vista($ini, $fim, null);
    $d = naval_ag_semana_dados(naval_vendas_efectivas((array) $v['vendas']), naval_vendas_efectivas((array) $w['vendas']), $perdas,
        (array) (naval_ag_risco_ultimo()['rotas'] ?? []), $p) + ['de' => $ini, 'ate' => $fim];
    naval_servico('ia');
    $texto = naval_ia_texto('Escreve três parágrafos curtos para a Direcção sobre a semana de vendas RTM: o que mudou face à semana anterior, as rotas que mais subiram e desceram, as perdas e os riscos, e uma recomendação concreta para esta semana.', $d);
    naval_servico('resumo');
    naval_servico('email');
    $dest = array_values(array_filter(array_map(function ($x) { return $x['rotas'] === null ? $x['email'] : ''; }, naval_resumo_destinatarios())));
    if (!$dest || !naval_email_configurado()) return ['destinatarios' => 0];
    $e = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
    $kz = function ($v) { return number_format((float) $v, 0, ',', ' ') . ' Kz'; };
    $pc = function ($v) { return $v === null ? '—' : ($v > 0 ? '+' : '') . number_format($v * 100, 1, ',', '') . ' %'; };
    $li = '';
    foreach ($d['rotas'] as $r) {
        $li .= '<tr><td style="padding:4px">' . $e($r['rota'] . ' · ' . $r['promotor']) . '</td><td style="padding:4px;text-align:right">' . $kz($r['semana'])
            . '</td><td style="padding:4px;text-align:right">' . $pc($r['variacao']) . '</td><td style="padding:4px;text-align:right">' . ($r['risco'] ?: '—') . '</td></tr>';
    }
    $html = '<div style="font-family:Segoe UI,Arial,sans-serif;font-size:14px;color:#14193a"><h2 style="margin:0 0 8px">Semana de ' . $e(date('d-m-Y', strtotime($ini))) . ' a ' . $e(date('d-m-Y', strtotime($fim))) . '</h2>'
        . '<p>Vendas: <b>' . $kz($d['semana']) . '</b> (' . $pc($d['variacao']) . ' face à semana anterior) · perdas efectivas: <b>' . $kz($d['perdas_kz']) . '</b> · tempo parado: '
        . intdiv($d['parado_min'], 60) . ' h</p>'
        . ($texto ? '<div style="margin:10px 0;padding:10px;background:#f2f4f9;border-radius:8px">' . nl2br($e($texto)) . '</div>' : '')
        . '<table style="border-collapse:collapse;width:100%"><thead><tr><th style="text-align:left;padding:4px">Rota</th><th style="text-align:right;padding:4px">Vendas</th>'
        . '<th style="text-align:right;padding:4px">Variação</th><th style="text-align:right;padding:4px">Risco</th></tr></thead><tbody>' . $li . '</tbody></table>'
        . '<p style="color:#666;font-size:12px">Dados do Pinguim BO, das conciliações e do OLAP. Mensagem automática do Portal RTM.</p></div>';
    naval_enviar_email($dest, 'Semana de vendas RTM · ' . date('d-m', strtotime($ini)) . ' a ' . date('d-m-Y', strtotime($fim)) . ' · ' . $kz($d['semana']), $html);
    naval_registar('relatorio_semanal', ['de' => $ini, 'ate' => $fim, 'destinatarios' => count($dest)]);
    return ['destinatarios' => count($dest)];
}
