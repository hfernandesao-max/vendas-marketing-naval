<?php
/**
 * Envio automático do ficheiro de vendas para o SAP (todos os dias, à hora configurada; por omissão 16:00).
 *
 * Ciclo (nucleo/servicos/resumo.php, o mesmo dos agentes do fecho do dia):
 *   1. às 16:00 emite um lote com as vendas ainda não emitidas (fonte fiscal: Pinguim BO), em nome do «Envio
 *      automático», com a numeração e os controlos de sempre (um documento só entra num lote; impressão digital);
 *      o período começa no dia do último lote automático, para apanhar as vendas sincronizadas depois do envio;
 *   2. documentos com erro (sem artigos, sem depósito SAP, linha sem código) ficam de fora e seguem listados; as rotas
 *      que ainda não sincronizaram depois do fim das vendas também (as vendas entram no envio seguinte);
 *   3. envia o Excel por email aos destinatários configurados na página do SAP, com a ligação «Já carreguei no SAP»
 *      (código de uso único, 14 dias; só a impressão digital do código fica guardada);
 *   4. quando o lote é dado como carregado (pela ligação ou na página do SAP), responde no mesmo assunto a quem tem
 *      de continuar o trabalho («avisar quando carregado»), com quem carregou e a referência do SAP.
 * Sem vendas por enviar num dia de trabalho, segue só a nota «sem vendas». Se o email falhar, tenta de novo no ciclo
 * seguinte (até às 23:00), sem gerar outro lote. SAP_ENVIO=0 no .env desliga o envio.
 * Estado: dados-privados/sap/envio.json.
 */
defined('NAVAL_NUCLEO') || exit;

naval_servico('sap');
naval_servico('sap-ficheiro');

const NAVAL_SAP_ENVIO_HORA = '16:00';
const NAVAL_SAP_ENVIO_DIAS_LIGACAO = 14;
const NAVAL_SAP_ENVIO_SISTEMA = ['login' => 'sistema', 'nome' => 'Envio automático', 'perfil' => ''];

/** Configuração do envio (dentro de sap/config.json): {activo, hora, para: [email], avisar: [email]}. */
function naval_sap_envio_config(?array $cfg = null): array
{
    $e = (array) (($cfg ?? naval_sap_config())['envio'] ?? []);
    $lista = function ($x) { return array_values(array_filter(array_map('strval', (array) $x), 'naval_email_valido')); };
    return ['activo' => !empty($e['activo']), 'hora' => preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) ($e['hora'] ?? '')) ? (string) $e['hora'] : NAVAL_SAP_ENVIO_HORA,
        'para' => $lista($e['para'] ?? []), 'avisar' => $lista($e['avisar'] ?? [])];
}

/** Valida a configuração do envio vinda da página (emails separados por vírgula, ponto e vírgula ou linha). */
function naval_sap_envio_limpar($e): array
{
    $e = (array) $e;
    $lista = function ($x, string $rot) {
        $x = is_array($x) ? $x : preg_split('/[\s,;]+/', (string) $x);
        $out = [];
        foreach ((array) $x as $m) {
            $m = strtolower(trim((string) $m));
            if ($m === '') continue;
            if (!naval_email_valido($m)) throw new NavalErro('Endereço de email inválido em «' . $rot . '»: ' . $m);
            $out[$m] = true;
        }
        if (count($out) > 10) throw new NavalErro('No máximo 10 endereços em «' . $rot . '».');
        return array_keys($out);
    };
    $hora = trim((string) ($e['hora'] ?? NAVAL_SAP_ENVIO_HORA));
    if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $hora)) throw new NavalErro('Hora do envio automático inválida (use hh:mm, por exemplo 16:00).');
    $r = ['activo' => !empty($e['activo']), 'hora' => $hora, 'para' => $lista($e['para'] ?? [], 'Enviar para'), 'avisar' => $lista($e['avisar'] ?? [], 'Avisar quando carregado')];
    if ($r['activo'] && !$r['para']) throw new NavalErro('Indique pelo menos um endereço em «Enviar para» para ligar o envio automático.');
    return $r;
}

function naval_sap_envio_estado(): array
{
    return naval_json_ler(naval_sap_pasta() . '/envio.json');
}

