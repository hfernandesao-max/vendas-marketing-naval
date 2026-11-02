<?php
/**
 * Resumo diário de gestão (por correio electrónico, às 07:00): vendas de ontem face ao habitual, semana, mês, rotas
 * em destaque e em quebra, alertas e previsão do dia. Com o assistente configurado, abre com um parágrafo escrito
 * pela IA a partir destes números (sem IA, o resumo é o mesmo, sem esse parágrafo).
 *
 * Destinatários: as contas activas cujo perfil tem a linha «resumo-diario» na matriz de acessos (editável pelo super
 * administrador). Cada pessoa recebe só as rotas do seu âmbito (supervisores: as suas rotas).
 * Envio: pela tarefa periódica do servidor (naval_periodicas, de 5 em 5 minutos), uma vez por dia, a partir de
 * RESUMO_HORA (07:00 por omissão). Fica registado em dados-privados/resumo/AAAA-MM-DD.json.
 */
defined('NAVAL_NUCLEO') || exit;
require_once __DIR__ . '/analise.php';
require_once __DIR__ . '/alertas.php';
require_once __DIR__ . '/previsao.php';
require_once __DIR__ . '/email.php';
require_once __DIR__ . '/vendas.php';

/** Contas que recebem o resumo: [{login, nome, email, perfil, rotas (null = todas)}]. */
function naval_resumo_destinatarios(): array
{
    $m = naval_matriz();
    $out = [];
    foreach (naval_utilizadores() as $login => $u) {
        $u['login'] = $login;
        if (!naval_conta_valida($u) || !naval_email_valido($u['email'] ?? null)) continue;
        $nivel = $m['resumo-diario'][$u['perfil']] ?? '';
        if ($nivel === '') continue;
        // o resumo segue as permissões de quem o recebe: sem páginas de vendas não recebe; AGT e equipamentos só com acesso
        $nivel = function (string $rec) use ($m, $u) { return (string) ($m[$rec][$u['perfil']] ?? ''); };
        if (!array_filter(NAVAL_PAGINAS_VENDAS, function ($p) use ($nivel) { return $nivel($p) !== ''; })) continue;
        $fontes = ['vendas'];
        if ($nivel('vendas-cliente') !== '') $fontes[] = 'clientes';
        if ($nivel('agt') !== '') $fontes[] = 'agt';
        if ($nivel('equipamentos') !== '') $fontes[] = 'mdm';
        $porRota = in_array($u['perfil'], naval_perfis_por_rota(), true);
        $out[] = ['login' => $login, 'nome' => (string) ($u['nome'] ?? $login), 'email' => trim((string) $u['email']), 'perfil' => $u['perfil'],
            'rotas' => $porRota ? array_values(array_map('strval', (array) ($u['rotas'] ?? []))) : null, 'fontes' => $fontes];
    }
    return $out;
}

/** Números do resumo para o dia $dia (ontem), no âmbito $rotas (null = todas). $agregados: pelo menos 120 dias até $dia. */
function naval_resumo_dados(array $agregados, string $dia, ?array $rotas, array $alertas = [], array $promotores = []): array
{
    $ag = naval_analise_ambito($agregados, $rotas, true);
    $serie = naval_analise_serie($ag, null);
    $t0 = strtotime($dia . ' 12:00');
    $soma = function (int $de, int $ate) use ($serie, $t0) {   // dias antes de $dia (0 = o próprio dia)
        $s = 0.0;
        for ($k = $de; $k <= $ate; $k++) $s += (float) ($serie[date('Y-m-d', $t0 - $k * 86400)] ?? 0);
        return $s;
    };
    $ontem = (float) ($serie[$dia] ?? 0);
    $habitual = naval_mediana(naval_mesmos_dias($serie, $dia, 6));
    $semana = $soma(0, 6);
    $media4 = $soma(7, 34) / 4;
    // mês até $dia face ao mesmo número de dias do mês anterior
    $diaMes = (int) date('j', $t0);
    $mes = 0.0;
    for ($k = 0; $k < $diaMes; $k++) $mes += (float) ($serie[date('Y-m-d', $t0 - $k * 86400)] ?? 0);
    $iniAnt = strtotime(date('Y-m-01', strtotime(date('Y-m-01', $t0) . ' -1 month')) . ' 12:00');
    $diasAnt = min($diaMes, (int) date('t', $iniAnt));
    $mesAnt = 0.0;
    for ($k = 0; $k < $diasAnt; $k++) $mesAnt += (float) ($serie[date('Y-m-d', $iniAnt + $k * 86400)] ?? 0);
    $docs = 0;
    foreach (($ag[$dia]['rotas'] ?? []) as $r) $docs += (int) $r['d'];

    $porRota = [];
    foreach (naval_analise_rotas($ag) as $rt) {
        $s = naval_analise_serie($ag, $rt);
        $h = naval_mediana(naval_mesmos_dias($s, $dia, 6));
        $v = (float) ($s[$dia] ?? 0);
        if ($v == 0 && $h == 0) continue;
        $porRota[] = ['rota' => $rt, 'promotor' => $promotores[$rt] ?? '', 'ontem' => round($v, 2), 'habitual' => round($h, 2),
            'variacao' => $h > 0 ? round($v / $h - 1, 3) : null];
    }
    usort($porRota, function ($a, $b) { return $b['ontem'] <=> $a['ontem']; });
    $prev = naval_previsao_vendas($ag, $dia, 1, $promotores);
    $hoje = is_array($prev['total']) ? $prev['total']['dias'][0] : null;
    $var = function (float $a, float $b) { return $b > 0 ? round($a / $b - 1, 3) : null; };
    return [
        'dia' => $dia,
        'ontem' => ['total' => round($ontem, 2), 'documentos' => $docs, 'habitual' => round($habitual, 2), 'variacao' => $var($ontem, $habitual)],
        'semana' => ['total' => round($semana, 2), 'media_4_semanas' => round($media4, 2), 'variacao' => $var($semana, $media4)],
        'mes' => ['total' => round($mes, 2), 'mes_anterior_mesmos_dias' => round($mesAnt, 2), 'variacao' => $var($mes, $mesAnt), 'dias' => $diaMes],
        'rotas' => $porRota,
        'alertas' => array_values(array_filter($alertas, function ($a) { return $a['gravidade'] !== 'info'; })),
        'destaques' => array_values(array_filter($alertas, function ($a) { return $a['gravidade'] === 'info'; })),
        'previsao_hoje' => $hoje ? ['dia' => $hoje['dia'], 'previsto' => $hoje['previsto'], 'minimo' => $hoje['minimo'], 'maximo' => $hoje['maximo'],
            'precisao' => $prev['total']['qualidade']['precisao']] : null,
    ];
}

