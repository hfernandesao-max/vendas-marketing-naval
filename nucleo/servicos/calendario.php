<?php
/**
 * Calendário de trabalho das vendas (rotas): horário dos promotores, folgas, feriados e janelas do fecho do dia.
 * É a fonte única para os objectivos, as previsões, o relatório de vendas, as notificações e os prazos do fecho.
 *
 * Horário com data de entrada em vigor (NAVAL_CAL_HORARIOS): cada dia usa sempre o horário que estava em vigor nesse
 * dia, para as conciliações, os prazos e os relatórios de dias passados não mudarem quando o horário muda.
 * Desde 24-11-2026 (horário da Direcção Comercial):
 *   - segunda a sexta: vendas das 07:30 às 15:00 (dia completo, peso 1);
 *   - sábado: vendas das 07:30 às 12:00 (meio dia; peso = horas do sábado ÷ horas de um dia de semana);
 *     sábado à tarde e domingo são folga;
 *   - feriados nacionais de Angola (Lei n.º 11/18): folga; mais as folgas que a empresa decidir (pontes);
 *   - contagem das carrinhas e PO (requisição ao armazém) das 15:00 às 15:30; carga das 15:30 às 16:30. Ao sábado, as
 *     mesmas janelas a seguir ao fim das vendas, com a mesma duração (12:00–12:30 e 12:30–13:30).
 * Até 23-11-2026: vendas 07:30–15:00 (sábado 07:30–13:00), fecho 15:30–16:00, carga 16:00–17:00, lidos do .env:
 *   HORARIO_SEMANA=07:30-15:00   HORARIO_SABADO=07:30-13:00   HORARIO_FECHO=15:30-16:00   HORARIO_CARGA=16:00-17:00
 * Um horário novo entra como uma versão nova em NAVAL_CAL_HORARIOS (fica no histórico do código, com a data).
 *   CALENDARIO_FOLGAS=AAAA-MM-DD,…   (pontes e outras folgas da empresa)
 *   CALENDARIO_TRABALHO=AAAA-MM-DD,… (dias que a empresa decide trabalhar apesar de serem feriado)
 * As folgas da empresa e os dias de trabalho excepcionais também se definem no portal (Administração › Calendário),
 * com motivo e registo de quem decidiu (dados-privados/calendario/dias.json); juntam-se aos do .env. Na véspera de um
 * dia de descanso (feriado ou folga da empresa), às CALENDARIO_HORA_AVISO (17:30), vendedores e Logística são avisados.
 */
defined('NAVAL_NUCLEO') || exit;

/** Versões do horário, da mais antiga para a mais recente; antes da primeira vale o .env (horário anterior). */
const NAVAL_CAL_HORARIOS = [
    ['desde' => '2026-11-24', 'semana' => '07:30-15:00', 'sabado' => '07:30-12:00', 'fecho' => '15:00-15:30', 'carga' => '15:30-16:30'],
];

/** O horário em vigor num dia: {desde, semana, sabado, fecho, carga} em «HH:MM-HH:MM». */
function naval_cal_horario(string $dia): array
{
    $h = ['desde' => '', 'semana' => naval_config('HORARIO_SEMANA', '07:30-15:00'), 'sabado' => naval_config('HORARIO_SABADO', '07:30-13:00'),
        'fecho' => naval_config('HORARIO_FECHO', '15:30-16:00'), 'carga' => naval_config('HORARIO_CARGA', '16:00-17:00')];
    foreach (NAVAL_CAL_HORARIOS as $v) if ($dia >= $v['desde']) $h = $v;
    return $h;
}

/** A próxima mudança de horário depois de $dia (para avisar), ou null. */
function naval_cal_horario_proximo(string $dia): ?array
{
    foreach (NAVAL_CAL_HORARIOS as $v) if ($v['desde'] > $dia) return $v;
    return null;
}

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