function naval_sap_envio_gravar_estado(array $e): void
{
    naval_json_gravar(naval_sap_pasta() . '/envio.json', $e, false);
}

/**
 * Período do envio de $dia: desde o dia do último lote automático válido (até 7 dias antes), para as vendas
 * sincronizadas depois do envio desse dia entrarem agora (função pura, testada).
 */
function naval_sap_envio_periodo(array $lotes, string $dia): array
{
    $de = $dia;
    $limite = date('Y-m-d', strtotime($dia . ' 12:00 -7 days'));
    foreach ($lotes as $l) {
        if (empty($l['automatico']) || ($l['estado'] ?? '') === 'anulado') continue;
        $ate = (string) ($l['ate'] ?? '');
        if ($ate >= $limite && $ate < $de) $de = $ate;
    }
    return [$de, $dia];
}

/** Vendas e linhas do período lidas da fonte fiscal (Pinguim BO), como v1/vendas?fiscal=1. null sem ligação. */
function naval_sap_envio_vendas(string $de, string $ate): ?array
{
    naval_servico('bd');
    naval_servico('pinguim');
    naval_servico('vendas');
    naval_servico('rotas');
    @set_time_limit(300);
    $GLOBALS['naval_fontes_fiscal'] = true;
    $prom = naval_promotores();
    $a = str_replace('-', '', $de);
    $b = str_replace('-', '', $ate);
    try {
        $x = naval_vendas_sql($a, $b, $prom, false);
        $vendas = $x !== null ? $x['vendas'] : naval_vendas_do_xml(naval_pinguim_xml($a, $b), $prom, false)['vendas'];
        $artigos = naval_vendas_artigos_sql($a, $b, $prom);
        if ($artigos === null) $artigos = naval_vendas_do_xml(naval_pinguim_xml($a, $b), $prom, true)['artigos'];
    } catch (Throwable $e) {
        error_log('[envio SAP] ' . $e->getMessage());
        return null;
    }
    return ['vendas' => (array) $vendas, 'artigos' => (array) $artigos, 'promotores' => $prom];
}

/** Rotas com vendas hoje cujo telemóvel ainda não sincronizou depois do fim das vendas. */
function naval_sap_envio_por_sincronizar(string $dia, string $fimVendas): array
{
    try {
        naval_servico('sincronizacao-alertas');
        $d = naval_sync_rotas_dados() ?? [];
    } catch (Throwable $e) {
        return [];
    }
    $out = [];
    foreach ($d as $rota => $x) {
        if (substr((string) ($x['ULTIMA_VENDA'] ?? ''), 0, 10) !== $dia) continue;
        if ((string) ($x['ULTIMA_SINCRONIZACAO'] ?? '') < $dia . ' ' . $fimVendas . ':00') $out[] = (string) $rota;
    }
    sort($out);
    return $out;
}

/** Ligação «Já carreguei no SAP» do lote (código de uso único). */
function naval_sap_envio_ligacao(int $n, string $codigo): string
{
    return rtrim(naval_config('PORTAL_URL', ''), '/') . '/app/sap-carregado.php?l=' . $n . '&c=' . rawurlencode($codigo);
}

/**
 * Ciclo do envio automático. $o (testes): agora já em $agora; vendas {vendas, artigos, promotores}; por_sincronizar;
 * enviar (callable como naval_enviar_email). Devolve o que fez ou null.
 */
