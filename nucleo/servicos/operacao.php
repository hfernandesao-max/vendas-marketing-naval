<?php
/**
 * Rotina diária automática (tarefa de 5 em 5 minutos, naval_periodicas):
 *
 *  - 18:00 (PLANO_HORA_PROPOSTA): plano de cargas do dia seguinte proposto. Se ainda não houver plano para amanhã,
 *    o portal cria um rascunho com as rotas que costumam sair nesse dia da semana (último registo de cada rota,
 *    quantidades pela previsão) e avisa a Logística, que só confirma e publica. Nunca publica nem envia o plano.
 *  - 20:00 (FECHO_HORA): fecho do dia por rota por correio: vendas líquidas, recebido por meio de cobrança, notas
 *    de crédito, facturas em aberto e estado da conciliação da carrinha. A quem tem a função «fecho-dia» na matriz
 *    (supervisores: só as suas rotas).
 *
 * Cada passo corre uma vez por dia (marca em dados-privados/operacao) e regista o que fez.
 */
defined('NAVAL_NUCLEO') || exit;
require_once __DIR__ . '/bd.php';

function naval_op_hora(string $chave, string $omissao): string
{
    $h = naval_config($chave, $omissao);
    return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $h) ? $h : $omissao;
}

/** Marca de um passo diário (true se ainda não tinha corrido hoje e ficou marcado agora). */
function naval_op_marcar(string $passo, string $dia): bool
{
    $f = naval_pasta('operacao') . '/' . $passo . '-' . $dia . '.json';
    if (is_file($f)) return false;
    naval_json_gravar($f, ['em' => date('Y-m-d H:i:s')]);
    return true;
}

/**
 * Rotas que costumam sair num dia da semana: as que saíram no mesmo dia da semana em pelo menos 2 das últimas
 * 4 semanas publicadas; sem histórico suficiente, todas as que têm sugestão.
 */
function naval_op_rotas_do_dia(string $data, array $sugestoes): array
{
    $ds = date('N', strtotime($data . ' 12:00'));
    $conta = [];
    $semanas = 0;
    foreach (naval_plano_dias(60) as $p) {
        if (($p['data'] ?? '') >= $data || date('N', strtotime($p['data'] . ' 12:00')) !== $ds || empty($p['publicado']['rotas'])) continue;
        if (++$semanas > 4) break;
        foreach ($p['publicado']['rotas'] as $r) if (!empty($r['sai'])) $conta[(string) $r['rota']] = ($conta[(string) $r['rota']] ?? 0) + 1;
    }
    $todas = array_column($sugestoes, 'rota');
    if ($semanas < 2) return $todas;
    return array_values(array_filter($todas, function ($r) use ($conta) { return ($conta[$r] ?? 0) >= 2; }));
}

/** Rascunho do plano de cargas de $data a partir das sugestões; null se já houver plano nesse dia. */
function naval_op_plano_proposto(string $data, ?array $sugestoes = null): ?array
{
    naval_servico('cargas');
    if (naval_plano_ler($data)) return null;
    $listas = naval_plano_listas();
    $sugestoes = $sugestoes ?? naval_plano_com_previsao($data, naval_plano_sugestoes($data, $listas));
    $saem = naval_op_rotas_do_dia($data, $sugestoes);
    $por = array_column($sugestoes, null, 'rota');
    $prom = naval_promotores();
    $rotas = [];
    foreach (naval_rotas_portal() as $rt) {
        $r = (string) $rt['rota'];
        $s = $por[$r] ?? null;
        $rotas[] = ['rota' => $r, 'vendedor' => $prom[$r] ?? '', 'sai' => $s !== null && in_array($r, $saem, true)] + ($s ? array_intersect_key($s, array_flip(['armazem', 'viagens', 'zonas', 'quantidades', 'motorista', 'telefone', 'matricula', 'viatura', 'transportadora'])) : []);
    }
    $p = naval_plano_actualizar($data, 0, $rotas, ['login' => 'automatico'], $listas['artigos']);
    $p['proposto'] = ['em' => date('Y-m-d H:i:s'), 'rotas' => count(array_filter($p['rotas'], function ($r) { return $r['sai']; }))];
    naval_plano_gravar($p);
    naval_registar('plano_proposto', ['data' => $data, 'rotas' => $p['proposto']['rotas']], 'automatico');
    return $p;
}

