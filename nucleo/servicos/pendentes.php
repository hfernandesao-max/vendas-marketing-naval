<?php
/**
 * «À sua espera»: o que aguarda a acção de quem pede, como a caixa de tarefas das apps de referência (SAP Fiori
 * «My Inbox», Microsoft Approvals, ServiceNow). Só aparece o que esta pessoa pode fazer agora, tendo em conta o nível
 * de aprovação e a separação de funções (quem lança, conta ou já aprovou um passo não aprova o seguinte; ISO/IEC
 * 27001:2022 A.5.3). É só leitura: aprovar faz-se no portal, com a sessão em dois passos (A.8.5) e a assinatura
 * registada (nome, conta, data e hora).
 *
 *   - Folha de caixa (últimos 7 dias): validada → 1.º nível (se houver) → aprovação final; depósitos no banco por
 *     aprovar (controlo duplo: quem requisita não aprova).
 *   - Conciliação das carrinhas (hoje): contar (a partir do fecho), validar a própria contagem, aprovar as diferenças
 *     (coordenador) e aprovação final (responsável).
 *   - PO emitidas à espera de aprovação para a carga no cais (o coordenador; quem a criou ou emitiu não).
 *   - PO aprovadas à espera da conferência no cais (Fiel de Armazém), cargas conferidas à espera da validação do
 *     Back-Office e guias de remessa por tratar no Pinguim BO (modo manual, recusadas ou divergentes).
 *
 * Gestão por excepção com dono, prazo e escalamento (naval_pendentes_ciclo): às 07:30 dos dias de trabalho cada pessoa
 * recebe no telemóvel e por correio o que está à sua espera; ao longo do dia, o que está atrasado sobe a quem tem a
 * linha «pendentes-escalamento» (a Direcção), com o nome de quem o tem para fazer.
 */
defined('NAVAL_NUCLEO') || exit;

const NAVAL_PENDENTES_DIAS = 7;

/**
 * $pode: {caixa1, caixa2, contar, coordenar, fechar} (permissões G de quem pede; «dois» fixa o circuito da caixa nos testes). $carrinhas: as rotas de hoje
 * (naval_carr_vista) ou null para as ler. Devolve {itens: [...], nomes: {login: nome}, total}.
 */