function naval_sap_envio_ciclo(int $agora, array $o = []): ?array
{
    if (naval_config('SAP_ENVIO', '1') === '0') return null;
    $cfg = naval_sap_config();
    $env = naval_sap_envio_config($cfg);
    if (!$env['activo'] || !$env['para']) return null;
    $dia = date('Y-m-d', $agora);
    if (date('H:i', $agora) < $env['hora']) return null;
    $enviar = $o['enviar'] ?? 'naval_enviar_email';
    $est = naval_sap_envio_estado();
    if (($est['dia'] ?? '') === $dia) {
        // já tratado hoje; se o email falhou, tenta de novo (o mesmo lote) até às 23:00
        if (!empty($est['enviado']) || date('H:i', $agora) >= '23:00') return null;
        return naval_sap_envio_email($est, $env, $enviar);
    }
    naval_servico('calendario');
    $cal = naval_cal_dia($dia);
    [$de, $ate] = naval_sap_envio_periodo(naval_sap_lotes(), $dia);
    // lê também os 7 dias antes, para apanhar as facturas emitidas hoje com a data de um dia anterior (turno aberto)
    $dados = $o['vendas'] ?? naval_sap_envio_vendas(date('Y-m-d', strtotime($de . ' 12:00') - 7 * 86400), $ate);
    if ($dados === null) {
        // sem ligação ao Pinguim BO: tenta de novo no ciclo seguinte; às 19:00 avisa e desiste por hoje
        if (date('H:i', $agora) < '19:00') return ['erro' => 'sem_ligacao'];
        $est = ['dia' => $dia, 'de' => $de, 'ate' => $ate, 'lote' => null, 'falha' => 'Sem ligação ao Pinguim BO: o ficheiro não foi gerado. Gere-o na página do SAP do portal.', 'enviado' => false];
        naval_sap_envio_gravar_estado($est);
        return naval_sap_envio_email($est, $env, $enviar);
    }
    $L = naval_sap_linhas($dados['vendas'], $dados['artigos'], $cfg, $o['origens'] ?? naval_origem_stock($cfg['armazens']), naval_sap_exportados(naval_sap_lotes()),
        (array) ($dados['promotores'] ?? []), $de, $ate);
    $fimVendas = (string) ($cal['vendas'][1] ?? '15:00');
    $est = ['dia' => $dia, 'de' => $de, 'ate' => $ate, 'lote' => null, 'erros' => array_column($L['erros'], 'texto'), 'avisos' => $L['avisos'],
        'por_sincronizar' => $o['por_sincronizar'] ?? naval_sap_envio_por_sincronizar($dia, $fimVendas), 'enviado' => false];
    if (!$L['linhas']) {
        // num dia de folga sem vendas não se envia nada; num dia de trabalho, a nota «sem vendas»
        if (($cal['tipo'] ?? '') === 'folga' && !$L['erros']) {
            naval_sap_envio_gravar_estado($est + ['enviado' => true, 'sem_email' => true]);
            return ['dia' => $dia, 'lote' => null, 'email' => false];
        }
        naval_sap_envio_gravar_estado($est);
        return naval_sap_envio_email($est, $env, $enviar);
    }
    $total = 0.0;
    foreach ($L['docs'] as $v) $total += (float) ($v['total'] ?? 0);
    $omissao = array_values(array_filter($L['linhas'], function ($l) { return $l['como'] === 'omissao'; }));
    $codigo = bin2hex(random_bytes(20));
    $lote = naval_sap_novo_lote([
        'de' => $de, 'ate' => $ate, 'rotas' => [], 'documentos' => array_map(function ($v) { return (string) $v['documento']; }, $L['docs']),
        'linhas' => count($L['linhas']), 'total' => naval_sap_r2($total),
        'omissao' => ['linhas' => count($omissao), 'rotas' => array_values(array_unique(array_column($omissao, 'rota'))), 'local' => (string) ($cfg['local_omissao'] ?? '')],
    ], naval_sap_xlsx($L['linhas']), NAVAL_SAP_ENVIO_SISTEMA, [
        'automatico' => true,
        'confirmacao' => ['hash' => hash('sha256', $codigo), 'expira' => date('Y-m-d H:i:s', $agora + NAVAL_SAP_ENVIO_DIAS_LIGACAO * 86400)],
        'numerario' => naval_sap_r2(array_sum(array_map(function ($v) { return naval_sap_meio_doc($v, 'dinheiro'); }, $L['docs']))),
        'multicaixa' => naval_sap_r2(array_sum(array_map(function ($v) { return naval_sap_meio_doc($v, 'multicaixa'); }, $L['docs']))),
    ]);
    $est['lote'] = (int) $lote['numero'];
    // o código só vive no estado até o email seguir (para reenviar se falhar); depois fica só a impressão digital
    $est['codigo'] = $codigo;
    naval_sap_envio_gravar_estado($est);
    return naval_sap_envio_email($est, $env, $enviar);
}

