<?php
/**
 * Calendário de trabalho das vendas (rotas): horário dos promotores, folgas, feriados e janelas do fecho do dia.
 * É a fonte única para os objectivos, as previsões, o relatório de vendas, as notificações e os prazos do fecho.
 *
 *   - segunda a sexta: vendas das 07:30 às 15:00 (dia completo, peso 1);
 *   - sábado: vendas das 07:30 às 13:00 (meio dia; peso = horas do sábado ÷ horas de um dia de semana);
 *     sábado à tarde e domingo são folga;
 *   - feriados nacionais de Angola (Lei n.º 11/18): folga; mais as folgas que a empresa decidir (pontes);
 *   - fecho do dia: contas com a tesouraria, contagem das carrinhas e requisição ao armazém das 15:30 às 16:00;
 *     carregamento da mercadoria das 16:00 às 17:00. Ao sábado, as mesmas janelas a seguir ao fim das vendas, com o
 *     mesmo intervalo e duração (13:30–14:00 e 14:00–15:00).
 *
 * Configuração (.env), com estes valores por omissão:
 *   HORARIO_SEMANA=07:30-15:00   HORARIO_SABADO=07:30-13:00   HORARIO_FECHO=15:30-16:00   HORARIO_CARGA=16:00-17:00
 *   CALENDARIO_FOLGAS=AAAA-MM-DD,…   (pontes e outras folgas da empresa)
 *   CALENDARIO_TRABALHO=AAAA-MM-DD,… (dias que a empresa decide trabalhar apesar de serem feriado)
 */
defined('NAVAL_NUCLEO') || exit;

/** «HH:MM-HH:MM» → [início, fim] em minutos desde a meia-noite (ou o valor por omissão, se inválido). */
function naval_cal_intervalo(string $chave, string $omissao): array
{
    $ler = function (string $s) {
        if (!preg_match('/^\s*([01]\d|2[0-3]):([0-5]\d)\s*-\s*([01]\d|2[0-3]):([0-5]\d)\s*$/', $s, $m)) return null;
        $a = (int) $m[1] * 60 + (int) $m[2];
        $b = (int) $m[3] * 60 + (int) $m[4];
        return $b > $a ? [$a, $b] : null;
    };
    return $ler(naval_config($chave, $omissao)) ?? $ler($omissao);
}

function naval_cal_hm(int $min): string
{
    return sprintf('%02d:%02d', intdiv($min, 60), $min % 60);
}

/** Domingo de Páscoa (calendário gregoriano, algoritmo de Meeus/Jones/Butcher). */
function naval_cal_pascoa(int $ano): string
{
    $a = $ano % 19;
    $b = intdiv($ano, 100);
    $c = $ano % 100;
    $d = intdiv($b, 4);
    $e = $b % 4;
    $f = intdiv($b + 8, 25);
    $g = intdiv($b - $f + 1, 3);
    $h = (19 * $a + $b - $d - $g + 15) % 30;
    $i = intdiv($c, 4);
    $k = $c % 4;
    $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
    $m = intdiv($a + 11 * $h + 22 * $l, 451);
    $mes = intdiv($h + $l - 7 * $m + 114, 31);
    $dia = (($h + $l - 7 * $m + 114) % 31) + 1;
    return sprintf('%04d-%02d-%02d', $ano, $mes, $dia);
}

/** Feriados nacionais de Angola do ano {AAAA-MM-DD: nome} (Lei n.º 11/18, de 18 de Setembro). */
function naval_cal_feriados(int $ano): array
{
    static $cache = [];
    if (isset($cache[$ano])) return $cache[$ano];
    $p = strtotime(naval_cal_pascoa($ano) . ' 12:00');
    $f = [
        "$ano-01-01" => 'Ano Novo',
        "$ano-02-04" => 'Dia do Início da Luta Armada de Libertação Nacional',
        date('Y-m-d', $p - 47 * 86400) => 'Carnaval',
        "$ano-03-08" => 'Dia Internacional da Mulher',
        "$ano-03-23" => 'Dia da Libertação da África Austral',
        date('Y-m-d', $p - 2 * 86400) => 'Sexta-feira Santa',
        "$ano-04-04" => 'Dia da Paz e da Reconciliação Nacional',
        "$ano-05-01" => 'Dia Internacional do Trabalhador',
        "$ano-09-17" => 'Dia do Fundador da Nação e do Herói Nacional',
        "$ano-11-02" => 'Dia dos Finados',
        "$ano-11-11" => 'Dia da Independência Nacional',
        "$ano-12-25" => 'Dia de Natal e da Família',
    ];
    ksort($f);
    return $cache[$ano] = $f;
}