/** 18:00: propõe o plano de amanhã e avisa a Logística. */
function naval_op_plano_18h(int $agora): ?array
{
    $hoje = date('Y-m-d', $agora);
    if (naval_config('PLANO_PROPOSTA_ACTIVA', '1') === '0' || date('H:i', $agora) < naval_op_hora('PLANO_HORA_PROPOSTA', '18:00')) return null;
    if (!naval_op_marcar('plano', $hoje)) return null;
    $amanha = date('Y-m-d', strtotime($hoje . ' 12:00') + 86400);
    $p = naval_op_plano_proposto($amanha);
    if ($p === null) return ['data' => $amanha, 'resultado' => 'ja_existia'];
    naval_servico('email');
    $dest = naval_destinatarios_recurso('plano-cargas', 'G');
    $url = rtrim(naval_config('PORTAL_URL'), '/');
    if ($dest && naval_email_configurado()) {
        naval_enviar_email($dest, 'Plano de cargas de ' . naval_data_pt($amanha) . ' proposto · ' . $p['proposto']['rotas'] . ' rota(s)',
            '<div style="font:14px Segoe UI,Arial,sans-serif;color:#0a2a52;text-align:justify"><p>O plano de cargas de <b>' . naval_data_pt($amanha) . '</b> está proposto com '
            . $p['proposto']['rotas'] . ' rota(s): motorista, viatura, zonas e quantidades vêm do último registo de cada rota e da previsão de vendas, dentro do peso máximo de cada viatura.</p>'
            . '<p>Confirme, corrija o que mudou e publique no Portal RTM › Logística › Plano de cargas' . ($url !== '' ? ' (' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '/reports/#plano-cargas)' : '') . '. Nada foi enviado aos transportadores.</p></div>');
    }
    return ['data' => $amanha, 'resultado' => 'proposto', 'rotas' => $p['proposto']['rotas']];
}

/**
 * Fecho do dia por rota a partir dos documentos do dia: {rota: {promotor, docs, vendas, nc, recebido, meios: {meio: valor}, aberto}}.
 * Os meios são os do Pinguim BO (com as devoluções e compensações das notas de crédito já atribuídas).
 */
function naval_op_fecho(array $vendas): array
{
    $out = [];
    foreach ($vendas as $v) {
        $r = (string) ($v['rota'] ?? '');
        if ($r === '') continue;
        $x = $out[$r] ?? ['promotor' => (string) ($v['promotor'] ?? $r), 'docs' => 0, 'vendas' => 0.0, 'nc' => 0.0, 'recebido' => 0.0, 'meios' => [], 'aberto' => 0.0];
        $tot = (float) ($v['total'] ?? 0);
        $x['vendas'] += $tot;
        if (!empty($v['nc'])) $x['nc'] += abs($tot);
        else {
            $x['docs']++;
            $x['aberto'] += max(0.0, $tot - (float) ($v['valor_pago'] ?? 0));
        }
        foreach ((array) ($v['pagamentos'] ?? []) as $p) {
            if (($p['codigo'] ?? '') === 'CMP') continue;   // compensação factura × nota de crédito: sem dinheiro
            $m = (string) ($p['meio'] ?? $p['codigo'] ?? '?');
            $x['meios'][$m] = round(($x['meios'][$m] ?? 0) + (float) $p['valor'], 2);
            $x['recebido'] += (float) $p['valor'];
        }
        $out[$r] = $x;
    }
    ksort($out);
    foreach ($out as &$x) foreach (['vendas', 'nc', 'recebido', 'aberto'] as $k) $x[$k] = round($x[$k], 2);
    unset($x);
    return $out;
}