/** Assunto do email do envio (o aviso de carregado responde no mesmo assunto). */
function naval_sap_envio_assunto(array $est, ?array $lote): string
{
    $per = naval_data_pt($est['de']) . ($est['de'] !== $est['ate'] ? ' – ' . naval_data_pt($est['ate']) : '');
    return 'RTM sales for SAP / Vendas RTM para o SAP · ' . $per . ($lote ? ' · batch / lote ' . $lote['numero'] : ' · no file / sem ficheiro');
}

/** Envia (ou reenvia) o email do estado do dia. */
function naval_sap_envio_email(array $est, array $env, callable $enviar): array
{
    $lote = null;
    $xlsx = null;
    if (!empty($est['lote'])) {
        $lotes = naval_sap_lotes();
        $lote = $lotes[naval_sap_procurar($lotes, (int) $est['lote'])];
        $xlsx = (string) file_get_contents(naval_sap_pasta() . '/lote-' . $lote['numero'] . '.xlsx');
    }
    $assunto = naval_sap_envio_assunto($est, $lote);
    $para = array_map(function ($e) { return ['email' => $e, 'nome' => '']; }, $env['para']);
    $anexos = $lote ? [['nome' => $lote['ficheiro'], 'tipo' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'conteudo' => $xlsx]] : [];
    $r = $enviar($para, $assunto, naval_sap_envio_html($est, $lote), $anexos, $env['avisar'] ? ['responder_a' => ['email' => $env['avisar'][0], 'nome' => '']] : []);
    $ok = !empty($r['ok']);
    if ($ok) {
        unset($est['codigo']);
        $est['enviado'] = true;
    }
    $est['tentativas'] = (int) ($est['tentativas'] ?? 0) + 1;
    $est['erro_email'] = $ok ? null : (string) ($r['erro'] ?? '');
    naval_sap_envio_gravar_estado($est);
    if ($lote) {
        naval_sap_lote_anotar((int) $lote['numero'], function (array $l) use ($ok, $env, $assunto, $r) {
            $l['envio'] = ['em' => date('Y-m-d H:i:s'), 'para' => $env['para'], 'assunto' => $assunto, 'ok' => $ok, 'erro' => $ok ? null : (string) ($r['erro'] ?? '')];
            return $l;
        });
    }
    naval_registar($ok ? 'sap_envio' : 'sap_envio_falhou', ['dia' => $est['dia'], 'lote' => $lote['numero'] ?? null, 'para' => $env['para']] + ($ok ? [] : ['erro' => $r['erro'] ?? '']));
    return ['dia' => $est['dia'], 'lote' => $lote['numero'] ?? null, 'email' => $ok];
}