/** Lista de datas de uma chave de configuração (AAAA-MM-DD separadas por vírgula ou espaço). */
function naval_cal_lista(string $chave): array
{
    $out = [];
    foreach (preg_split('/[\s,;]+/', naval_config($chave, '')) as $d) if (naval_data_valida($d)) $out[$d] = true;
    return $out;
}

/**
 * O dia no calendário de trabalho: {dia, tipo (completo | meio | folga), motivo, feriado, peso, vendas, fecho, carga}.
 * As janelas vêm em «HH:MM» ([início, fim]); num dia de folga ficam null.
 */
function naval_cal_dia(string $dia): array
{
    static $cache = [];
    if (isset($cache[$dia])) return $cache[$dia];
    if (!naval_data_valida($dia)) throw new NavalErro('Data inválida.', 400);
    $semana = naval_cal_intervalo('HORARIO_SEMANA', '07:30-15:00');
    $sabado = naval_cal_intervalo('HORARIO_SABADO', '07:30-13:00');
    $fecho = naval_cal_intervalo('HORARIO_FECHO', '15:30-16:00');
    $carga = naval_cal_intervalo('HORARIO_CARGA', '16:00-17:00');
    $ds = (int) date('N', strtotime($dia . ' 12:00'));
    $feriado = naval_cal_feriados((int) substr($dia, 0, 4))[$dia] ?? null;
    $trabalho = isset(naval_cal_lista('CALENDARIO_TRABALHO')[$dia]);
    $folgaEmpresa = isset(naval_cal_lista('CALENDARIO_FOLGAS')[$dia]);
    $motivo = '';
    if ($ds === 7) $motivo = 'domingo';
    elseif ($feriado !== null && !$trabalho) $motivo = 'feriado';
    elseif ($folgaEmpresa) $motivo = 'folga';
    if ($motivo !== '') {
        return $cache[$dia] = ['dia' => $dia, 'tipo' => 'folga', 'motivo' => $motivo, 'feriado' => $feriado, 'peso' => 0.0,
            'vendas' => null, 'fecho' => null, 'carga' => null];
    }
    $v = $ds === 6 ? $sabado : $semana;
    // o fecho e a carga seguem o fim das vendas com o mesmo intervalo e duração dos dias de semana
    $desloca = $v[1] - $semana[1];
    $janela = function (array $j) use ($desloca) { return [naval_cal_hm($j[0] + $desloca), naval_cal_hm($j[1] + $desloca)]; };
    return $cache[$dia] = ['dia' => $dia, 'tipo' => $ds === 6 ? 'meio' : 'completo', 'motivo' => '', 'feriado' => $feriado,
        'peso' => round(($v[1] - $v[0]) / ($semana[1] - $semana[0]), 3), 'vendas' => [naval_cal_hm($v[0]), naval_cal_hm($v[1])],
        'fecho' => $janela($fecho), 'carga' => $janela($carga)];
}

/** Peso do dia em dias completos de vendas (segunda a sexta 1; sábado ≈ 0,73; folga 0). */
function naval_cal_peso(string $dia): float
{
    return (float) naval_cal_dia($dia)['peso'];
}

function naval_cal_folga(string $dia): bool
{
    return naval_cal_dia($dia)['tipo'] === 'folga';
}

/** Hora (HH:MM) a que terminam as vendas do dia; num dia de folga, a de um dia de semana. */
function naval_cal_fim_vendas(string $dia): string
{
    $c = naval_cal_dia($dia);
    return $c['vendas'][1] ?? naval_cal_hm(naval_cal_intervalo('HORARIO_SEMANA', '07:30-15:00')[1]);
}

/**
 * Fase do dia em $agora (timestamp): folga | antes | vendas | intervalo | fecho | carga | fim. Serve os prazos do
 * fecho (contagem e requisição em atraso depois do fim do fecho; carga em atraso depois do fim da carga).
 */
function naval_cal_fase(int $agora): string
{
    $c = naval_cal_dia(date('Y-m-d', $agora));
    if ($c['tipo'] === 'folga') return 'folga';
    $hm = date('H:i', $agora);
    if ($hm < $c['vendas'][0]) return 'antes';
    if ($hm < $c['vendas'][1]) return 'vendas';
    if ($hm < $c['fecho'][0]) return 'intervalo';
    if ($hm < $c['fecho'][1]) return 'fecho';
    if ($hm < $c['carga'][1]) return 'carga';
    return 'fim';
}

/** Resumo do dia para as páginas: o calendário do dia e a hora do servidor (para os prazos no ecrã). */
function naval_cal_para_pagina(string $dia): array
{
    return naval_cal_dia($dia) + ['agora' => date('Y-m-d H:i')];
}
