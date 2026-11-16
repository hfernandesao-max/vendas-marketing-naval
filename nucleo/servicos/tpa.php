<?php
/**
 * Multicaixa por TPA (terminal de pagamento automático): registo dos terminais, conferência dos fechos com o Pinguim BO
 * e com o extracto do banco, e resumo de fim de dia para o Financeiro.
 *
 *   - Registo dos TPA: Ident. TPA (8 dígitos, como no talão), Id. Estab. (estabelecimento no banco), empresa, banco,
 *     conta e rota a que está atribuído. É o registo que dá o banco, a conta e a empresa a cada talão de fecho.
 *   - Fecho do período («Desactivar (fecho)»): na folha de caixa, cada talão com TPA, período, número de operações e
 *     «total a movimentar» (o que o banco credita). TPA + período identificam a linha no extracto; não se repetem.
 *   - Conferência: o fecho de cada TPA é comparado com o Multicaixa registado no Pinguim BO pela rota do terminal no
 *     mesmo dia (e, com PINGUIM_MC_REFERENCIA, pagamento a pagamento pela referência «Per/Tr» escrita no campo
 *     «Número» do pagamento); o Financeiro marca cada fecho «Conferido no extracto», com a referência do movimento.
 *   - Nunca se guarda o número do cartão nem o certificado da transacção (PCI DSS): não são precisos para conferir.
 * Ficheiros: dados-privados/tpa/terminais.json, conferencias.json e config.json.
 */
defined('NAVAL_NUCLEO') || exit;

const NAVAL_TPA_MAX_DIAS = 62;

function naval_tpa_pasta(): string
{
    return naval_pasta('tpa');
}

/** Terminais registados: [ident => {tpa, estab, empresa, banco, conta, rota, activo, nota, alterado_por, alterado_em}]. */
function naval_tpa_terminais(): array
{
    $d = naval_json_ler(naval_tpa_pasta() . '/terminais.json', ['terminais' => []]);
    $out = [];
    foreach ((array) ($d['terminais'] ?? []) as $t) {
        if (is_array($t) && preg_match('/^\d{8}$/', (string) ($t['tpa'] ?? ''))) $out[(string) $t['tpa']] = $t;
    }
    ksort($out);
    return $out;
}

/** Há terminais registados? Sem registo, a folha de caixa mantém os talões como antes (banco + número do talão). */
function naval_tpa_em_uso(): bool
{
    return (bool) naval_tpa_terminais();
}

/** Grava (cria ou altera) um terminal; valida os campos. */
function naval_tpa_gravar(array $p, string $login): array
{
    naval_servico('caixa');
    $tpa = preg_replace('/\D/', '', (string) ($p['tpa'] ?? ''));
    if (!preg_match('/^\d{8}$/', $tpa)) throw new NavalErro('O Ident. TPA tem 8 dígitos, como no talão (ex.: 00444134).', 400);
    $estab = preg_replace('/\D/', '', (string) ($p['estab'] ?? ''));
    if ($estab !== '' && !preg_match('/^\d{1,15}$/', $estab)) throw new NavalErro('O Id. Estab. tem só dígitos (ex.: 0000287047).', 400);
    $empresa = strtoupper((string) ($p['empresa'] ?? ''));
    if (!in_array($empresa, NAVAL_CAIXA_EMPRESAS, true)) throw new NavalErro('Escolha a empresa do TPA.', 400);
    $banco = strtoupper((string) ($p['banco'] ?? ''));
    if (!in_array($banco, NAVAL_CAIXA_BANCOS, true)) throw new NavalErro('Escolha o banco do TPA.', 400);
    $conta = strtoupper(trim(preg_replace('/\s+/', ' ', (string) ($p['conta'] ?? ''))));
    if ($conta !== '' && !preg_match('/^[A-Z0-9][A-Z0-9 .\-]{3,39}$/', $conta)) throw new NavalErro('Conta bancária inválida.', 400);
    $rota = (string) ($p['rota'] ?? '');
    if ($rota !== '' && !preg_match('/^[A-Za-z0-9]{1,10}$/', $rota)) throw new NavalErro('Rota inválida.', 400);
    $novo = ['tpa' => $tpa, 'estab' => $estab, 'empresa' => $empresa, 'banco' => $banco, 'conta' => $conta, 'rota' => $rota,
        'activo' => !array_key_exists('activo', $p) || !empty($p['activo']), 'nota' => naval_texto($p['nota'] ?? '', 120),
        'alterado_por' => $login, 'alterado_em' => date('Y-m-d H:i:s')];
    return naval_exclusivo('tpa', function () use ($novo) {
        $f = naval_tpa_pasta() . '/terminais.json';
        $d = naval_json_ler($f, ['terminais' => []]);
        $lista = naval_tpa_terminais();
        $antes = $lista[$novo['tpa']] ?? null;
        $lista[$novo['tpa']] = $novo + ['criado_em' => $antes['criado_em'] ?? $novo['alterado_em']];
        $d['terminais'] = array_values($lista);
        naval_json_gravar($f, $d);
        naval_registar($antes ? 'tpa_alterado' : 'tpa_registado', ['tpa' => $novo['tpa'], 'banco' => $novo['banco'], 'empresa' => $novo['empresa'], 'rota' => $novo['rota']]);
        return $lista[$novo['tpa']];
    });
}