/** Corpo do email do envio (inglês e português). */
function naval_sap_envio_html(array $est, ?array $lote): string
{
    $h = function ($t) { return htmlspecialchars((string) $t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); };
    $kz = function ($v) { return number_format((float) $v, 2, ',', ' ') . ' Kz'; };
    $td = 'style="padding:6px 10px;border-bottom:1px solid #E3E8F2"';
    $tdv = 'style="padding:6px 10px;border-bottom:1px solid #E3E8F2;text-align:right;white-space:nowrap"';
    $l = function ($a, $b) use ($h, $td, $tdv) { return '<tr><td ' . $td . '>' . $h($a) . '</td><td ' . $tdv . '>' . $h($b) . '</td></tr>'; };
    $per = naval_data_pt($est['de']) . ($est['de'] !== $est['ate'] ? ' – ' . naval_data_pt($est['ate']) : '');
    $s = '<div style="font-family:Segoe UI,Arial,sans-serif;color:#0B1F3A;max-width:720px">'
        . '<h2 style="color:#273777;margin:0 0 4px">RTM sales for SAP · Vendas RTM para o SAP</h2>'
        . '<p style="margin:0 0 14px;color:#44546D">' . $h($per) . '</p>';
    if (!empty($est['falha'])) {
        $s .= '<p style="color:#B42318"><b>The file was not generated (no connection to Pinguim BO). · ' . $h($est['falha']) . '</b></p>';
    } elseif ($lote) {
        $s .= '<p>The attached file is ready to load into SAP. · O ficheiro em anexo está pronto para carregar no SAP.</p>'
            . '<table style="border-collapse:collapse;width:100%;margin:0 0 16px"><tbody>'
            . $l('Batch / Lote', (string) $lote['numero'])
            . $l('File / Ficheiro', $lote['ficheiro'])
            . $l('Documents / Documentos', (string) $lote['n_documentos'])
            . $l('Lines / Linhas', (string) $lote['linhas'])
            . $l('Total invoiced / Total facturado', $kz($lote['total']))
            . (isset($lote['numerario']) ? $l('Cash / Numerário', $kz($lote['numerario'])) : '')
            . (isset($lote['multicaixa']) ? $l('Card (Multicaixa)', $kz($lote['multicaixa'])) : '')
            . $l('SHA-256', substr($lote['sha256'], 0, 16) . '…')
            . '</tbody></table>';
        if (!empty($est['codigo'])) {
            $s .= '<p style="margin:18px 0"><a href="' . $h(naval_sap_envio_ligacao((int) $lote['numero'], $est['codigo'])) . '" style="background:#273777;color:#fff;padding:10px 18px;border-radius:8px;text-decoration:none;font-weight:600">I have loaded it into SAP · Já carreguei no SAP</a></p>'
                . '<p style="color:#44546D;font-size:13px">After loading, press the button and enter the SAP reference: the team is told in this same email thread to continue. · Depois de carregar, carregue no botão e indique a referência do SAP: a equipa é avisada neste mesmo assunto para continuar. (' . NAVAL_SAP_ENVIO_DIAS_LIGACAO . ' dias)</p>';
        }
    } else {
        $s .= '<p>There are no sales to send for this period. · Não há vendas por enviar neste período.</p>';
    }
    $lista = function (string $tit, array $x) use ($h) {
        if (!$x) return '';
        $o = '<p style="margin:14px 0 4px"><b>' . $h($tit) . '</b></p><ul style="margin:0;padding-left:20px">';
        foreach (array_slice($x, 0, 30) as $i) $o .= '<li>' . $h($i) . '</li>';
        if (count($x) > 30) $o .= '<li>… +' . (count($x) - 30) . '</li>';
        return $o . '</ul>';
    };
    if (!empty($est['por_sincronizar'])) {
        $s .= $lista('Routes not yet synchronised (their sales go in the next file) · Rotas ainda por sincronizar (as vendas seguem no próximo ficheiro)', array_map(function ($r) { return 'Rota ' . $r; }, $est['por_sincronizar']));
    }
    $s .= $lista('Left out, to be fixed in Pinguim BO · Ficaram de fora, por corrigir no Pinguim BO', (array) ($est['erros'] ?? []));
    $s .= $lista('Warnings (included) · Avisos (incluídos)', (array) ($est['avisos'] ?? []));
    $link = rtrim(naval_config('PORTAL_URL', ''), '/') . '/reports/#sap-exportar';
    return $s . '<p style="margin:18px 0 0;color:#44546D;font-size:13px">Portal RTM · <a href="' . $h($link) . '">' . $h($link) . '</a></p></div>';
}

/** Altera um lote dentro da exclusão mútua dos lotes. */
function naval_sap_lote_anotar(int $n, callable $f): array
{
    return naval_sap_exclusivo(function () use ($n, $f) {
        $lotes = naval_sap_lotes();
        $i = naval_sap_procurar($lotes, $n);
        $lotes[$i] = $f($lotes[$i]);
        naval_sap_gravar_lotes($lotes);
        return $lotes[$i];
    });
}

/** Lote de uma ligação «Já carreguei no SAP» válida (código certo, por usar, no prazo) ou null. */
function naval_sap_envio_lote_da_ligacao(int $n, string $codigo): ?array
{
    if ($n < 1 || !preg_match('/^[a-f0-9]{40}$/', $codigo)) return null;
    foreach (naval_sap_lotes() as $l) {
        if ((int) $l['numero'] !== $n) continue;
        $c = (array) ($l['confirmacao'] ?? []);
        if (empty($c['hash']) || !hash_equals((string) $c['hash'], hash('sha256', $codigo))) return null;
        if ((string) ($c['expira'] ?? '') < date('Y-m-d H:i:s')) return null;
        return $l;
    }
    return null;
}