function naval_pendentes(string $login, ?array $rotas, array $pode, int $agora, ?array $carrinhas = null): array
{
    naval_servico('calendario');
    $hoje = date('Y-m-d', $agora);
    $hm = date('H:i', $agora);
    $eu = function ($x) use ($login) { return $login !== '' && strcasecmp((string) $x, $login) === 0; };
    $itens = [];
    // ── folha de caixa ──
    if (!empty($pode['caixa1']) || !empty($pode['caixa2'])) {
        naval_servico('caixa');
        $dois = $pode['dois'] ?? naval_caixa_dois_niveis();
        for ($i = NAVAL_PENDENTES_DIAS - 1; $i >= 0; $i--) {
            $dia = date('Y-m-d', strtotime($hoje . ' 12:00') - $i * 86400);
            $c = naval_json_ler(naval_caixa_ficheiro($dia));
            if (!$c || ($c['estado'] ?? '') === 'aprovada') continue;
            $depPend = 0;
            foreach ((array) ($c['saidas'] ?? []) as $l) {
                if (($l['tipo'] ?? '') !== 'deposito_banco' || !empty($l['aprovado_por'])) continue;
                $depPend++;
                if ($eu($l['requisitante'] ?? '')) continue;   // quem requisita não aprova
                $itens[] = ['tipo' => 'deposito', 'dia' => $dia, 'valor' => round((float) ($l['valor'] ?? 0), 2), 'banco' => (string) ($l['banco'] ?? ''),
                    'lacre' => (string) ($l['lacre'] ?? ''), 'por' => (string) ($l['requisitante'] ?? ''), 'em' => (string) ($l['requisitado_em'] ?? ''),
                    'atraso' => $dia < $hoje, 'url' => '../reports/#caixa-marketing'];
            }
            $estado = (string) ($c['estado'] ?? '');
            if (!in_array($estado, ['fechada', 'verificada'], true)) continue;
            $nivel = $estado === 'fechada' && $dois ? 1 : 2;
            if (empty($pode[$nivel === 1 ? 'caixa1' : 'caixa2'])) continue;
            $impedidos = $nivel === 1 ? ['fechada_por', 'alterado_por'] : ['fechada_por', 'alterado_por', 'verificada_por'];
            if (naval_config('CAIXA_SEGREGACAO', '1') !== '0' && array_filter($impedidos, function ($k) use ($c, $eu) { return $eu($c[$k] ?? ''); })) continue;
            $v = $estado === 'verificada';   // último passo: validada (por quem lança) ou aprovada no 1.º nível
            $itens[] = ['tipo' => 'caixa', 'nivel' => $nivel, 'apos' => $estado, 'dia' => $dia, 'por' => (string) ($c[$v ? 'verificada_por' : 'fechada_por'] ?? ''),
                'em' => (string) ($c[$v ? 'verificada_em' : 'fechada_em'] ?? ''), 'depositos' => $depPend,
                'diferenca' => isset($c['diferenca']) ? round((float) $c['diferenca'], 2) : null, 'atraso' => $dia < $hoje, 'url' => '../reports/#caixa-marketing'];
        }
    }
    // ── conciliação das carrinhas (hoje) ──
    if (!empty($pode['contar']) || !empty($pode['coordenar']) || !empty($pode['fechar'])) {
        $cal = naval_cal_dia($hoje);
        if ($carrinhas === null) {
            naval_servico('carrinha');
            $carrinhas = naval_carr_vista($hoje, $rotas, false)['rotas'] ?? [];
        }
        $seg = naval_config('CARRINHA_SEGREGACAO', '1') !== '0';
        $fora = $cal['tipo'] !== 'folga' && $hm >= ($cal['fecho'][1] ?? '16:00');
        foreach ($carrinhas as $r) {
            $e = (string) ($r['resumo']['estado'] ?? '');
            $base = ['tipo' => 'carrinha', 'dia' => $hoje, 'rota' => (string) $r['rota'], 'promotor' => (string) ($r['promotor'] ?? ''),
                'diferencas' => (int) ($r['resumo']['diferencas'] ?? 0), 'atraso' => $fora, 'url' => '../reports/#conciliacao-carrinha'];
            $ja = function (array $campos) use ($r, $eu, $seg) { return $seg && array_filter($campos, function ($k) use ($r, $eu) { return $eu($r[$k] ?? ''); }); };
            if ($e === 'por_contar' && !empty($pode['contar']) && $cal['tipo'] !== 'folga' && $hm >= ($cal['fecho'][0] ?? '15:30')) {
                $itens[] = ['accao' => 'contar'] + $base;
            } elseif ($e === 'por_validar' && !empty($pode['contar']) && $eu($r['contado_por'] ?? '')) {
                $itens[] = ['accao' => 'validar', 'por' => (string) $r['contado_por'], 'em' => (string) ($r['contado_em'] ?? '')] + $base;
            } elseif ($e === 'por_aprovar' && !empty($pode['coordenar']) && !$ja(['contado_por', 'validado_por'])) {
                $itens[] = ['accao' => 'coordenar', 'por' => (string) ($r['validado_por'] ?? $r['contado_por'] ?? ''), 'em' => (string) ($r['validado_em'] ?? '')] + $base;
            } elseif ($e === 'por_fechar' && !empty($pode['fechar']) && !$ja(['contado_por', 'validado_por', 'coordenado_por'])) {
                $itens[] = ['accao' => 'fechar', 'por' => (string) ($r['coordenado_por'] ?? ''), 'em' => (string) ($r['coordenado_em'] ?? '')] + $base;
            }
        }
    }
    // ── PO por aprovar para a carga ──
    if (!empty($pode['po'])) {
        naval_servico('requisicoes');
        foreach ([(int) date('Y', $agora) - 1, (int) date('Y', $agora)] as $ano) {
            foreach (naval_req_ficheiros((string) $ano) as $f) {
                $r = naval_json_ler((string) $f);
                if (!$r || $r['estado'] !== 'emitida' || !empty($r['aprovada_em']) || !naval_req_precisa_aprovacao($r)) continue;
                if ($rotas !== null && !in_array((string) $r['rota'], $rotas, true)) continue;
                if ($eu($r['criado_por'] ?? '') || $eu($r['emitida_por'] ?? '')) continue;   // quem criou ou emitiu não aprova
                $itens[] = ['tipo' => 'po', 'dia' => (string) $r['data'], 'numero' => (string) $r['numero'], 'rota' => (string) $r['rota'],
                    'promotor' => (string) ($r['promotor'] ?? ''), 'artigos' => count((array) $r['linhas']),
                    'quantidade' => round(array_sum(array_column((array) $r['linhas'], 'quantidade')), 3), 'por' => (string) ($r['emitida_por'] ?? ''),
                    'em' => (string) ($r['emitida_em'] ?? ''), 'atraso' => (string) $r['data'] < $hoje, 'url' => '../reports/#requisicoes-carrinha'];
            }
        }
    }
    // ── PO aprovadas por conferir no cais (Fiel de Armazém) e cargas conferidas por validar (Back-Office) ──
    if (!empty($pode['conferir']) || !empty($pode['validar'])) {
        naval_servico('requisicoes');
        $cal = naval_cal_dia($hoje);
        $fimCarga = (string) ($cal['carga'][1] ?? '17:00');
        $horas = max(1, (int) naval_config('PENDENTES_VALIDAR_HORAS', '2'));
        foreach ([(int) date('Y', $agora) - 1, (int) date('Y', $agora)] as $ano) {
            foreach (naval_req_ficheiros((string) $ano) as $f) {
                $r = naval_json_ler((string) $f);
                if (!$r || ($rotas !== null && !in_array((string) $r['rota'], $rotas, true))) continue;
                $base = ['dia' => (string) $r['data'], 'numero' => (string) $r['numero'], 'rota' => (string) $r['rota'], 'promotor' => (string) ($r['promotor'] ?? ''),
                    'artigos' => count((array) $r['linhas']), 'quantidade' => round(array_sum(array_column((array) $r['linhas'], 'quantidade')), 3), 'url' => '../reports/#cargas-po'];
                if (!empty($pode['conferir']) && naval_req_pronta_carga($r) && !$eu($r['emitida_por'] ?? '')) {
                    $itens[] = ['tipo' => 'po_conferir', 'por' => (string) ($r['aprovada_por'] ?? $r['emitida_por'] ?? ''), 'em' => (string) ($r['aprovada_em'] ?? $r['emitida_em'] ?? ''),
                        'atraso' => (string) $r['data'] < $hoje || ((string) $r['data'] === $hoje && $hm >= $fimCarga)] + $base;
                } elseif (!empty($pode['validar']) && ($r['estado'] ?? '') === 'carregada' && ($r['fase'] ?? '') === 'conferida' && !$eu($r['confirmado_por'] ?? '')) {
                    $em = (string) ($r['confirmado_em'] ?? '');
                    $itens[] = ['tipo' => 'po_validar', 'por' => (string) ($r['confirmado_por'] ?? ''), 'em' => $em,
                        'atraso' => $em !== '' && strtotime($em) < $agora - $horas * 3600] + $base;
                }
            }
        }
    }
    // ── guias de remessa por tratar no Pinguim BO (Back-Office): à mão no modo manual, recusadas ou divergentes ──
    if (!empty($pode['validar'])) {
        naval_servico('ordens-pinguim');
        $horas = max(1, (int) naval_config('PENDENTES_VALIDAR_HORAS', '2'));
        foreach (naval_ord_listar($rotas, ['por_executar', 'recusada', 'divergente'], 30) as $o) {
            if ($o['estado'] === 'por_executar' && ($o['modo'] ?? '') !== 'manual') continue;   // o robô trata
            $em = (string) ($o['criada_em'] ?? '');
            $itens[] = ['tipo' => 'ordem', 'estado' => (string) $o['estado'], 'dia' => (string) $o['data'], 'numero' => (string) ($o['referencia'] ?? $o['id']),
                'rota' => (string) $o['rota'], 'promotor' => (string) ($o['promotor'] ?? ''), 'em' => $em,
                'atraso' => $o['estado'] !== 'por_executar' || ($em !== '' && strtotime($em) < $agora - $horas * 3600), 'url' => '../reports/#cargas-po'];
        }
    }
    // os atrasados primeiro, depois os mais antigos
    $ordem = function ($x) { return [(int) !$x['atraso'], $x['dia'], $x['tipo'], $x['rota'] ?? '', $x['lacre'] ?? '']; };
    usort($itens, function ($a, $b) use ($ordem) { return $ordem($a) <=> $ordem($b); });
    return ['itens' => $itens, 'nomes' => (object) naval_nomes_assinantes($itens), 'total' => count($itens)];
}