/** Correio do resumo (HTML com estilos em linha, cores Naval, texto justificado). */
function naval_resumo_html(array $d, ?string $textoIa, string $ligacao): string
{
    $e = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
    $pct = function ($v) { return $v === null ? '—' : naval_pct((float) $v); };
    $cor = function ($v) { return $v === null ? '#4A5568' : ($v < -0.05 ? '#B42318' : ($v > 0.05 ? '#0057B8' : '#4A5568')); };
    $azul = '#1E3A8A';
    $txt = 'font-family:Segoe UI,Arial,sans-serif;color:#1C2340;font-size:14px;line-height:1.5;text-align:justify';
    $h = '<div style="' . $txt . ';max-width:720px">';
    $h .= '<div style="border-bottom:3px solid ' . $azul . ';padding-bottom:8px;margin-bottom:14px"><div style="font-size:20px;font-weight:700;color:' . $azul
        . '">Resumo diário · Portal RTM</div><div style="color:#4A5568">Vendas de ' . $e(naval_data_pt($d['dia'])) . ' (' . $e(naval_dia_nome_singular($d['dia'])) . ')</div></div>';
    if ($textoIa) {
        $h .= '<div style="background:#E8F0FB;border-left:4px solid #0057B8;padding:10px 12px;margin-bottom:14px">';
        foreach (preg_split('/\n{2,}/', trim($textoIa)) as $par) $h .= '<p style="margin:0 0 6px;text-align:justify">' . nl2br($e(trim($par))) . '</p>';
        $h .= '<div style="font-size:11px;color:#4A5568">Texto escrito pelo assistente IA a partir dos números abaixo.</div></div>';
    }
    $cartao = function (string $titulo, float $valor, string $sub, $v) use ($e, $pct, $cor, $azul) {
        return '<td style="width:33%;padding:8px;border:1px solid #C5D3EA;vertical-align:top"><div style="font-size:12px;color:#4A5568">' . $e($titulo)
            . '</div><div style="font-size:18px;font-weight:700;color:' . $azul . '">' . $e(naval_kz($valor)) . '</div><div style="font-size:12px;color:' . $cor($v) . '">'
            . $e($pct($v)) . ' ' . $e($sub) . '</div></td>';
    };
    $h .= '<table style="border-collapse:collapse;width:100%;margin-bottom:14px"><tr>'
        . $cartao('Ontem · ' . $d['ontem']['documentos'] . ' documentos', $d['ontem']['total'], 'face ao habitual (' . naval_kz($d['ontem']['habitual']) . ')', $d['ontem']['variacao'])
        . $cartao('Últimos 7 dias', $d['semana']['total'], 'face à média das 4 semanas anteriores', $d['semana']['variacao'])
        . $cartao('Mês até ontem (' . $d['mes']['dias'] . ' dias)', $d['mes']['total'], 'face ao mesmo período do mês anterior', $d['mes']['variacao'])
        . '</tr></table>';
    if ($d['previsao_hoje']) {
        $p = $d['previsao_hoje'];
        $h .= '<p style="margin:0 0 14px;text-align:justify"><b>Previsão para hoje (' . $e(naval_data_pt($p['dia'])) . '):</b> ' . $e(naval_kz($p['previsto']))
            . ' (intervalo provável de ' . $e(naval_kz($p['minimo'])) . ' a ' . $e(naval_kz($p['maximo'])) . ')'
            . ($p['precisao'] !== null ? '; precisão do método nos últimos 14 dias: ' . (int) round($p['precisao'] * 100) . ' %.' : '.') . '</p>';
    }
    if ($d['alertas']) {
        $h .= '<div style="font-size:16px;font-weight:700;color:' . $azul . ';margin:6px 0">Alertas (' . count($d['alertas']) . ')</div><ul style="margin:0 0 14px;padding-left:18px">';
        foreach (array_slice($d['alertas'], 0, 12) as $a) {
            $h .= '<li style="margin-bottom:4px;text-align:justify"><span style="color:' . ($a['gravidade'] === 'critico' ? '#B42318' : '#B26B00') . ';font-weight:700">'
                . ($a['gravidade'] === 'critico' ? 'Crítico' : 'Aviso') . '</span> · ' . $e($a['titulo']) . '. <span style="color:#4A5568">' . $e($a['detalhe']) . '</span></li>';
        }
        if (count($d['alertas']) > 12) $h .= '<li>… e mais ' . (count($d['alertas']) - 12) . ' no portal (Gestão › Alertas).</li>';
        $h .= '</ul>';
    }
    if ($d['destaques']) {
        $h .= '<div style="font-size:16px;font-weight:700;color:' . $azul . ';margin:6px 0">Destaques</div><ul style="margin:0 0 14px;padding-left:18px">';
        foreach ($d['destaques'] as $a) $h .= '<li style="margin-bottom:4px;text-align:justify">' . $e($a['titulo']) . '. <span style="color:#4A5568">' . $e($a['detalhe']) . '</span></li>';
        $h .= '</ul>';
    }
    if ($d['rotas']) {
        $h .= '<div style="font-size:16px;font-weight:700;color:' . $azul . ';margin:6px 0">Rotas</div><table style="border-collapse:collapse;width:100%;font-size:13px">'
            . '<tr style="background:' . $azul . ';color:#fff"><th style="padding:5px;text-align:left">Rota</th><th style="padding:5px;text-align:right">Ontem</th>'
            . '<th style="padding:5px;text-align:right">Habitual</th><th style="padding:5px;text-align:right">Variação</th></tr>';
        foreach ($d['rotas'] as $i => $r) {
            $h .= '<tr style="background:' . ($i % 2 ? '#E8F0FB' : '#fff') . '"><td style="padding:5px">' . $e($r['rota'] . ($r['promotor'] ? ' · ' . $r['promotor'] : ''))
                . '</td><td style="padding:5px;text-align:right">' . $e(naval_kz($r['ontem'])) . '</td><td style="padding:5px;text-align:right">' . $e(naval_kz($r['habitual']))
                . '</td><td style="padding:5px;text-align:right;color:' . $cor($r['variacao']) . '">' . $e($pct($r['variacao'])) . '</td></tr>';
        }
        $h .= '</table>';
    }
    $h .= '<p style="font-size:12px;color:#4A5568;margin-top:16px;text-align:justify">Vendas líquidas (facturas − notas de crédito), com IVA, do Pinguim BO. «Habitual» é a '
        . 'mediana do mesmo dia da semana nas últimas 6 semanas. Detalhe no portal: <a href="' . $e($ligacao) . '" style="color:#0057B8">' . $e($ligacao) . '</a>. '
        . 'Recebe este resumo porque o seu perfil o tem na matriz de acessos.</p></div>';
    return $h;
}

