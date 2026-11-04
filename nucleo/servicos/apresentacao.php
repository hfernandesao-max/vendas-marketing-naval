<?php
/**
 * Apresentação do portal: números reais do negócio e da automação, recalculados a cada pedido (com cache curta).
 *
 * Negócio: dos agregados diários do Pinguim BO (naval_analise_agregados), já no âmbito de quem pede.
 * Automação: do que o portal fez sozinho (ficheiros da rotina diária: resumos, cálculos da noite, planos propostos,
 * fechos do dia e versões instaladas) e da carteira calculada esta noite.
 * Só agregados: sem clientes, documentos, NIF nem nomes.
 */
const NAVAL_APX_MESES = 6;          // meses mostrados no gráfico (o actual, até hoje, e os 5 anteriores)
const NAVAL_APX_DIAS_AUTOMACAO = 30; // janela da contagem do que o portal fez sozinho

/** Primeiro dia do mês de $dia deslocado $n meses (n negativo = para trás). */
function naval_apx_mes(string $dia, int $n): string
{
    return date('Y-m-01', strtotime(substr($dia, 0, 7) . '-01 12:00 ' . ($n >= 0 ? '+' : '') . $n . ' month'));
}

/**
 * Indicadores de negócio a partir dos agregados diários (já no âmbito). $hoje AAAA-MM-DD.
 * {meses:[{mes, total, documentos, clientes, parcial}], mes:{total, anterior, variacao, documentos, clientes, rotas,
 *  valor_medio}, clientes_30, dias_com_dados, desde, ate}
 */
function naval_apx_negocio(array $ag, string $hoje): array
{
    $meses = [];
    for ($k = NAVAL_APX_MESES - 1; $k >= 0; $k--) {
        $m = substr(naval_apx_mes($hoje, -$k), 0, 7);
        $meses[$m] = ['mes' => $m, 'total' => 0.0, 'documentos' => 0, 'clientes' => [], 'parcial' => $k === 0];
    }
    $iniMes = substr($hoje, 0, 8) . '01';
    $iniAnt = naval_apx_mes($hoje, -1);
    $fimAnt = date('Y-m-d', min(strtotime($iniAnt . ' 12:00') + ((int) substr($hoje, 8, 2) - 1) * 86400, strtotime($iniMes . ' 12:00') - 86400));
    $limite30 = date('Y-m-d', strtotime($hoje . ' 12:00') - 29 * 86400);
    $mes = ['total' => 0.0, 'anterior' => 0.0, 'documentos' => 0, 'clientes' => [], 'rotas' => []];
    $c30 = [];
    $dias = 0;
    $desde = null;
    $compras = 0;
    $valorCompras = 0.0;
    foreach ($ag as $dia => $x) {
        if ($dia > $hoje) continue;
        $tot = 0.0;
        $docs = 0;
        foreach ((array) ($x['rotas'] ?? []) as $rt => $r) {
            $tot += (float) ($r['t'] ?? 0);
            $docs += (int) ($r['d'] ?? 0);
            if ($dia >= $iniMes && (int) ($r['d'] ?? 0) > 0) $mes['rotas'][(string) $rt] = true;
        }
        if ($docs > 0) {
            $dias++;
            if ($desde === null || $dia < $desde) $desde = $dia;
        }
        $m = substr($dia, 0, 7);
        if (isset($meses[$m])) {
            $meses[$m]['total'] += $tot;
            $meses[$m]['documentos'] += $docs;
        }
        foreach ((array) ($x['clientes'] ?? []) as $c => $cl) {
            if (isset($meses[$m])) $meses[$m]['clientes'][$c] = true;
            if ($dia >= $limite30) $c30[$c] = true;
            if ($dia >= $iniMes) {
                $mes['clientes'][$c] = true;
                if ((float) ($cl['t'] ?? 0) > 0) {
                    $compras++;
                    $valorCompras += (float) $cl['t'];
                }
            }
        }
        if ($dia >= $iniMes) {
            $mes['total'] += $tot;
            $mes['documentos'] += $docs;
        } elseif ($dia >= $iniAnt && $dia <= $fimAnt) $mes['anterior'] += $tot;
    }
    return [
        'meses' => array_values(array_map(function ($x) {
            return ['mes' => $x['mes'], 'total' => round($x['total'], 2), 'documentos' => $x['documentos'], 'clientes' => count($x['clientes']), 'parcial' => $x['parcial']];
        }, $meses)),
        'mes' => ['de' => $iniMes, 'total' => round($mes['total'], 2), 'anterior' => round($mes['anterior'], 2),
            'variacao' => $mes['anterior'] > 0 ? round($mes['total'] / $mes['anterior'] - 1, 3) : null,
            'documentos' => $mes['documentos'], 'clientes' => count($mes['clientes']), 'rotas' => count($mes['rotas']),
            'valor_medio' => $compras ? round($valorCompras / $compras, 2) : null],
        // último mês completo face ao anterior e média mensal dos meses completos (comparações estáveis no início do mês)
        'ultimo_mes' => naval_apx_ultimo_mes($meses),
        'media_mensal' => naval_apx_media($meses),
        'clientes_30' => count($c30),
        'dias_com_dados' => $dias,
        'desde' => $desde,
        'ate' => $hoje,
    ];
}