/** Os pendentes da conta com sessão (permissões e âmbito dela). */
function naval_pendentes_conta(string $login): array
{
    return naval_pendentes($login, naval_rotas(), [
        'caixa1' => naval_pode('caixa-aprovacao', 'G'),
        'caixa2' => naval_pode('caixa-aprovacao-final', 'G'),
        'contar' => naval_pode('conciliacao-carrinha', 'G'),
        'coordenar' => naval_pode('conciliacao-coordenacao', 'G'),
        'fechar' => naval_pode('conciliacao-fecho', 'G'),
        'po' => naval_pode('requisicoes-aprovacao', 'G'),
        'conferir' => naval_pode('requisicoes-carrinha', 'G') && naval_pode('cargas-po', 'V'),
        'validar' => naval_pode('validacao-carga', 'G'),
    ], time());
}

/** Permissões de uma conta qualquer (sem sessão: pela matriz e pelo perfil), para o resumo e o escalamento. */
function naval_pendentes_permissoes(array $u): array
{
    naval_servico('notificacoes');
    $g = function (string $rec, string $min = 'G') use ($u) { return NAVAL_ORDEM_NIVEIS[naval_notif_nivel($u, $rec)] >= NAVAL_ORDEM_NIVEIS[$min]; };
    return ['caixa1' => $g('caixa-aprovacao'), 'caixa2' => $g('caixa-aprovacao-final'), 'contar' => $g('conciliacao-carrinha'),
        'coordenar' => $g('conciliacao-coordenacao'), 'fechar' => $g('conciliacao-fecho'), 'po' => $g('requisicoes-aprovacao'),
        'conferir' => $g('requisicoes-carrinha') && $g('cargas-po', 'V'), 'validar' => $g('validacao-carga')];
}