function naval_dia_nome_singular(string $dia): string
{
    return ['', 'segunda-feira', 'terça-feira', 'quarta-feira', 'quinta-feira', 'sexta-feira', 'sábado', 'domingo'][naval_dia_semana($dia)];
}

/**
 * Envia o resumo de $dia (omissão: ontem) a todos os destinatários (ou só a $apenas, um login: envio de teste).
 * Agrupa por âmbito (mesmo conjunto de rotas = mesmo correio). Devolve {enviados, falhas, destinatarios}.
 */
function naval_resumo_enviar(?string $dia = null, ?string $apenas = null): array
{
    $dia = $dia ?: date('Y-m-d', time() - 86400);
    $dest = naval_resumo_destinatarios();
    if ($apenas !== null) $dest = array_values(array_filter($dest, function ($d) use ($apenas) { return $d['login'] === $apenas; }));
    if (!$dest) return ['enviados' => 0, 'falhas' => [], 'destinatarios' => 0];
    $ag = naval_analise_agregados(date('Y-m-d', strtotime($dia . ' 12:00') - NAVAL_ANALISE_HISTORICO * 86400), $dia);
    $promotores = naval_promotores();
    $grupos = [];
    foreach ($dest as $d) $grupos[json_encode([$d['rotas'], $d['fontes']])][] = $d;
    $enviados = 0;
    $falhas = [];
    $ligacao = rtrim(naval_config('PORTAL_URL', 'https://geo.naval.co.ao:2051'), '/') . '/reports/#alertas';
    foreach ($grupos as $g) {
        $rotas = $g[0]['rotas'];
        $alertas = [];
        try {
            $alertas = naval_alertas($dia, $rotas, true, $g[0]['fontes'])['alertas'];
        } catch (Throwable $e) {
            error_log('[resumo alertas] ' . $e->getMessage());
        }
        $dados = naval_resumo_dados($ag, $dia, $rotas, $alertas, $promotores);
        $textoIa = null;
        if (naval_config('RESUMO_IA', '1') !== '0') {
            naval_servico('ia');
            $base = $dados;
            $base['alertas'] = array_map(function ($a) { return $a['titulo'] . '. ' . $a['detalhe']; }, array_slice($dados['alertas'], 0, 10));
            $base['destaques'] = array_map(function ($a) { return $a['titulo']; }, $dados['destaques']);
            $textoIa = naval_ia_texto('Escreve um parágrafo de abertura (no máximo 90 palavras) para o resumo diário de vendas da direcção: o essencial de ontem, '
                . 'o que merece atenção hoje e uma recomendação concreta. Sem títulos nem listas.', $base);
        }
        $assunto = 'Resumo diário · vendas de ' . naval_data_pt($dia) . ' · ' . naval_kz($dados['ontem']['total'])
            . ($dados['ontem']['variacao'] !== null ? ' (' . naval_pct($dados['ontem']['variacao']) . ')' : '');
        $r = naval_enviar_email(array_map(function ($d) { return ['email' => $d['email'], 'nome' => $d['nome']]; }, $g), $assunto,
            naval_resumo_html($dados, $textoIa, $ligacao));
        if ($r['ok']) $enviados += count($g);
        else $falhas[] = $r['erro'];
    }
    $res = ['enviados' => $enviados, 'falhas' => $falhas, 'destinatarios' => count($dest)];
    if ($apenas === null) naval_json_gravar(naval_pasta('resumo') . '/' . date('Y-m-d') . '.json', $res + ['dia' => $dia, 'hora' => date('H:i:s')]);
    naval_registar('resumo_diario', $res + ['dia' => $dia, 'teste' => $apenas !== null], 'sistema');
    return $res;
}