/** Valida um talão de fecho de TPA da folha de caixa (modo com registo): devolve a linha a guardar. */
function naval_tpa_linha_fecho(array $l, array $terminais, callable $valor): array
{
    $rt = preg_match('/^[A-Za-z0-9]{1,10}$/', (string) ($l['rota'] ?? '')) ? (string) $l['rota'] : '';
    if ($rt === '') throw new NavalErro('Escolha o promotor de cada talão de fecho do TPA.', 400);
    $tpa = preg_replace('/\D/', '', (string) ($l['tpa'] ?? ''));
    $t = $terminais[$tpa] ?? null;
    if (!$t) throw new NavalErro('O TPA ' . ($tpa !== '' ? $tpa : '(sem número)') . ' não está registado: registe-o em Finanças › Multicaixa por TPA › Terminais.', 400);
    if (empty($t['activo'])) throw new NavalErro('O TPA ' . $tpa . ' está desactivado no registo.', 400);
    $per = ltrim(preg_replace('/\D/', '', (string) ($l['periodo'] ?? '')), '0');
    if (!preg_match('/^\d{1,4}$/', $per)) throw new NavalErro('Indique o período («Per») do talão de fecho do TPA ' . $tpa . '.', 400);
    $ops = ($l['operacoes'] ?? '') === '' || $l['operacoes'] === null ? null : (int) $l['operacoes'];
    if ($ops !== null && ($ops < 0 || $ops > 9999)) throw new NavalErro('Número de operações inválido no TPA ' . $tpa . '.', 400);
    return ['rota' => $rt, 'tpa' => $tpa, 'periodo' => $per, 'operacoes' => $ops, 'banco' => $t['banco'], 'empresa' => $t['empresa'],
        'conta' => $t['conta'], 'talao' => $tpa . '/' . $per, 'valor' => $valor($l['valor'] ?? null, 'talões de fecho do TPA'),
        'nota' => naval_texto($l['nota'] ?? '', 120)];
}

/** TPA + período não se repetem: nem no mesmo dia, nem nos 60 dias anteriores. */
function naval_tpa_fechos_unicos(string $dia, array $linhas): void
{
    $vistos = [];
    foreach ($linhas as $l) {
        if (empty($l['tpa'])) continue;
        $k = $l['tpa'] . '/' . $l['periodo'];
        if (isset($vistos[$k])) throw new NavalErro('O fecho ' . $k . ' (TPA/período) está repetido.', 400);
        $vistos[$k] = true;
    }
    if (!$vistos) return;
    $d = strtotime($dia . ' 12:00');
    for ($i = 1; $i <= 60; $i++) {
        $o = date('Y-m-d', $d - $i * 86400);
        $f = naval_caixa_ficheiro($o);
        if (!is_file($f)) continue;
        foreach ((array) (naval_json_ler($f)['tpa'] ?? []) as $l) {
            $k = ($l['tpa'] ?? '') . '/' . ($l['periodo'] ?? '');
            if (isset($vistos[$k])) throw new NavalErro('O fecho ' . $k . ' (TPA/período) já foi registado na caixa de ' . naval_data_pt($o) . '.', 400);
        }
    }
}

// ───────────── Conferência com o extracto ─────────────

function naval_tpa_conferencias(): array
{
    return (array) (naval_json_ler(naval_tpa_pasta() . '/conferencias.json', ['fechos' => []])['fechos'] ?? []);
}