/** Chave estável de um pendente (o mesmo trabalho visto por várias pessoas conta uma só vez no escalamento). */
function naval_pendente_chave(array $x): string
{
    return implode('|', [$x['tipo'], $x['dia'] ?? '', $x['rota'] ?? '', $x['numero'] ?? '', $x['accao'] ?? '', $x['nivel'] ?? '', $x['lacre'] ?? '']);
}

/** Descrição curta de um pendente, em português, para o correio e as notificações. */
function naval_pendente_texto(array $x): string
{
    $d = date('d-m-Y', (int) strtotime((string) ($x['dia'] ?? '')));
    switch ($x['tipo']) {
        case 'caixa': return 'Fecho de caixa de ' . $d . ': ' . ((int) ($x['nivel'] ?? 2) === 1 ? 'aprovar (1.º nível)' : 'aprovação final');
        case 'deposito': return 'Depósito no banco de ' . $d . ': aprovar (' . ($x['banco'] ?? '') . ', lacre ' . ($x['lacre'] ?? '') . ')';
        case 'po': return 'PO ' . $x['numero'] . ' (rota ' . $x['rota'] . '): aprovar para a carga';
        case 'po_conferir': return 'PO ' . $x['numero'] . ' (rota ' . $x['rota'] . '): conferir a carga no cais';
        case 'po_validar': return 'PO ' . $x['numero'] . ' (rota ' . $x['rota'] . '): validar a carga conferida (Back-Office)';
        case 'ordem':
            $e = ['por_executar' => 'gerar no Pinguim BO', 'recusada' => 'recusada, tratar', 'divergente' => 'diferente do pedido, corrigir'];
            return 'Guia da ' . $x['numero'] . ' (rota ' . $x['rota'] . '): ' . ($e[$x['estado']] ?? $x['estado']);
        default:
            $a = ['contar' => 'contar a carrinha', 'validar' => 'validar a contagem', 'coordenar' => 'aprovar as diferenças (coordenador)', 'fechar' => 'aprovação final da conciliação'];
            return 'Rota ' . ($x['rota'] ?? '') . ': ' . ($a[$x['accao'] ?? ''] ?? 'conciliação da carrinha') . ' (' . $d . ')';
    }
}