/**
 * Tarefas periódicas do servidor (chamadas de 5 em 5 minutos pela tarefa agendada «Portal RTM - Actualizacao urgente»):
 *   - resumo diário: uma vez por dia, a partir de RESUMO_HORA (07:00), se RESUMO_ACTIVO não for 0;
 *   - agregados de análise do dia anterior preparados de madrugada (as páginas abrem mais depressa).
 * Nunca lança excepções: cada tarefa regista as suas falhas.
 */
function naval_periodicas(?int $agora = null): array
{
    $agora = $agora ?? time();
    $feito = [];
    $hora = naval_config('RESUMO_HORA', '07:00');
    if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $hora)) $hora = '07:00';
    $ficheiro = naval_pasta('resumo') . '/' . date('Y-m-d', $agora) . '.json';
    if (naval_config('RESUMO_ACTIVO', '1') !== '0' && date('H:i', $agora) >= $hora && !is_file($ficheiro)) {
        try {
            naval_exclusivo('resumo-diario', function () use ($ficheiro, &$feito) {
                if (is_file($ficheiro)) return;
                $feito['resumo'] = naval_resumo_enviar();
            });
        } catch (Throwable $e) {
            error_log('[resumo diário] ' . $e->getMessage());
            naval_json_gravar($ficheiro, ['erro' => $e->getMessage(), 'hora' => date('H:i:s')]);
            $feito['resumo'] = ['erro' => $e->getMessage()];
        }
    }
    if (date('H', $agora) >= '04' && date('H', $agora) < '07' && !is_file(naval_analise_pasta() . '/' . date('Y-m-d', $agora - 86400) . '.json')) {
        try {
            naval_analise_agregados(date('Y-m-d', $agora - NAVAL_ANALISE_HISTORICO * 86400), date('Y-m-d', $agora - 86400));
            $feito['agregados'] = true;
        } catch (Throwable $e) {
            error_log('[agregados] ' . $e->getMessage());
        }
    }
    return $feito;
}
