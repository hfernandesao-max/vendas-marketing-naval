<?php
/**
 * Controlo de gestão (app «Naval Gestão» e portal): os indicadores que as equipas de controlo das grandes empresas
 * seguem todos os dias, calculados a partir dos mesmos agregados diários do Pinguim BO que o resto do portal
 * (nucleo/servicos/analise.php), já limitados às rotas de cada pessoa.
 *
 *   - Fecho previsto do mês: o que já foi vendido + o habitual de cada dia que falta (mediana do mesmo dia da semana
 *     nas 6 semanas anteriores), face ao mês passado completo.
 *   - Clientes: activos no mês, novos (sem compras nos 60 dias antes do mês) e por recuperar (compraram no mês passado
 *     e ainda não este mês), com os maiores.
 *   - Artigos que mais vendem no mês, face aos mesmos dias do mês passado.
 *   - Produtividade de cada rota: dias com vendas, documentos por dia, valor médio, clientes e notas de crédito.
 *   - Riscos: concentração nos 5 maiores clientes e na maior rota, e peso das notas de crédito.
 */
defined('NAVAL_NUCLEO') || exit;

function naval_controlo(array $ag, int $agora, array $promotores): array
{
    $hoje = date('Y-m-d', $agora);
    $iniMes = date('Y-m-01', $agora);
    $fimMes = date('Y-m-t', $agora);
    $iniAnt = date('Y-m-01', strtotime($iniMes . ' 12:00 -1 month'));
    $fimAntCompleto = date('Y-m-t', strtotime($iniAnt . ' 12:00'));
    $diasMes = (int) date('j', $agora);
    $fimAntMesmos = date('Y-m-d', min(strtotime($iniAnt . ' 12:00') + ($diasMes - 1) * 86400, strtotime($fimAntCompleto . ' 12:00')));
    $diaT = function (string $d) use ($ag): float {
        $s = 0.0;
        foreach (($ag[$d]['rotas'] ?? []) as $r) $s += (float) $r['t'];
        return $s;
    };
    $soma = function (string $de, string $ate) use ($diaT): float {
        $s = 0.0;
        foreach (naval_analise_dias($de, $ate) as $d) $s += $diaT($d);
        return $s;
    };
    $serie = [];
    foreach (array_keys($ag) as $d) $serie[$d] = $diaT($d);
    $habitual = function (string $d) use ($serie): float {
        return naval_mediana(naval_mesmos_dias($serie, $d, 6));
    };

    // 1. fecho previsto do mês
    $ateOntem = $diasMes > 1 ? $soma($iniMes, date('Y-m-d', $agora - 86400)) : 0.0;
    $hojeT = $diaT($hoje);
    $hojeHab = $habitual($hoje);
    $falta = 0.0;
    foreach (naval_analise_dias(date('Y-m-d', $agora + 86400), $fimMes) as $d) if ($d > $hoje) $falta += $habitual($d);
    $previsto = $ateOntem + max($hojeT, $hojeHab) + $falta;
    $antCompleto = $soma($iniAnt, $fimAntCompleto);
    $fecho = [
        'ate_hoje' => round($ateOntem + $hojeT, 2), 'previsto' => round($previsto, 2), 'mes_anterior' => round($antCompleto, 2),
        'variacao' => $antCompleto > 0 ? round($previsto / $antCompleto - 1, 3) : null,
        'progresso' => $previsto > 0 ? round(($ateOntem + $hojeT) / $previsto, 3) : null,
        'dias_passados' => $diasMes, 'dias_mes' => (int) date('t', $agora),
    ];

    // 2. clientes
    $clientesEm = function (string $de, string $ate) use ($ag): array {
        $c = [];
        foreach (naval_analise_dias($de, $ate) as $d) {
            foreach (($ag[$d]['clientes'] ?? []) as $k => $x) {
                if ((float) $x['t'] <= 0) continue;
                $c[$k] = ['n' => $x['n'], 'r' => $x['r'], 't' => ($c[$k]['t'] ?? 0) + (float) $x['t'], 'd' => ($c[$k]['d'] ?? 0) + (int) $x['d']];
            }
        }
        return $c;
    };
    $cMes = $clientesEm($iniMes, $hoje);
    $cAntMesmos = $clientesEm($iniAnt, $fimAntMesmos);
    $cAnt = $clientesEm($iniAnt, $fimAntCompleto);
    $antes = $clientesEm(date('Y-m-d', strtotime($iniMes . ' 12:00') - 60 * 86400), date('Y-m-d', strtotime($iniMes . ' 12:00') - 86400));
    $novos = array_diff_key($cMes, $antes);
    $recuperar = array_diff_key($cAnt, $cMes);
    uasort($recuperar, function ($a, $b) { return $b['t'] <=> $a['t']; });
    $lista = function (array $c, int $n) {
        $out = [];
        foreach (array_slice($c, 0, $n, true) as $k => $x) $out[] = ['codigo' => (string) $k, 'nome' => $x['n'], 'rota' => $x['r'], 'valor' => round($x['t'], 2), 'compras' => $x['d']];
        return $out;
    };
    $clientes = [
        'activos' => count($cMes), 'activos_anterior' => count($cAntMesmos),
        'novos' => count($novos), 'por_recuperar' => count($recuperar),
        'valor_por_recuperar' => round(array_sum(array_column($recuperar, 't')), 2),
        'recuperar' => $lista($recuperar, 5),
    ];

    // 3. artigos que mais vendem
    $artigosEm = function (string $de, string $ate) use ($ag): array {
        $a = [];
        foreach (naval_analise_dias($de, $ate) as $d) {
            foreach (($ag[$d]['artigos'] ?? []) as $porRota) {
                foreach ($porRota as $cod => $x) {
                    $a[$cod] = ['n' => $x['n'], 'u' => $x['u'], 'q' => ($a[$cod]['q'] ?? 0) + (float) $x['q'], 't' => ($a[$cod]['t'] ?? 0) + (float) $x['t']];
                }
            }
        }
        return $a;
    };
    $aMes = $artigosEm($iniMes, $hoje);
    $aAnt = $artigosEm($iniAnt, $fimAntMesmos);
    uasort($aMes, function ($a, $b) { return $b['t'] <=> $a['t']; });
    $totArt = array_sum(array_column($aMes, 't'));
    $artigos = [];
    foreach (array_slice($aMes, 0, 5, true) as $cod => $x) {
        $ant = (float) ($aAnt[$cod]['t'] ?? 0);
        $artigos[] = ['codigo' => (string) $cod, 'nome' => $x['n'], 'unidade' => $x['u'], 'quantidade' => round($x['q'], 2), 'valor' => round($x['t'], 2),
            'peso' => $totArt > 0 ? round($x['t'] / $totArt, 3) : null, 'variacao' => $ant > 0 ? round($x['t'] / $ant - 1, 3) : null];
    }

    // 4. produtividade de cada rota no mês
    $rotas = [];
    foreach (naval_analise_dias($iniMes, $hoje) as $d) {
        foreach (($ag[$d]['rotas'] ?? []) as $rt => $x) {
            $r = $rotas[$rt] ?? ['rota' => (string) $rt, 'promotor' => $promotores[$rt] ?? '', 'total' => 0.0, 'docs' => 0, 'nc' => 0, 'dias' => 0];
            $r['total'] += (float) $x['t'];
            $r['docs'] += (int) $x['d'];
            $r['nc'] += (int) ($x['nc'] ?? 0);
            if ((int) $x['d'] > 0) $r['dias']++;
            $rotas[$rt] = $r;
        }
    }
    $porRotaClientes = [];
    foreach ($cMes as $c) $porRotaClientes[$c['r']] = ($porRotaClientes[$c['r']] ?? 0) + 1;
    foreach ($rotas as $rt => &$r) {
        $r['total'] = round($r['total'], 2);
        $r['docs_dia'] = $r['dias'] ? round($r['docs'] / $r['dias'], 1) : 0;
        $r['valor_medio'] = $r['docs'] ? round($r['total'] / $r['docs'], 2) : null;
        $r['clientes'] = $porRotaClientes[$rt] ?? 0;
        $r['peso_nc'] = ($r['docs'] + $r['nc']) ? round($r['nc'] / ($r['docs'] + $r['nc']), 3) : 0;
    }
    unset($r);
    usort($rotas, function ($a, $b) { return $b['total'] <=> $a['total']; });

    // 5. riscos
    $totMes = array_sum(array_column($cMes, 't'));
    $top = $cMes;
    uasort($top, function ($a, $b) { return $b['t'] <=> $a['t']; });
    $nc = array_sum(array_column($rotas, 'nc'));
    $docs = array_sum(array_column($rotas, 'docs'));
    $totRotas = array_sum(array_column($rotas, 'total'));
    $riscos = [
        'top5_clientes' => $totMes > 0 ? round(array_sum(array_column(array_slice($top, 0, 5), 't')) / $totMes, 3) : null,
        'maior_rota' => $rotas && $totRotas > 0 ? ['rota' => $rotas[0]['rota'], 'peso' => round($rotas[0]['total'] / $totRotas, 3)] : null,
        'notas_credito' => $nc, 'peso_notas_credito' => ($docs + $nc) ? round($nc / ($docs + $nc), 3) : 0,
    ];
    return ['fecho' => $fecho, 'clientes' => $clientes, 'artigos' => $artigos, 'rotas' => $rotas, 'riscos' => $riscos];
}