/** Confirma o carregamento pela ligação do email: quem (nome escrito) e a referência do SAP. Inutiliza a ligação. */
function naval_sap_envio_confirmar_ligacao(int $n, string $codigo, string $nome, string $referencia, ?callable $enviar = null): array
{
    $l = naval_sap_envio_lote_da_ligacao($n, $codigo);
    if (!$l) {
        naval_registar('sap_ligacao_recusada', ['lote' => $n]);
        throw new NavalErro('This link is no longer valid. · Esta ligação já não é válida.', 403);
    }
    $nome = naval_texto($nome, 80);
    $referencia = naval_texto($referencia, 60);
    if (naval_comprimento($nome) < 2) throw new NavalErro('Enter your name. · Indique o seu nome.');
    if (naval_comprimento($referencia) < 2) throw new NavalErro('Enter the SAP reference. · Indique a referência do SAP.');
    // naval_sap_confirmar inutiliza a ligação
    $lote = naval_sap_confirmar($n, $referencia, ['login' => 'email', 'nome' => $nome . ' (email)']);
    naval_sap_envio_avisar_carregado($lote, $enviar);
    return $lote;
}

/** Aviso «carregado no SAP» no mesmo assunto do envio, a quem continua o trabalho (e a quem recebeu o ficheiro). */
function naval_sap_envio_avisar_carregado(array $lote, ?callable $enviar = null): ?array
{
    $env = naval_sap_envio_config();
    if (!$env['avisar']) return null;
    $enviar = $enviar ?? 'naval_enviar_email';
    $h = function ($t) { return htmlspecialchars((string) $t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); };
    $c = (array) ($lote['carregado'] ?? []);
    $assunto = 'RE: ' . (string) ($lote['envio']['assunto'] ?? ('RTM sales for SAP / Vendas RTM para o SAP · batch / lote ' . $lote['numero']));
    $quem = (string) ($c['por_nome'] ?? '');
    $quando = naval_data_pt(substr((string) ($c['em'] ?? ''), 0, 10)) . ' ' . substr((string) ($c['em'] ?? ''), 11, 5);
    $html = '<div style="font-family:Segoe UI,Arial,sans-serif;color:#0B1F3A;max-width:720px">'
        . '<p><b>Batch ' . $h($lote['numero']) . ' has been loaded into SAP</b> by ' . $h($quem) . ' on ' . $h($quando)
        . (($c['referencia'] ?? '') !== '' ? ' (SAP reference ' . $h($c['referencia']) . ')' : '') . '. You can go ahead with your part of the work.</p>'
        . '<p><b>O lote ' . $h($lote['numero']) . ' foi carregado no SAP</b> por ' . $h($quem) . ' em ' . $h($quando)
        . (($c['referencia'] ?? '') !== '' ? ' (referência SAP ' . $h($c['referencia']) . ')' : '') . '. Pode avançar com o seu trabalho.</p>'
        . '<p style="color:#44546D;font-size:13px">' . $h($lote['ficheiro']) . ' · ' . $h($lote['n_documentos']) . ' documentos · '
        . $h(number_format((float) $lote['total'], 2, ',', ' ')) . ' Kz</p></div>';
    $para = [];
    foreach (array_merge($env['avisar'], (array) ($lote['envio']['para'] ?? [])) as $e) $para[strtolower($e)] = ['email' => $e, 'nome' => ''];
    $r = $enviar(array_values($para), $assunto, $html, [], []);
    naval_registar(!empty($r['ok']) ? 'sap_aviso_carregado' : 'sap_aviso_carregado_falhou', ['lote' => $lote['numero'], 'para' => array_keys($para)] + (empty($r['ok']) ? ['erro' => $r['erro'] ?? ''] : []));
    return $r;
}