/** Ficheiros AAAA-MM-DD da pasta (com prefixo opcional) dos últimos $dias dias até $hoje; $ok filtra o conteúdo. */
function naval_apx_contar(string $pasta, string $prefixo, string $hoje, int $dias, ?callable $ok = null): int
{
    $de = date('Y-m-d', strtotime($hoje . ' 12:00') - ($dias - 1) * 86400);
    $n = 0;
    foreach (glob($pasta . '/' . $prefixo . '????-??-??.json') ?: [] as $f) {
        $d = substr(basename($f, '.json'), -10);
        if ($d < $de || $d > $hoje) continue;
        if ($ok && !$ok(naval_json_ler($f))) continue;
        $n++;
    }
    return $n;
}

/**
 * O que o portal fez sozinho nos últimos 30 dias e o estado da carteira calculada esta noite (no âmbito de $rotas).
 * Sem $comCarteira (quem pede não vê vendas) a parte da carteira fica a null.
 */
function naval_apx_automacao(string $hoje, ?array $rotas, bool $comCarteira): array
{
    $n = NAVAL_APX_DIAS_AUTOMACAO;
    $versoes = 0;
    naval_servico('actualizacao');
    $f = naval_act_pasta() . '/registo.jsonl';
    $de = date('Y-m-d', strtotime($hoje . ' 12:00') - ($n - 1) * 86400);
    if (is_file($f)) {
        foreach (array_slice(file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [], -2000) as $l) {
            $j = json_decode($l, true);
            if (is_array($j) && ($j['accao'] ?? '') === 'actualizada' && substr((string) ($j['data'] ?? ''), 0, 10) >= $de) $versoes++;
        }
    }
    $out = [
        'dias' => $n,
        'resumos' => naval_apx_contar(naval_pasta('resumo'), '', $hoje, $n, function ($x) { return empty($x['erro']); }),
        'calculos' => naval_apx_contar(naval_pasta('carteira'), '', $hoje, $n),
        'planos' => naval_apx_contar(naval_pasta('operacao'), 'plano-', $hoje, $n),
        'fechos' => naval_apx_contar(naval_pasta('operacao'), 'fecho-', $hoje, $n),
        'versoes' => $versoes,
        'carteira' => null,
    ];
    if (!$comCarteira) return $out;
    naval_servico('carteira');
    $c = naval_cart_ler();
    if (!$c) return $out;
    $dentro = function (string $rota) use ($rotas) { return $rotas === null || in_array($rota, $rotas, true); };
    $estados = ['em_dia' => 0, 'a_abrandar' => 0, 'parado' => 0];
    $encomendas = 0;
    $valorParados = 0.0;
    foreach ((array) ($c['clientes'] ?? []) as $x) {
        if (!$dentro((string) ($x['r'] ?? ''))) continue;
        $e = (string) ($x['estado'] ?? '');
        if (isset($estados[$e])) $estados[$e]++;
        if (!empty($x['encomenda'])) $encomendas++;
        if ($e === 'parado') $valorParados += (float) ($x['media'] ?? 0);
    }
    $out['carteira'] = $estados + [
        'calculado' => (string) ($c['gerado'] ?? ''),
        'encomendas' => $encomendas,
        'valor_parados' => round($valorParados, 2),
        'anomalias' => count(array_filter((array) ($c['anomalias'] ?? []), function ($x) use ($dentro) { return $dentro((string) ($x['rota'] ?? '')); })),
        'qualidade' => count(array_filter((array) ($c['qualidade'] ?? []), function ($x) use ($dentro, $rotas) { return $rotas === null || ((string) ($x['rota'] ?? '') !== '' && $dentro((string) $x['rota'])); })),
    ];
    return $out;
}

