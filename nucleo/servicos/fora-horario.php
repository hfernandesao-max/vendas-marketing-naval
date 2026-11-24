<?php
/**
 * Facturas fora do horário de vendas (calendário de trabalho): uma factura emitida depois do fim das vendas (ou antes
 * do início, ou num dia de descanso) é assinalada e avisada na hora, ao coordenador e a quem segue a rota. O bloqueio
 * da facturação é do Winrest; o portal detecta, avisa e mede (indicador por promotor e relatório semanal).
 *
 * Também se assinala a factura emitida num dia posterior à data que leva («turno aberto»): o turno do Winrest de um dia
 * anterior não foi fechado, a factura sai com a data errada e o stock do Pinguim BO muda para trás (ex.: rota 1002,
 * facturas FR0126S77755N/14 a 16 emitidas a 23-11 com a data de 19-11; o fecho FCL A1002/18 só foi lançado a 23-11).
 *
 * Excepção autorizada: o coordenador autoriza antes uma venda fora do horário (rota, dia, até que horas, motivo);
 * as facturas dessa rota e dia até essa hora ficam «autorizadas» e não contam como infracção.
 * Configuração: FORA_HORARIO_TOLERANCIA (minutos depois do fim das vendas, 10), FORA_HORARIO=0 desliga os avisos.
 * Só SELECT no Pinguim BO (as vendas vêm de naval_vendas_sql).
 */
defined('NAVAL_NUCLEO') || exit;

function naval_fh_ficheiro(): string
{
    return naval_pasta('fora-horario') . '/autorizacoes.json';
}

/** Autorizações de venda fora do horário: [{id, rota, dia, ate, motivo, por, em}]. */
function naval_fh_autorizacoes(?string $de = null, ?string $ate = null): array
{
    $l = array_values(array_filter((array) naval_json_ler(naval_fh_ficheiro()), 'is_array'));
    return array_values(array_filter($l, function ($a) use ($de, $ate) {
        $d = (string) ($a['dia'] ?? '');
        return ($de === null || $d >= $de) && ($ate === null || $d <= $ate);
    }));
}

/** O coordenador autoriza uma venda fora do horário (hoje ou num dia seguinte; até 23:59). */
function naval_fh_autorizar(string $rota, string $dia, string $ate, string $motivo, string $por): array
{
    $rota = trim($rota);
    $motivo = trim($motivo);
    if (!preg_match('/^10\d\d$/', $rota)) throw new NavalErro('Rota inválida.', 400);
    if (!naval_data_valida($dia) || $dia < date('Y-m-d')) throw new NavalErro('Só se autoriza para hoje ou para um dia seguinte.', 400);
    if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $ate)) throw new NavalErro('Hora inválida.', 400);
    if (mb_strlen($motivo) < 5) throw new NavalErro('Escreva o motivo da autorização.', 400);
    $a = ['id' => substr(bin2hex(random_bytes(6)), 0, 12), 'rota' => $rota, 'dia' => $dia, 'ate' => $ate, 'motivo' => mb_substr($motivo, 0, 200),
        'por' => $por, 'em' => date('Y-m-d H:i:s')];
    naval_exclusivo('fora-horario', function () use ($a) {
        $l = naval_fh_autorizacoes();
        $l[] = $a;
        // guarda os últimos 400 dias
        $l = array_values(array_filter($l, function ($x) { return (string) $x['dia'] >= date('Y-m-d', time() - 400 * 86400); }));
        naval_json_gravar(naval_fh_ficheiro(), $l);
    });
    naval_registar('venda_fora_horario_autorizada', ['rota' => $rota, 'dia' => $dia, 'ate' => $ate, 'motivo' => $a['motivo']]);
    return $a;
}

/**
 * Facturas fora do horário (função pura, testada). $vendas: documentos de naval_vendas_sql (data, hora HH:MM, rota,
 * promotor, documento, cliente, total, nc); $janela(dia) → [início, fim] das vendas («HH:MM») ou null num dia de
 * descanso; $autorizacoes: [{rota, dia, ate, motivo, por}]. As notas de crédito não contam (corrigem, não vendem).
 * Devolve [{documento, data, hora, rota, promotor, cliente, total, inicio, fim, minutos, motivo (depois | antes |
 * descanso), autorizada, autorizacao}], da mais recente para a mais antiga.
 */