/**
 * Email de teste do envio automático (botão na página do SAP), antes de o ligar: o mesmo percurso de verdade (vendas de
 * hoje no Pinguim BO, Excel, Microsoft 365, domínios autorizados) para os endereços indicados, mas sem emitir lote, sem
 * gastar número e sem marcar documentos; o ficheiro diz TESTE_NAO_CARREGAR e o email não tem a ligação de confirmação.
 * Também envia a quem é avisado quando carregado, para confirmar esse endereço. $o (testes): vendas, origens, enviar.
 */
function naval_sap_envio_teste(array $envio, array $eu, array $o = []): array
{
    $env = naval_sap_envio_limpar(['activo' => false] + $envio);
    if (!$env['para']) throw new NavalErro('Indique pelo menos um endereço em «Enviar para» para o teste.');
    $enviar = $o['enviar'] ?? 'naval_enviar_email';
    $cfg = naval_sap_config();
    $dia = date('Y-m-d');
    $dados = array_key_exists('vendas', $o) ? $o['vendas'] : naval_sap_envio_vendas($dia, $dia);
    $est = ['dia' => $dia, 'de' => $dia, 'ate' => $dia, 'por_sincronizar' => [], 'erros' => [], 'avisos' => []];
    $lote = null;
    $anexos = [];
    if ($dados === null) {
        $est['falha'] = 'Sem ligação ao Pinguim BO neste momento (o email foi enviado na mesma, para testar o correio).';
    } else {
        $L = naval_sap_linhas($dados['vendas'], $dados['artigos'], $cfg, $o['origens'] ?? naval_origem_stock($cfg['armazens']), naval_sap_exportados(naval_sap_lotes()),
            (array) ($dados['promotores'] ?? []), $dia, $dia);
        $est['erros'] = array_column($L['erros'], 'texto');
        $est['avisos'] = $L['avisos'];
        if ($L['linhas']) {
            $xlsx = naval_sap_xlsx($L['linhas']);
            $total = 0.0;
            foreach ($L['docs'] as $v) $total += (float) ($v['total'] ?? 0);
            $lote = ['numero' => 'TESTE', 'ficheiro' => 'TESTE_NAO_CARREGAR_RTM_Sales_SAP_Upload_' . $dia . '.xlsx', 'n_documentos' => count($L['docs']), 'linhas' => count($L['linhas']),
                'total' => naval_sap_r2($total), 'sha256' => hash('sha256', $xlsx)];
            $anexos = [['nome' => $lote['ficheiro'], 'tipo' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'conteudo' => $xlsx]];
        }
    }
    $aviso = '<p style="background:#FFF4E5;border:1px solid #F0B429;padding:10px 12px;border-radius:8px"><b>TEST · TESTE</b> — email configuration test, do not load this file into SAP. · '
        . 'teste da configuração do email, não carregue este ficheiro no SAP.</p>';
    $assunto = '[TEST / TESTE] ' . naval_sap_envio_assunto($est, $lote ? ['numero' => 'TESTE'] : null);
    $mails = function (array $l) { return array_map(function ($e) { return ['email' => $e, 'nome' => '']; }, $l); };
    $r = ['para' => $enviar($mails($env['para']), $assunto, $aviso . naval_sap_envio_html($est, $lote), $anexos, [])];
    if ($env['avisar']) {
        $r['avisar'] = $enviar($mails($env['avisar']), 'RE: ' . $assunto, $aviso . '<p style="font-family:Segoe UI,Arial,sans-serif">This address receives the notice «loaded into SAP». · '
            . 'Este endereço recebe o aviso «carregado no SAP».</p>', [], []);
    }
    $modo = naval_email_config()['modo'];
    naval_registar('sap_envio_teste', ['por' => $eu['login'] ?? '', 'para' => $env['para'], 'avisar' => $env['avisar'], 'ok' => array_map(function ($x) { return !empty($x['ok']); }, $r)]);
    return ['para' => ['enderecos' => $env['para']] + $r['para'], 'avisar' => isset($r['avisar']) ? ['enderecos' => $env['avisar']] + $r['avisar'] : null,
        'ficheiro' => $lote ? ['documentos' => $lote['n_documentos'], 'linhas' => $lote['linhas'], 'total' => $lote['total']] : null, 'sem_ligacao' => $dados === null,
        'modo_ficheiro' => $modo === 'ficheiro'];
}