/** Último mês completo, o total e a variação face ao mês anterior. */
function naval_apx_ultimo_mes(array $meses): ?array
{
    $completos = array_values(array_filter($meses, function ($m) { return empty($m['parcial']); }));
    $n = count($completos);
    if (!$n) return null;
    $u = $completos[$n - 1];
    $a = $n > 1 ? $completos[$n - 2] : null;
    return ['mes' => $u['mes'], 'total' => round($u['total'], 2),
        'variacao' => $a && $a['total'] > 0 ? round($u['total'] / $a['total'] - 1, 3) : null];
}

/** Média mensal das vendas dos meses completos com vendas. */
function naval_apx_media(array $meses): ?float
{
    $t = array_column(array_filter($meses, function ($m) { return empty($m['parcial']) && $m['total'] > 0; }), 'total');
    return $t ? round(array_sum($t) / count($t), 2) : null;
}

/**
 * Governação, segurança e conformidade (agregados, sem nomes): contas, verificação em dois passos, perfis, recursos
 * controlados pela matriz de acessos, acções registadas este mês e, a quem vê a AGT, a conformidade do mês.
 */
function naval_apx_governanca(bool $veAgt): array
{
    $activas = array_filter(naval_utilizadores(), function ($u) { return !empty($u['activo']); });
    $mfa = count(array_filter($activas, function ($u) { return !empty($u['mfa']); }));
    $reg = naval_pasta_privada() . '/registos/acessos-' . date('Y-m') . '.jsonl';
    $n = 0;
    if (is_file($reg) && ($h = @fopen($reg, 'r'))) {
        while (!feof($h)) $n += substr_count((string) fread($h, 1 << 20), "\n");
        fclose($h);
    }
    $out = ['contas' => count($activas), 'mfa' => $mfa, 'mfa_pct' => $activas ? round($mfa / count($activas), 3) : null,
        'perfis' => count(naval_perfis()), 'recursos' => count(naval_matriz()), 'registos_mes' => $n, 'agt' => null];
    if ($veAgt && naval_bd_configurada()) {
        try {
            naval_servico('agt');
            $docs = naval_cache('apx-agt|' . date('Y-m-d'), 3600, function () {
                return naval_agt(date('Ym01'), date('Ymd'))['documentos'];
            });
            $vivos = array_filter((array) $docs, function ($d) { return empty($d['anulado']); });
            $com = count(array_filter($vivos, function ($d) { return $d['estado'] === 'comunicado'; }));
            $err = count(array_filter($vivos, function ($d) { return $d['estado'] === 'erro'; }));
            $out['agt'] = ['documentos' => count($vivos), 'comunicados' => $com, 'erros' => $err, 'pct' => $vivos ? round($com / count($vivos), 3) : null];
        } catch (Throwable $e) {
            error_log('[apresentacao agt] ' . $e->getMessage());
        }
    }
    return $out;
}

/** Sistemas integrados no portal e o estado de cada ligação (só pela configuração; sem chamadas externas). */
function naval_apx_sistemas(): array
{
    naval_servico('mdm');
    naval_servico('olap');
    naval_servico('actualizacao');
    $bd = naval_bd_configurada();
    return [
        ['nome' => 'Pinguim BO', 'funcao' => 'Vendas, clientes e documentos', 'ligado' => $bd],
        ['nome' => 'AGT', 'funcao' => 'Comunicação fiscal dos documentos', 'ligado' => $bd],
        ['nome' => 'ManageEngine MDM', 'funcao' => 'Telemóveis e impressoras do terreno', 'ligado' => naval_mdm_configurada()],
        ['nome' => 'Servidor OLAP', 'funcao' => 'Localização das equipas no terreno', 'ligado' => naval_olap_configurado()],
        ['nome' => 'Google Maps', 'funcao' => 'Mapas, rotas e prospecção', 'ligado' => naval_config('GOOGLE_MAPS_API_KEY') !== ''],
        ['nome' => 'GitHub', 'funcao' => 'Versões testadas e instalação automática', 'ligado' => naval_act_usa_app() || naval_config('ACTUALIZACAO_GITHUB_TOKEN') !== ''],
        ['nome' => 'Correio electrónico', 'funcao' => 'Resumos, alertas e fechos do dia', 'ligado' => naval_config('EMAIL_MODO') !== ''],
    ];
}