/** Marca (ou retira) «Conferido no extracto» num fecho: chave «dia|tpa|período», referência e data do movimento. */
function naval_tpa_conferir(string $dia, string $tpa, string $periodo, bool $marcar, string $ref, string $dataMov, string $login): array
{
    if (!naval_data_valida($dia)) throw new NavalErro('Data inválida.', 400);
    if (!preg_match('/^\d{8}$/', $tpa) || !preg_match('/^\d{1,4}$/', $periodo)) throw new NavalErro('Fecho inválido.', 400);
    $ref = naval_texto($ref, 40);
    if ($marcar && $ref === '') throw new NavalErro('Indique a referência do movimento no extracto.', 400);
    if ($marcar && !naval_data_valida($dataMov)) throw new NavalErro('Indique a data do movimento no extracto.', 400);
    $k = $dia . '|' . $tpa . '|' . $periodo;
    return naval_exclusivo('tpa-conferencias', function () use ($k, $marcar, $ref, $dataMov, $login, $dia, $tpa, $periodo) {
        $f = naval_tpa_pasta() . '/conferencias.json';
        $d = naval_json_ler($f, ['fechos' => []]);
        if ($marcar) $d['fechos'][$k] = ['ref' => $ref, 'data' => $dataMov, 'por' => $login, 'em' => date('Y-m-d H:i:s')];
        else unset($d['fechos'][$k]);
        naval_json_gravar($f, $d);
        naval_registar($marcar ? 'tpa_conferido' : 'tpa_conferencia_retirada', ['dia' => $dia, 'tpa' => $tpa, 'periodo' => $periodo, 'ref' => $ref]);
        return (array) $d['fechos'];
    });
}

// ───────────── Destinatários do resumo de fim de dia ─────────────

function naval_tpa_config(): array
{
    return naval_json_ler(naval_tpa_pasta() . '/config.json', ['destinatarios' => []]);
}

function naval_tpa_destinatarios(array $lista, string $login): array
{
    $out = [];
    foreach (array_slice($lista, 0, 20) as $e) {
        $e = strtolower(trim((string) $e));
        if ($e === '') continue;
        if (!naval_email_valido($e)) throw new NavalErro('Endereço de correio inválido: ' . $e, 400);
        $out[$e] = true;
    }
    $c = naval_tpa_config();
    $c['destinatarios'] = array_keys($out);
    $c['alterado_por'] = $login;
    $c['alterado_em'] = date('Y-m-d H:i:s');
    naval_json_gravar(naval_tpa_pasta() . '/config.json', $c);
    naval_registar('tpa_destinatarios', ['n' => count($out)]);
    return $c['destinatarios'];
}

// ───────────── Relatório ─────────────

/**
 * Relatório de um período (função pura): $caixas [dia => folha de caixa], $pinguim [dia => [rota => multicaixa]],
 * $terminais, $conferencias, $pagamentos (opcional: pagamentos Multicaixa do Pinguim BO com a referência).
 * Devolve {fechos, bancos, sem_fecho, rever, totais}.
 */