function naval_fh_classificar(array $vendas, callable $janela, array $autorizacoes, int $tolerancia = 10): array
{
    $minutos = function (string $hm): int { return (int) substr($hm, 0, 2) * 60 + (int) substr($hm, 3, 2); };
    $out = [];
    foreach ($vendas as $v) {
        if (!empty($v['nc'])) continue;
        $dia = (string) ($v['data'] ?? '');
        $hora = substr((string) ($v['hora'] ?? ''), 0, 5);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dia) || !preg_match('/^\d{2}:\d{2}$/', $hora)) continue;
        $j = $janela($dia);
        $motivo = null;
        $min = 0;
        $emitida = (string) ($v['emitida'] ?? '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $emitida) && $emitida > $dia) {
            // turno de um dia anterior aberto: em minutos, o atraso entre a data e o dia de emissão
            $motivo = 'turno_aberto';
            $min = (int) round((strtotime($emitida . ' 12:00') - strtotime($dia . ' 12:00')) / 60);
        } elseif ($j === null) $motivo = 'descanso';
        elseif ($minutos($hora) > $minutos((string) $j[1]) + $tolerancia) {
            $motivo = 'depois';
            $min = $minutos($hora) - $minutos((string) $j[1]);
        } elseif ($minutos($hora) < $minutos((string) $j[0])) {
            $motivo = 'antes';
            $min = $minutos((string) $j[0]) - $minutos($hora);
        }
        if ($motivo === null) continue;
        $rota = (string) ($v['rota'] ?? '');
        $aut = null;
        foreach ($motivo === 'turno_aberto' ? [] : $autorizacoes as $a) {
            if ((string) ($a['rota'] ?? '') === $rota && (string) ($a['dia'] ?? '') === $dia && $hora <= (string) ($a['ate'] ?? '')) {
                $aut = ['motivo' => (string) ($a['motivo'] ?? ''), 'por' => (string) ($a['por'] ?? ''), 'ate' => (string) $a['ate']];
                break;
            }
        }
        $out[] = ['documento' => (string) ($v['documento'] ?? $v['chave'] ?? ''), 'data' => $dia, 'hora' => $hora, 'rota' => $rota,
            'promotor' => (string) ($v['promotor'] ?? $rota), 'cliente' => (string) ($v['cliente'] ?? ''), 'total' => round((float) ($v['total'] ?? 0), 2),
            'inicio' => $j[0] ?? null, 'fim' => $j[1] ?? null, 'minutos' => $min, 'motivo' => $motivo, 'autorizada' => $aut !== null, 'autorizacao' => $aut]
            + ($motivo === 'turno_aberto' ? ['emitida' => $emitida] : []);
    }
    usort($out, function ($a, $b) { return strcmp($b['data'] . $b['hora'], $a['data'] . $a['hora']); });
    return $out;
}

/** Indicador por promotor (função pura): [{rota, promotor, facturas, valor, autorizadas, dias, mais_tarde}], pior primeiro. */
function naval_fh_por_promotor(array $lista): array
{
    $p = [];
    foreach ($lista as $f) {
        $r = $f['rota'];
        $x = $p[$r] ?? ['rota' => $r, 'promotor' => $f['promotor'], 'facturas' => 0, 'valor' => 0.0, 'autorizadas' => 0, 'dias' => [], 'mais_tarde' => ''];
        if ($f['autorizada']) $x['autorizadas']++;
        else {
            $x['facturas']++;
            $x['valor'] += (float) $f['total'];
            $x['dias'][$f['data']] = 1;
            if ($f['motivo'] === 'depois' && $f['hora'] > $x['mais_tarde']) $x['mais_tarde'] = $f['hora'];
        }
        $p[$r] = $x;
    }
    $out = array_map(function ($x) { $x['dias'] = count($x['dias']); $x['valor'] = round($x['valor'], 2); return $x; }, array_values($p));
    usort($out, function ($a, $b) { return [$b['facturas'], $b['valor']] <=> [$a['facturas'], $a['valor']] ?: strcmp($a['rota'], $b['rota']); });
    return $out;
}