/**
 * Resumo diário e escalamento dos pendentes (corre no ciclo periódico; cada envio uma só vez):
 *   - PENDENTES_HORA_RESUMO (07:30), dias de trabalho: cada pessoa recebe o que está à sua espera (notificação e
 *     correio), os atrasados primeiro;
 *   - PENDENTES_HORAS_ESCALAR (10:00,16:30): o que está atrasado sobe a quem tem «pendentes-escalamento», com quem o
 *     tem para fazer. PENDENTES_RESUMO=0 desliga tudo; PENDENTES_EMAIL=0 só o correio.
 * $o (testes): contas, carrinhas, escalar_para, notificar(tipo, login|null, titulo, corpo, textos, chave), email(para, assunto, html).
 */
function naval_pendentes_ciclo(int $agora, array $o = []): array
{
    if (naval_config('PENDENTES_RESUMO', '1') === '0') return [];
    naval_servico('calendario');
    naval_servico('notificacoes');
    $hoje = date('Y-m-d', $agora);
    $hm = date('H:i', $agora);
    if ((naval_cal_dia($hoje)['tipo'] ?? '') === 'folga') return [];
    $hResumo = naval_config('PENDENTES_HORA_RESUMO', '07:30');
    $hEscalar = array_values(array_filter(array_map('trim', explode(',', naval_config('PENDENTES_HORAS_ESCALAR', '10:00,16:30')))));
    $marca = naval_pasta('pendentes') . '/ciclo.json';
    $m = naval_json_ler($marca);
    $fazer = [];
    if ($hm >= $hResumo && ($m['resumo'] ?? '') !== $hoje) $fazer[] = 'resumo';
    $ultimoEscalar = '';
    foreach ($hEscalar as $h) if ($hm >= $h) $ultimoEscalar = $h;
    if ($ultimoEscalar !== '' && ($m['escalar'] ?? '') !== $hoje . ' ' . $ultimoEscalar) $fazer[] = 'escalar';
    if (!$fazer) return [];
    if (in_array('resumo', $fazer, true)) $m['resumo'] = $hoje;
    if (in_array('escalar', $fazer, true)) $m['escalar'] = $hoje . ' ' . $ultimoEscalar;
    naval_json_gravar($marca, $m);

    $notificar = $o['notificar'] ?? function (string $tipo, ?string $login, string $tit, string $corpo, array $textos, string $chave) {
        naval_notificar($tipo, $tit, $corpo, ['so' => $login !== null ? [$login] : null, 'textos' => $textos, 'chave' => $chave, 'url' => '../gestao/#alertas']);
    };
    $email = $o['email'] ?? function (array $para, string $assunto, string $html) {
        naval_servico('email');
        if (naval_email_configurado() && naval_config('PENDENTES_EMAIL', '1') !== '0') naval_enviar_email($para, $assunto, $html);
    };
    $carrinhas = $o['carrinhas'] ?? null;
    if ($carrinhas === null) {
        try {
            naval_servico('carrinha');
            $carrinhas = naval_carr_vista($hoje, null, false)['rotas'] ?? [];
        } catch (Throwable $e) {
            $carrinhas = [];
        }
    }
    $porRota = naval_perfis_por_rota();
    $contas = $o['contas'] ?? naval_utilizadores();
    $todos = [];   // chave => [item, [nomes de quem o tem para fazer]]
    $out = ['resumos' => 0, 'escalados' => 0];
    foreach ($contas as $login => $u) {
        $u += ['login' => (string) $login];
        if (!naval_conta_valida($u)) continue;
        $rotas = in_array($u['perfil'] ?? '', $porRota, true) ? array_values(array_map('strval', (array) ($u['rotas'] ?? []))) : null;
        $carr = $rotas === null ? $carrinhas : array_values(array_filter($carrinhas, function ($r) use ($rotas) { return in_array((string) $r['rota'], $rotas, true); }));
        $p = naval_pendentes((string) $login, $rotas, naval_pendentes_permissoes($u), $agora, $carr);
        if (!$p['itens']) continue;
        foreach ($p['itens'] as $x) {
            $k = naval_pendente_chave($x);
            $todos[$k] = [$x, array_merge($todos[$k][1] ?? [], [(string) ($u['nome'] ?? $login)])];
        }
        if (!in_array('resumo', $fazer, true)) continue;
        $n = count($p['itens']);
        $atr = count(array_filter($p['itens'], function ($x) { return $x['atraso']; }));
        $tx = naval_notif_txt('pendentes', ['n' => $n, 'a' => $atr, 'p' => naval_pendente_texto($p['itens'][0])]);
        $notificar('pendentes', (string) $login, $tx['pt'][0], $tx['pt'][1], $tx, 'pendentes-' . $hoje . '-' . $login);
        if (naval_email_valido($u['email'] ?? null)) {
            $email([trim((string) $u['email'])], 'À sua espera hoje: ' . $n . ($atr ? ' (' . $atr . ' atrasado' . ($atr > 1 ? 's' : '') . ')' : ''),
                naval_pendentes_html('Bom dia, ' . htmlspecialchars((string) ($u['nome'] ?? $login)) . '. Isto está à sua espera no Portal RTM:',
                    array_map(function ($x) { return [$x, []]; }, $p['itens'])));
        }
        $out['resumos']++;
    }
    if (in_array('escalar', $fazer, true)) {
        $atrasados = array_values(array_filter($todos, function ($t) { return $t[0]['atraso']; }));
        if ($atrasados) {
            $tx = naval_notif_txt('escalados', ['n' => count($atrasados), 'p' => naval_pendente_texto($atrasados[0][0])]);
            $notificar('pendentes_escalados', null, $tx['pt'][0], $tx['pt'][1], $tx, 'escalados-' . $hoje . '-' . $ultimoEscalar);
            $dest = $o['escalar_para'] ?? naval_notif_destinatarios('pendentes_escalados');
            $para = array_values(array_filter(array_map(function ($u) { return naval_email_valido($u['email'] ?? null) ? trim((string) $u['email']) : ''; }, $dest)));
            if ($para) $email($para, 'Pendentes atrasados: ' . count($atrasados), naval_pendentes_html('Estes pendentes estão atrasados (com quem os tem para fazer):', $atrasados));
            $out['escalados'] = count($atrasados);
        }
    }
    return $out;
}

/** Correio dos pendentes: lista simples, os atrasados primeiro, com quem os tem para fazer (no escalamento). */
function naval_pendentes_html(string $intro, array $lista): string
{
    $li = '';
    foreach ($lista as [$x, $quem]) {
        $li .= '<li style="margin:4px 0">' . ($x['atraso'] ? '<b style="color:#c0392b">Atrasado</b> · ' : '') . htmlspecialchars(naval_pendente_texto($x))
            . ($quem ? ' <span style="color:#666">— ' . htmlspecialchars(implode(', ', array_unique($quem))) . '</span>' : '') . '</li>';
    }
    return '<div style="font-family:Segoe UI,Arial,sans-serif;font-size:14px"><p>' . $intro . '</p><ul>' . $li . '</ul>'
        . '<p style="color:#666">Aprova-se no portal, com a verificação em dois passos e a assinatura registada.</p></div>';
}