/** Janela do horário em vigor no dia ($campo: semana, sabado, fecho ou carga) em minutos. */
function naval_cal_janela(string $dia, string $campo): array
{
    $omissao = ['semana' => '07:30-15:00', 'sabado' => '07:30-13:00', 'fecho' => '15:30-16:00', 'carga' => '16:00-17:00'][$campo];
    $s = (string) (naval_cal_horario($dia)[$campo] ?? $omissao);
    if (!preg_match('/^\s*([01]\d|2[0-3]):([0-5]\d)\s*-\s*([01]\d|2[0-3]):([0-5]\d)\s*$/', $s, $m)) return naval_cal_intervalo('_', $omissao);
    $a = (int) $m[1] * 60 + (int) $m[2];
    $b = (int) $m[3] * 60 + (int) $m[4];
    return $b > $a ? [$a, $b] : naval_cal_intervalo('_', $omissao);
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
    // os definidos no portal (Administração › Calendário)
    $tipo = ['CALENDARIO_FOLGAS' => 'folgas', 'CALENDARIO_TRABALHO' => 'trabalho'][$chave] ?? '';
    if ($tipo !== '') foreach (array_keys((array) (naval_cal_portal()[$tipo] ?? [])) as $d) if (naval_data_valida((string) $d)) $out[(string) $d] = true;
    return $out;
}

/** Folgas e dias de trabalho definidos no portal: {folgas: {dia: {motivo, por, em}}, trabalho: {…}}. */
function naval_cal_portal(bool $recarregar = false): array
{
    static $c = null;
    if ($c === null || $recarregar) $c = naval_json_ler(naval_pasta('calendario') . '/dias.json') + ['folgas' => [], 'trabalho' => []];
    return $c;
}

/**
 * Define um dia no calendário: «folga» (a empresa não trabalha), «trabalho» (trabalha apesar de ser feriado) ou ''
 * (volta ao normal). Só dias de hoje em diante; com motivo. Devolve o calendário do portal.
 */
function naval_cal_definir(string $dia, string $tipo, string $motivo, string $login): array
{
    if (!naval_data_valida($dia)) throw new NavalErro('Data inválida.', 400);
    if ($dia < date('Y-m-d')) throw new NavalErro('Só se mudam dias de hoje em diante.', 400);
    if (!in_array($tipo, ['folga', 'trabalho', ''], true)) throw new NavalErro('Tipo inválido.', 400);
    $motivo = naval_texto($motivo, 120);
    if ($tipo !== '' && mb_strlen($motivo) < 3) throw new NavalErro('Indique o motivo.', 400);
    $f = naval_pasta('calendario') . '/dias.json';
    $c = naval_exclusivo('calendario', function () use ($f, $dia, $tipo, $motivo, $login) {
        $c = naval_json_ler($f) + ['folgas' => [], 'trabalho' => []];
        unset($c['folgas'][$dia], $c['trabalho'][$dia]);
        if ($tipo !== '') $c[$tipo === 'folga' ? 'folgas' : 'trabalho'][$dia] = ['motivo' => $motivo, 'por' => $login, 'em' => date('Y-m-d H:i:s')];
        ksort($c['folgas']);
        ksort($c['trabalho']);
        naval_json_gravar($f, $c);
        return $c;
    });
    naval_registar('calendario_alterado', ['dia' => $dia, 'tipo' => $tipo === '' ? 'normal' : $tipo, 'motivo' => $motivo]);
    naval_cal_portal(true);
    return $c;
}

/**
 * Próximos dias fora do normal (sem os domingos): feriados, folgas da empresa e dias de trabalho excepcionais, de
 * $de a $de + $dias, com o motivo e quem decidiu. Lê sem a cache de naval_cal_dia (pode ter mudado neste pedido).
 */
function naval_cal_excepcoes(string $de, int $dias = 120): array
{
    $p = naval_cal_portal(true);
    $envF = [];
    foreach (preg_split('/[\s,;]+/', naval_config('CALENDARIO_FOLGAS', '')) as $d) if (naval_data_valida($d)) $envF[$d] = true;
    $envT = [];
    foreach (preg_split('/[\s,;]+/', naval_config('CALENDARIO_TRABALHO', '')) as $d) if (naval_data_valida($d)) $envT[$d] = true;
    $out = [];
    $t0 = strtotime($de . ' 12:00');
    for ($i = 0; $i < $dias; $i++) {
        $d = date('Y-m-d', $t0 + $i * 86400);
        $fer = naval_cal_feriados((int) substr($d, 0, 4))[$d] ?? null;
        $trab = $p['trabalho'][$d] ?? (isset($envT[$d]) ? ['motivo' => 'definido no servidor (.env)', 'por' => '', 'em' => ''] : null);
        $folga = $p['folgas'][$d] ?? (isset($envF[$d]) ? ['motivo' => 'definido no servidor (.env)', 'por' => '', 'em' => ''] : null);
        if ($fer === null && $trab === null && $folga === null) continue;
        $tipo = $fer !== null ? ($trab !== null ? 'trabalho' : 'feriado') : ($folga !== null ? 'folga' : 'trabalho');
        $x = $trab ?? $folga ?? ['motivo' => '', 'por' => '', 'em' => ''];
        $out[] = ['dia' => $d, 'tipo' => $tipo, 'feriado' => $fer, 'motivo' => (string) $x['motivo'], 'por' => (string) $x['por'], 'em' => (string) $x['em'],
            'portal' => isset($p['trabalho'][$d]) || isset($p['folgas'][$d])];
    }
    return $out;
}