/** Corpo do correio do fecho (só as rotas indicadas). */
function naval_op_fecho_html(string $dia, array $fecho, array $carrinhas): string
{
    $h = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
    $kz = function ($v) { return number_format((float) $v, 0, ',', ' ') . ' Kz'; };
    $th = 'style="background:#1E4FB0;color:#fff;padding:6px 8px;text-align:left;font-size:12px"';
    $td = 'style="padding:6px 8px;border-bottom:1px solid #D5DEEF;font-size:12.5px;vertical-align:top"';
    $tn = 'style="padding:6px 8px;border-bottom:1px solid #D5DEEF;font-size:12.5px;text-align:right;white-space:nowrap"';
    $l = '';
    $t = ['vendas' => 0, 'recebido' => 0, 'nc' => 0, 'aberto' => 0, 'docs' => 0];
    foreach ($fecho as $r => $x) {
        foreach ($t as $k => $_) $t[$k] += $x[$k];
        $meios = [];
        foreach ($x['meios'] as $m => $v) $meios[] = $h($m) . ' ' . $kz($v);
        $c = $carrinhas[$r] ?? '';
        $l .= '<tr><td ' . $td . '><b>' . $h($r) . '</b><br>' . $h($x['promotor']) . '</td><td ' . $tn . '>' . $x['docs'] . '</td><td ' . $tn . '>' . $kz($x['vendas']) . '</td><td ' . $tn . '>' . $kz($x['recebido'])
            . '</td><td ' . $td . '>' . implode('<br>', $meios) . '</td><td ' . $tn . '>' . ($x['nc'] ? '−' . $kz($x['nc']) : '—') . '</td><td ' . $tn . '>' . ($x['aberto'] > 0.5 ? '<b>' . $kz($x['aberto']) . '</b>' : '—') . '</td><td ' . $td . '>' . $h($c) . '</td></tr>';
    }
    return '<div style="font-family:Segoe UI,Arial,sans-serif;color:#0B1735;max-width:1000px;text-align:justify">'
        . '<p style="margin:0 0 4px;font-size:12px;color:#1E4FB0;font-weight:700;letter-spacing:.06em">GRUPO NAVAL · FECHO DO DIA</p>'
        . '<h2 style="margin:0 0 6px;font-size:20px">Fecho de ' . $h(naval_data_pt($dia)) . '</h2>'
        . '<p style="margin:0 0 12px;font-size:13px">Vendas líquidas ' . $kz($t['vendas']) . ' em ' . $t['docs'] . ' documentos · recebido ' . $kz($t['recebido'])
        . ($t['nc'] ? ' · notas de crédito −' . $kz($t['nc']) : '') . ($t['aberto'] > 0.5 ? ' · em aberto ' . $kz($t['aberto']) : '') . '.</p>'
        . '<table style="border-collapse:collapse;width:100%"><thead><tr><th ' . $th . '>Rota</th><th ' . $th . '>Doc.</th><th ' . $th . '>Vendas</th><th ' . $th . '>Recebido</th><th ' . $th . '>Por meio</th><th ' . $th . '>Notas de crédito</th><th ' . $th . '>Em aberto</th><th ' . $th . '>Carrinha</th></tr></thead><tbody>'
        . $l . '</tbody></table><p style="margin:14px 0 0;font-size:11px;color:#8A97B3">Dados do Pinguim BO. Mensagem automática do Portal RTM · Grupo Naval.</p></div>';
}

/** 20:00: fecho do dia por correio (cada destinatário só com as rotas que pode ver). */
function naval_op_fecho_20h(int $agora): ?array
{
    $dia = date('Y-m-d', $agora);
    if (naval_config('FECHO_ACTIVO', '1') === '0' || date('H:i', $agora) < naval_op_hora('FECHO_HORA', '20:00')) return null;
    if (!naval_op_marcar('fecho', $dia)) return null;
    naval_servico('vendas');
    naval_servico('email');
    $d = naval_vendas_sql(str_replace('-', '', $dia), str_replace('-', '', $dia), naval_promotores(), false);
    if ($d === null) return ['erro' => 'Sem ligação ao Pinguim BO.'];
    $fecho = naval_op_fecho((array) $d['vendas']);
    if (!$fecho) return ['rotas' => 0];
    $carr = [];
    try {
        naval_servico('carrinha');
        foreach (naval_carr_vista($dia, null, false)['rotas'] as $r) {
            $e = (string) ($r['resumo']['estado'] ?? '');
            $carr[(string) $r['rota']] = ['por_contar' => 'por contar', 'por_validar' => 'por validar', 'por_aprovar' => 'por aprovar (coordenador)',
                'por_fechar' => 'por aprovar (responsável)', 'aprovada' => 'aprovada'][$e] ?? $e;
        }
    } catch (Throwable $e) {
        error_log('[fecho carrinha] ' . $e->getMessage());
    }
    $enviados = 0;
    if (!naval_email_configurado()) return ['rotas' => count($fecho), 'enviados' => 0];
    // agrupado por âmbito: quem vê todas as rotas recebe um correio; cada supervisor, o das suas rotas
    $grupos = [];
    $m = naval_matriz();
    foreach (naval_utilizadores() as $login => $u) {
        if (!naval_conta_valida($u) || !naval_email_valido($u['email'] ?? null) || ($m['fecho-dia'][$u['perfil']] ?? '') === '') continue;
        $porRota = in_array($u['perfil'], naval_perfis_por_rota(), true);
        $rs = $porRota ? array_values(array_intersect(array_keys($fecho), array_map('strval', (array) ($u['rotas'] ?? [])))) : array_keys($fecho);
        if (!$rs) continue;
        $k = implode(',', $rs);
        $grupos[$k]['rotas'] = $rs;
        $grupos[$k]['para'][] = ['email' => trim((string) $u['email']), 'nome' => (string) ($u['nome'] ?? $login)];
    }
    foreach ($grupos as $g) {
        $f = array_intersect_key($fecho, array_flip($g['rotas']));
        naval_enviar_email($g['para'], 'Fecho do dia ' . naval_data_pt($dia) . ' · ' . count($f) . ' rota(s)', naval_op_fecho_html($dia, $f, $carr));
        $enviados += count($g['para']);
    }
    return ['rotas' => count($fecho), 'enviados' => $enviados];
}