function naval_tpa_montar(array $caixas, array $pinguim, array $terminais, array $conferencias, ?array $pagamentos = null): array
{
    $r2 = function ($v) { return round((float) $v, 2); };
    $fechos = [];
    $porRotaDia = [];
    foreach ($caixas as $dia => $c) {
        foreach ((array) ($c['tpa'] ?? []) as $l) {
            if (empty($l['tpa'])) continue;   // talões antigos (antes do registo dos TPA)
            $t = $terminais[$l['tpa']] ?? [];
            $k = $dia . '|' . $l['tpa'] . '|' . $l['periodo'];
            $fechos[] = ['dia' => $dia, 'tpa' => $l['tpa'], 'periodo' => (string) $l['periodo'], 'rota' => (string) $l['rota'],
                'empresa' => $l['empresa'] ?? ($t['empresa'] ?? ''), 'banco' => $l['banco'] ?? ($t['banco'] ?? ''),
                'conta' => $l['conta'] ?? ($t['conta'] ?? ''), 'estab' => $t['estab'] ?? '', 'operacoes' => $l['operacoes'] ?? null,
                'valor' => $r2($l['valor'] ?? 0), 'estado_caixa' => (string) ($c['estado'] ?? ''), 'conferido' => $conferencias[$k] ?? null,
                'pinguim' => null, 'diferenca' => null, 'partilhado' => false];
            $porRotaDia[$dia . '|' . $l['rota']][] = count($fechos) - 1;
        }
    }
    // Pinguim BO: o Multicaixa da rota no dia; uma rota com mais de um fecho compara-se no conjunto (primeira linha)
    foreach ($porRotaDia as $k => $idx) {
        [$dia, $rota] = explode('|', $k, 2);
        $mc = $r2($pinguim[$dia][$rota] ?? 0);
        $soma = $r2(array_sum(array_map(function ($i) use ($fechos) { return $fechos[$i]['valor']; }, $idx)));
        foreach ($idx as $n => $i) {
            $fechos[$i]['partilhado'] = count($idx) > 1;
            if ($n === 0) {
                $fechos[$i]['pinguim'] = $mc;
                $fechos[$i]['diferenca'] = $r2($soma - $mc);
            }
        }
    }
    // rotas com Multicaixa no Pinguim BO e sem nenhum talão de fecho na caixa (o talão falta)
    $semFecho = [];
    foreach ($pinguim as $dia => $rotas) {
        if (!isset($caixas[$dia])) continue;
        foreach ($rotas as $rota => $mc) {
            if ($mc > 0.005 && !isset($porRotaDia[$dia . '|' . $rota])) {
                $t = null;
                foreach ($terminais as $x) {
                    if (($x['rota'] ?? '') === (string) $rota && !empty($x['activo'])) $t = $x;
                }
                $semFecho[] = ['dia' => $dia, 'rota' => (string) $rota, 'pinguim' => $r2($mc), 'tpa' => $t['tpa'] ?? ''];
            }
        }
    }
    usort($fechos, function ($a, $b) { return [$a['dia'], $a['empresa'], $a['banco'], $a['tpa'], (int) $a['periodo']] <=> [$b['dia'], $b['empresa'], $b['banco'], $b['tpa'], (int) $b['periodo']]; });
    // por empresa, banco e conta (o que aparece no extracto)
    $bancos = [];
    foreach ($fechos as $f) {
        $k = $f['empresa'] . '|' . $f['banco'] . '|' . $f['conta'];
        $bancos[$k] = $bancos[$k] ?? ['empresa' => $f['empresa'], 'banco' => $f['banco'], 'conta' => $f['conta'], 'tpa' => [], 'valor' => 0.0, 'conferido' => 0.0];
        $bancos[$k]['tpa'][$f['tpa']] = true;
        $bancos[$k]['valor'] = $r2($bancos[$k]['valor'] + $f['valor']);
        if ($f['conferido']) $bancos[$k]['conferido'] = $r2($bancos[$k]['conferido'] + $f['valor']);
    }
    $bancos = array_values(array_map(function ($b) { $b['tpa'] = count($b['tpa']); return $b; }, $bancos));
    // pagamentos a rever (com a referência «Per/Tr» do Pinguim BO)
    $rever = null;
    if ($pagamentos !== null) {
        $rever = [];
        $periodos = [];
        foreach ($fechos as $f) $periodos[$f['dia'] . '|' . $f['rota']][] = $f['periodo'];
        foreach ($pagamentos as $p) {
            $ref = trim((string) ($p['ref'] ?? ''));
            $motivo = '';
            if ($ref === '') $motivo = 'sem_referencia';
            elseif (!preg_match('#^0*(\d{1,4})\s*/\s*\d{1,4}$#', $ref, $m)) $motivo = 'formato';
            elseif (isset($periodos[$p['dia'] . '|' . $p['rota']]) && !in_array(ltrim($m[1], '0') ?: '0', $periodos[$p['dia'] . '|' . $p['rota']], true)) $motivo = 'fora_do_periodo';
            if ($motivo !== '') $rever[] = ['dia' => $p['dia'], 'documento' => $p['documento'], 'rota' => $p['rota'], 'valor' => $r2($p['valor']), 'ref' => $ref, 'motivo' => $motivo];
        }
    }
    $tv = $r2(array_sum(array_column($fechos, 'valor')));
    $tp = 0.0;
    foreach ($fechos as $f) if ($f['pinguim'] !== null) $tp += $f['pinguim'];
    foreach ($semFecho as $s) $tp += $s['pinguim'];
    $conf = 0.0;
    foreach ($fechos as $f) if ($f['conferido']) $conf += $f['valor'];
    return ['fechos' => $fechos, 'bancos' => $bancos, 'sem_fecho' => $semFecho, 'rever' => $rever,
        'totais' => ['fechos' => $tv, 'pinguim' => $r2($tp), 'diferenca' => $r2($tv - $tp), 'conferido' => $r2($conf), 'por_conferir' => $r2($tv - $conf),
            'n_fechos' => count($fechos), 'n_conferidos' => count(array_filter($fechos, function ($f) { return (bool) $f['conferido']; })),
            'operacoes' => array_sum(array_map(function ($f) { return (int) ($f['operacoes'] ?? 0); }, $fechos))]];
}