/**
 * Aviso da véspera (CALENDARIO_HORA_AVISO, 17:30, num dia de trabalho): amanhã é feriado ou folga da empresa (o
 * domingo toda a gente sabe) → notificação a vendedores, supervisores e Logística. Uma vez por dia. Devolve o dia
 * avisado ou null. $notificar (testes): função(titulo, corpo, textos, chave).
 */
function naval_cal_aviso_vespera(int $agora, ?callable $notificar = null): ?string
{
    $hoje = date('Y-m-d', $agora);
    $amanha = date('Y-m-d', strtotime($hoje . ' 12:00') + 86400);
    if (date('H:i', $agora) < naval_config('CALENDARIO_HORA_AVISO', '17:30')) return null;
    if (naval_cal_folga($hoje) || !naval_cal_folga($amanha) || date('N', strtotime($amanha . ' 12:00')) === '7') return null;
    $marca = naval_pasta('calendario') . '/aviso-' . $hoje . '.json';
    if (is_file($marca)) return null;
    naval_json_gravar($marca, ['em' => date('Y-m-d H:i:s', $agora), 'dia' => $amanha], false);
    $c = naval_cal_dia($amanha);
    $motivo = $c['feriado'] ?: ((naval_cal_portal()['folgas'][$amanha]['motivo'] ?? '') ?: 'folga da empresa');
    // o próximo dia de trabalho
    $prox = $amanha;
    for ($i = 1; $i <= 10 && naval_cal_folga($prox); $i++) $prox = date('Y-m-d', strtotime($amanha . ' 12:00') + $i * 86400);
    $dmy = function ($d) { return date('d-m-Y', strtotime($d . ' 12:00')); };
    $v = ['d' => $dmy($amanha), 'm' => $motivo, 'p' => $dmy($prox)];
    naval_servico('notificacoes');
    $tx = naval_notif_txt('vespera', $v);
    $notificar = $notificar ?? function ($tit, $corpo, $textos, $chave) {
        naval_notificar('dia_descanso', $tit, $corpo, ['chave' => $chave, 'textos' => $textos, 'url' => '../gestao/']);
    };
    $notificar($tx['pt'][0], $tx['pt'][1], $tx, 'vespera-' . $amanha);
    return $amanha;
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
    // o horário em vigor nesse dia (um dia passado fica sempre com o horário de então)
    $semana = naval_cal_janela($dia, 'semana');
    $sabado = naval_cal_janela($dia, 'sabado');
    $fecho = naval_cal_janela($dia, 'fecho');
    $carga = naval_cal_janela($dia, 'carga');
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

/** Último dia de trabalho antes de $dia (segunda-feira → sábado; depois de um feriado → o dia útil anterior). */
function naval_cal_util_anterior(string $dia): string
{
    $t = strtotime($dia . ' 12:00');
    for ($i = 1; $i <= 10; $i++) {
        $d = date('Y-m-d', $t - $i * 86400);
        if (!naval_cal_folga($d)) return $d;
    }
    return date('Y-m-d', $t - 86400);
}

/** Hora (HH:MM) a que terminam as vendas do dia; num dia de folga, a de um dia de semana. */
function naval_cal_fim_vendas(string $dia): string
{
    $c = naval_cal_dia($dia);
    return $c['vendas'][1] ?? naval_cal_hm(naval_cal_janela($dia, 'semana')[1]);
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