/** Janela das vendas de um dia no calendário (null num dia de descanso). */
function naval_fh_janela(string $dia): ?array
{
    naval_servico('calendario');
    $c = naval_cal_dia($dia);
    return $c['tipo'] === 'folga' ? null : $c['vendas'];
}

/** Facturas fora do horário de um período (Pinguim BO, guardado 5 minutos). null sem ligação. */
function naval_fh_periodo(string $de, string $ate): ?array
{
    if (!naval_data_valida($de) || !naval_data_valida($ate) || $de > $ate) throw new NavalErro('Intervalo de datas inválido.', 400);
    naval_servico('vendas');
    naval_servico('rotas');
    $v = naval_cache('fora-horario|' . $de . '|' . $ate . '|' . floor(time() / 300) . '|' . naval_rotas_versao(), 300, function () use ($de, $ate) {
        $x = naval_vendas_sql(str_replace('-', '', $de), str_replace('-', '', $ate), naval_promotores(), false);
        return $x === null ? null : naval_vendas_efectivas((array) $x['vendas']);
    });
    if ($v === null) return null;
    $tol = max(0, (int) naval_config('FORA_HORARIO_TOLERANCIA', '10'));
    return naval_fh_classificar((array) $v, 'naval_fh_janela', naval_fh_autorizacoes($de, $ate), $tol);
}

/**
 * Facturas fora do horário que pertencem a um dia: as desse dia e as emitidas nesse dia com a data de um dia anterior
 * (turno aberto, até 7 dias). null sem ligação.
 */
function naval_fh_do_dia(string $dia): ?array
{
    $l = naval_fh_periodo(date('Y-m-d', strtotime($dia . ' 12:00') - 7 * 86400), $dia);
    if ($l === null) return null;
    return array_values(array_filter($l, function ($x) use ($dia) {
        return $x['motivo'] === 'turno_aberto' ? ($x['emitida'] ?? '') === $dia : $x['data'] === $dia;
    }));
}

/**
 * Rotina periódica (de 5 em 5 minutos, das 06:00 às 23:30): avisa cada factura nova fora do horário e não autorizada
 * (uma vez por factura), ao coordenador e a quem segue a rota, e cada factura emitida hoje com a data de um dia anterior
 * (turno do Winrest por fechar). Devolve quantas avisou.
 */
function naval_fh_ciclo(int $agora): ?int
{
    if (naval_config('FORA_HORARIO', '1') === '0' || !function_exists('naval_bd_configurada')) return null;
    naval_servico('bd');
    if (!naval_bd_configurada()) return null;
    $dia = date('Y-m-d', $agora);
    $hm = date('H:i', $agora);
    if ($hm < '06:00' || $hm > '23:30') return null;
    $f = naval_pasta('fora-horario') . '/ciclo.json';
    $e = naval_json_ler($f, ['t' => 0]);
    if ($agora - (int) $e['t'] < 300) return null;
    naval_json_gravar($f, ['t' => $agora], false);
    $lista = naval_fh_do_dia($dia);
    if ($lista === null) return null;
    naval_servico('notificacoes');
    $n = 0;
    foreach ($lista as $x) {
        if ($x['autorizada']) continue;
        $tipo = $x['motivo'] === 'turno_aberto' ? 'fora_horario_turno' : ($x['motivo'] === 'descanso' ? 'fora_horario_descanso' : 'fora_horario');
        $tx = naval_notif_txt($tipo, ['r' => $x['rota'] . ($x['promotor'] !== $x['rota'] ? ' · ' . $x['promotor'] : ''),
            'doc' => $x['documento'], 'h' => $x['hora'], 'v' => number_format((float) $x['total'], 0, ',', ' '), 'f' => (string) ($x['fim'] ?? ''),
            'd' => naval_data_pt($x['data'])]);
        $r = naval_notificar('factura_fora_horario', $tx['pt'][0], $tx['pt'][1],
            ['chave' => 'fora-horario|' . $x['documento'], 'rota' => $x['rota'], 'url' => '../reports/#conciliacao-carrinha', 'textos' => $tx], $agora);
        if (empty($r['repetida'])) $n++;
    }
    return $n;
}