/** Coluna do Pinguim BO (tabela DOCFOOTER) com o «Número» do pagamento (PINGUIM_MC_REFERENCIA), ou ''. */
function naval_tpa_coluna_referencia(): string
{
    $c = strtoupper(trim(naval_config('PINGUIM_MC_REFERENCIA', '')));
    return preg_match('/^[A-Z][A-Z0-9_]{0,39}$/', $c) ? $c : '';
}

/** Pagamentos Multicaixa do Pinguim BO com a referência escrita no «Número» (só com PINGUIM_MC_REFERENCIA). */
function naval_tpa_pagamentos_pinguim(string $de, string $ate): ?array
{
    $col = naval_tpa_coluna_referencia();
    if ($col === '') return null;
    naval_servico('bd');
    naval_servico('vendas');
    if (!naval_bd_configurada()) return null;
    $p = [':de' => naval_bd_data(str_replace('-', '', $de)), ':ate' => naval_bd_data(str_replace('-', '', $ate))];
    $validos = naval_vendas_condicao_validos();
    $out = [];
    foreach (naval_bd_consultar("
        SELECT CONVERT(VARCHAR(10), DH.DOC_DATE, 120) AS DIA,
               COALESCE(STORE.CODE, SUBSTRING(STORE.DESCRIPTION, 1, 4)) AS ROTA,
               RTRIM(LTRIM(COALESCE(DH.DOC_PREFIX, '') + ' ' + CAST(DH.DOC_NUMBER AS VARCHAR(20)))) AS DOCUMENTO,
               CAST(DF.PAYVALUE AS FLOAT) AS VALOR,
               CAST(DF.[$col] AS VARCHAR(60)) AS REF
        FROM DOCFOOTER DF
        INNER JOIN DOCHEADER DH ON DH.ID = DF.DOCHEADER_ID
        INNER JOIN PAYMETHOD PM ON PM.ID = DF.PAYMETHOD_ID
        LEFT JOIN STORE ON STORE.ID = DH.STORE_ID
        WHERE DF.DELETED = 0 AND PM.CODE = 'MC' AND $validos
          AND DH.CALC_SALES_SIGNAL > 0
          AND DH.DOC_DATE >= :de AND DH.DOC_DATE <= :ate", $p) as $r) {
        $out[] = ['dia' => (string) $r['DIA'], 'rota' => (string) $r['ROTA'], 'documento' => (string) $r['DOCUMENTO'], 'valor' => (float) $r['VALOR'], 'ref' => (string) ($r['REF'] ?? '')];
    }
    return $out;
}

/** Relatório de um período a partir das fontes (folhas de caixa, Pinguim BO, registo e conferências). */
function naval_tpa_relatorio(string $de, string $ate): array
{
    if (!naval_data_valida($de) || !naval_data_valida($ate) || $de > $ate) throw new NavalErro('Período inválido.', 400);
    if ((strtotime($ate) - strtotime($de)) / 86400 > NAVAL_TPA_MAX_DIAS) throw new NavalErro('Escolha um período de, no máximo, ' . NAVAL_TPA_MAX_DIAS . ' dias.', 400);
    naval_servico('caixa');
    $caixas = [];
    for ($t = strtotime($de . ' 12:00'); $t <= strtotime($ate . ' 12:00'); $t += 86400) {
        $d = date('Y-m-d', $t);
        if (is_file(naval_caixa_ficheiro($d))) $caixas[$d] = naval_caixa_ler($d);
    }
    // Multicaixa por rota e dia, do Pinguim BO (as mesmas regras da folha de caixa)
    $pinguim = [];
    $ligado = true;
    try {
        naval_servico('bd');
        naval_servico('vendas');
        naval_servico('rotas');
        naval_servico('relatorio-vendas');
        $v = naval_vendas_sql(str_replace('-', '', $de), str_replace('-', '', $ate), naval_promotores(), false);
        if (($v['vendas'] ?? null) === null) $ligado = false;
        $porDia = [];
        foreach ((array) ($v['vendas'] ?? []) as $doc) $porDia[substr((string) $doc['data'], 0, 10)][] = $doc;
        foreach ($porDia as $d => $docs) {
            foreach ((array) naval_caixa_esperado($d, $docs) as $rota => $x) $pinguim[$d][(string) $rota] = (float) $x['multicaixa'];
        }
    } catch (Throwable $e) {
        $ligado = false;
        error_log('[tpa] ' . $e->getMessage());
    }
    $pagamentos = null;
    try {
        $pagamentos = naval_tpa_pagamentos_pinguim($de, $ate);
    } catch (Throwable $e) {
        error_log('[tpa referências] ' . $e->getMessage());
    }
    return naval_tpa_montar($caixas, $pinguim, naval_tpa_terminais(), naval_tpa_conferencias(), $pagamentos)
        + ['de' => $de, 'ate' => $ate, 'pinguim_ligado' => $ligado, 'referencias' => naval_tpa_coluna_referencia() !== ''];
}

// ───────────── Resumo de fim de dia ─────────────

/** HTML do resumo de fim de dia (função pura). */
function naval_tpa_resumo_html(string $dia, array $c, array $r, string $ligacao): string
{
    $e = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
    $kz = function ($v) { return number_format((float) $v, 2, ',', ' ') . ' Kz'; };
    $azul = '#0B1F4B';
    $td = 'padding:7px 10px;border-bottom:1px solid #E4EAF3';
    $tdn = $td . ';text-align:right;white-space:nowrap';
    $th = 'padding:7px 10px;background:#EEF3FB;text-align:left;font-weight:600;color:#3B4A66';
    $h = '<div style="font-family:Segoe UI,Arial,sans-serif;color:#0F1D3A;font-size:14px;line-height:1.5;max-width:680px">';
    $h .= '<div style="background:' . $azul . ';color:#fff;padding:16px 18px;border-radius:10px 10px 0 0"><div style="font-size:12px;letter-spacing:.08em;text-transform:uppercase;color:#AFC4EA;font-weight:700">Resumo de fim de dia · Marketing</div>'
        . '<div style="font-size:20px;font-weight:700">Fecho de caixa de ' . $e(naval_data_pt($dia)) . '</div>'
        . '<div style="font-size:13px;color:#D5E1F6">Validado por ' . $e($c['fechada_por'] ?? '—') . ' · aprovado por ' . $e($c['aprovada_por'] ?? '—') . '</div></div>';
    $h .= '<div style="border:1px solid #D5DEEC;border-top:0;border-radius:0 0 10px 10px;padding:16px 18px">';
    $h .= '<p style="text-align:justify;margin-top:0">Resumo automático do fecho de caixa, para conferência com os extractos bancários e regularização da conta de cartões (POS Card GL). Os valores do Multicaixa são os dos talões de fecho dos TPA, comparados com os pagamentos registados no Pinguim BO.</p>';
    $t = $r['totais'];
    $h .= '<table style="border-collapse:collapse;width:100%;font-size:13px;margin:6px 0 14px"><tr><td style="' . $td . '">Multicaixa (fechos dos TPA)</td><td style="' . $tdn . ';font-weight:700">' . $kz($t['fechos']) . '</td></tr>'
        . '<tr><td style="' . $td . '">Multicaixa no Pinguim BO</td><td style="' . $tdn . '">' . $kz($t['pinguim']) . '</td></tr>'
        . '<tr><td style="' . $td . '">Diferença</td><td style="' . $tdn . ';font-weight:700;color:' . (abs($t['diferenca']) >= 0.01 ? '#9A3412' : '#0F1D3A') . '">' . $kz($t['diferenca']) . '</td></tr>'
        . '<tr><td style="' . $td . '">Numerário entregue</td><td style="' . $tdn . '">' . $kz($c['total_entregas'] ?? 0) . '</td></tr>'
        . '<tr><td style="' . $td . '">Depósitos no banco</td><td style="' . $tdn . '">' . $kz($c['total_saidas'] ?? 0) . '</td></tr>'
        . '<tr><td style="' . $td . '">Saldo final contado</td><td style="' . $tdn . '">' . $kz($c['contagem_fisica'] ?? 0) . '</td></tr></table>';
    $h .= '<div style="font-weight:700;color:' . $azul . ';margin-bottom:6px">Multicaixa por banco, conta e TPA</div>';
    $h .= '<table style="border-collapse:collapse;width:100%;font-size:13px"><tr><th style="' . $th . '">Empresa · banco</th><th style="' . $th . '">Conta</th><th style="' . $th . '">TPA · período</th><th style="' . $th . ';text-align:right">Total a movimentar</th></tr>';
    foreach ($r['fechos'] as $f) {
        $h .= '<tr><td style="' . $td . '">' . $e($f['empresa'] . ' · ' . $f['banco']) . '</td><td style="' . $td . '">' . $e($f['conta'] ?: '—') . '</td><td style="' . $td . '">'
            . $e($f['tpa'] . ' · ' . $f['periodo']) . '</td><td style="' . $tdn . '">' . $kz($f['valor']) . '</td></tr>';
    }
    $h .= '<tr><td colspan="3" style="' . $td . ';font-weight:700;background:#F7F9FD">Total a creditar pelos bancos</td><td style="' . $tdn . ';font-weight:700;background:#F7F9FD">' . $kz($t['fechos']) . '</td></tr></table>';
    $avisos = [];
    foreach ($r['fechos'] as $f) {
        if ($f['diferenca'] !== null && abs($f['diferenca']) >= 0.01) $avisos[] = 'TPA ' . $f['tpa'] . ' (' . $f['banco'] . ', rota ' . $f['rota'] . ', período ' . $f['periodo'] . '): diferença de ' . $kz($f['diferenca']) . ' face ao Pinguim BO.';
    }
    foreach ($r['sem_fecho'] as $s) $avisos[] = 'Rota ' . $s['rota'] . ': ' . $kz($s['pinguim']) . ' de Multicaixa no Pinguim BO sem talão de fecho do TPA na caixa.';
    if ($avisos) {
        $h .= '<div style="margin-top:14px;border:1px solid #F3C99A;background:#FFF7ED;border-radius:8px;padding:10px 12px;text-align:justify"><b style="color:#9A3412">A rever:</b><br>' . implode('<br>', array_map($e, $avisos)) . '</div>';
    }
    $h .= '<p style="margin:16px 0 4px"><a href="' . $e($ligacao) . '" style="display:inline-block;background:#1E4FA3;color:#fff;text-decoration:none;padding:9px 18px;border-radius:8px;font-weight:600">Abrir «Multicaixa por TPA» no portal</a></p>';
    $h .= '<p style="font-size:12px;color:#4A5875;text-align:justify">Mensagem automática do Portal RTM, enviada quando o fecho de caixa é aprovado. No portal, o fecho tem o PDF assinado com o código de verificação e o Excel do dia.</p>';
    return $h . '</div></div>';
}

/** Envia o resumo de fim de dia aos destinatários (quando a caixa é aprovada). Nunca lança excepções. */
function naval_tpa_resumo_enviar(string $dia, array $c): array
{
    try {
        $dest = (array) (naval_tpa_config()['destinatarios'] ?? []);
        if (!$dest) return ['ok' => false, 'erro' => 'sem destinatários'];
        naval_servico('email');
        $r = naval_tpa_relatorio($dia, $dia);
        $lig = rtrim(naval_config('PORTAL_URL', 'https://geo.naval.co.ao:2051'), '/') . '/reports/#multicaixa-tpa?de=' . $dia . '&ate=' . $dia;
        $x = naval_enviar_email(array_map(function ($e) { return ['email' => $e, 'nome' => $e]; }, $dest),
            'Fecho de caixa de ' . naval_data_pt($dia) . ' · Multicaixa por TPA', naval_tpa_resumo_html($dia, $c, $r, $lig));
        naval_registar('tpa_resumo_enviado', ['dia' => $dia, 'destinatarios' => count($dest), 'ok' => $x['ok']]);
        return $x;
    } catch (Throwable $e) {
        error_log('[tpa resumo] ' . $e->getMessage());
        return ['ok' => false, 'erro' => $e->getMessage()];
    }
}
