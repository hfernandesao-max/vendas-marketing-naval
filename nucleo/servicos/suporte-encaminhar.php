<?php
/**
 * Encaminhamento dos pedidos de suporte para a equipa de TI (caixa ITSupport, que alimenta a ferramenta de pedidos
 * ManageEngine ServiceDesk Plus) e confirmação a quem abriu o pedido.
 *
 *  - Assunto normalizado: «[Portal RTM] [SUP-000123] P2 · Incidente · Smartphone RTM · título». A resposta ao correio
 *    vai para quem abriu o pedido (Reply-To).
 *  - Corpo legível por pessoas (classificação, prazos, descrição, contexto e ligação ao pedido no portal) e, com o
 *    «E-mail Command» do ServiceDesk Plus ligado, um bloco $Campo=valor$ que a ferramenta lê para preencher o pedido
 *    (operação, requerente, tipo, categoria, prioridade, impacto, urgência, grupo). Os nomes têm de ser os da ferramenta:
 *    configuram-se em Administração › Suporte › Encaminhamento.
 *  - Pedidos com dados pessoais: o correio não leva a descrição nem a captura (ficam só no portal).
 *  - Se o envio falhar, fica «por enviar» e a rotina periódica tenta de novo (até 10 vezes, com intervalos crescentes).
 */
defined('NAVAL_NUCLEO') || exit;
require_once __DIR__ . '/suporte.php';
require_once __DIR__ . '/email.php';

const NAVAL_SUP_ENC_TENTATIVAS = 10;

/** Configuração por omissão (os nomes do ServiceDesk Plus confirmam-se com a equipa de TI). */
function naval_sup_enc_omissao(): array
{
    return [
        'activo' => true,
        'destinatario' => 'ITSupport@grupo-naval.com',
        'identificador' => '[Portal RTM]',
        'confirmar_requerente' => true,
        'comando' => [
            'activo' => false,
            'delimitador' => '$',
            'requerente' => true,
            'tipos' => ['pedido' => 'Service Request', 'incidente' => 'Incident', 'seguranca' => 'Incident', 'melhoria' => 'Service Request', 'nao_conformidade' => 'Incident'],
            'prioridades' => ['P1' => 'High', 'P2' => 'High', 'P3' => 'Medium', 'P4' => 'Low'],
            'impactos' => ['alto' => 'High', 'medio' => 'Medium', 'baixo' => 'Low'],
            'urgencias' => ['alta' => 'High', 'media' => 'Normal', 'baixa' => 'Low'],
            'categorias' => NAVAL_SUP_SERVICOS,
            'grupo' => '',
        ],
    ];
}

function naval_sup_enc_ficheiro(): string
{
    return naval_pasta('suporte') . '/encaminhamento.json';
}

/** Configuração em vigor: a gravada sobre a de omissão (chaves desconhecidas ignoradas). */
function naval_sup_enc_config(): array
{
    $o = naval_sup_enc_omissao();
    $g = naval_json_ler(naval_sup_enc_ficheiro());
    foreach (['activo', 'destinatario', 'identificador', 'confirmar_requerente'] as $k) if (array_key_exists($k, $g)) $o[$k] = $g[$k];
    foreach (['activo', 'delimitador', 'requerente', 'grupo'] as $k) if (array_key_exists($k, (array) ($g['comando'] ?? []))) $o['comando'][$k] = $g['comando'][$k];
    foreach (['tipos', 'prioridades', 'impactos', 'urgencias', 'categorias'] as $k) {
        foreach ((array) ($g['comando'][$k] ?? []) as $c => $v) if (array_key_exists($c, $o['comando'][$k])) $o['comando'][$k][$c] = (string) $v;
    }
    return $o;
}

/** Grava a configuração (quem gere o suporte), validada; fica no registo de actividade. */
function naval_sup_enc_gravar(array $d, string $login): array
{
    $o = naval_sup_enc_omissao();
    $dest = trim((string) ($d['destinatario'] ?? ''));
    if (!naval_email_valido($dest)) throw new NavalErro('Indique o endereço de correio electrónico da equipa de TI.', 400, ['campo' => 'destinatario']);
    $ident = trim((string) ($d['identificador'] ?? ''));
    if ($ident === '' || mb_strlen($ident) > 40 || preg_match('/[\r\n]/', $ident)) throw new NavalErro('O identificador do assunto tem de 1 a 40 caracteres.', 400, ['campo' => 'identificador']);
    $cmd = (array) ($d['comando'] ?? []);
    $delim = (string) ($cmd['delimitador'] ?? '$');
    if (!preg_match('/^[$@#%&~^|]{1,2}$/', $delim)) throw new NavalErro('O delimitador tem de ser um ou dois destes caracteres: $ @ # % & ~ ^ |', 400, ['campo' => 'delimitador']);
    $limpo = function ($v) { return mb_substr(trim((string) preg_replace('/[\r\n$@#%&~^|=]+/', ' ', (string) $v)), 0, 80); };
    $g = ['activo' => !empty($d['activo']), 'destinatario' => $dest, 'identificador' => $ident, 'confirmar_requerente' => !empty($d['confirmar_requerente']),
        'comando' => ['activo' => !empty($cmd['activo']), 'delimitador' => $delim, 'requerente' => !empty($cmd['requerente']), 'grupo' => $limpo($cmd['grupo'] ?? '')]];
    foreach (['tipos', 'prioridades', 'impactos', 'urgencias', 'categorias'] as $k) {
        foreach ($o['comando'][$k] as $c => $omissao) {
            $v = $limpo($cmd[$k][$c] ?? $omissao);
            $g['comando'][$k][$c] = $v === '' ? $omissao : $v;
        }
    }
    $g['alterado_por'] = $login;
    $g['alterado_em'] = date('Y-m-d H:i:s');
    naval_exclusivo('suporte-enc', function () use ($g) { naval_json_gravar(naval_sup_enc_ficheiro(), $g); });
    naval_registar('suporte_encaminhamento', ['activo' => $g['activo'], 'destinatario' => $dest, 'comando' => $g['comando']['activo']]);
    return naval_sup_enc_config();
}

/** Assunto normalizado do correio. */
function naval_sup_enc_assunto(array $p, array $cfg): string
{
    $s = $cfg['identificador'] . ' [' . $p['id'] . '] ' . $p['prioridade'] . ' · ' . NAVAL_SUP_TIPOS[$p['tipo']] . ' · '
        . (NAVAL_SUP_SERVICOS[$p['servico'] ?? 'outro'] ?? 'Outro') . ' · ' . $p['titulo'];
    return mb_substr(trim((string) preg_replace('/[\r\n]+/', ' ', $s)), 0, 250);
}

/** Bloco do «E-mail Command» do ServiceDesk Plus ($Campo=valor$, um por linha), ou '' se desligado. */
function naval_sup_enc_comando(array $p, array $cfg): string
{
    $c = $cfg['comando'];
    if (empty($c['activo'])) return '';
    $d = (string) $c['delimitador'];
    $v = function ($x) use ($d) { return trim((string) preg_replace('/[\r\n]+/', ' ', str_replace($d, '', (string) $x))); };
    $l = ['Operation' => 'AddRequest'];
    if (!empty($c['requerente']) && ($p['criado_email'] ?? '') !== '') $l['Requester'] = $p['criado_email'];
    $l['RequestType'] = $c['tipos'][$p['tipo']] ?? '';
    $l['Category'] = $c['categorias'][$p['servico'] ?? 'outro'] ?? '';
    $l['Priority'] = $c['prioridades'][$p['prioridade']] ?? '';
    if (($p['impacto'] ?? '') !== '') $l['Impact'] = $c['impactos'][$p['impacto']] ?? '';
    if (($p['urgencia'] ?? '') !== '') $l['Urgency'] = $c['urgencias'][$p['urgencia']] ?? '';
    if ($c['grupo'] !== '') $l['Group'] = $c['grupo'];
    $out = [];
    foreach ($l as $k => $x) if ($v($x) !== '') $out[] = $d . $k . '=' . $v($x) . $d;
    return implode("\n", $out);
}

/** Corpo HTML do correio para a equipa de TI (texto escapado; sem dados pessoais quando o pedido os envolve). */
function naval_sup_enc_html(array $p, array $cfg): string
{
    $h = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); };
    $dh = function ($s) { return $s ? date('d-m-Y H:i', (int) strtotime((string) $s)) : '—'; };
    $ctx = (array) ($p['contexto'] ?? []);
    $lig = rtrim(naval_config('PORTAL_URL', 'https://geo.naval.co.ao:2051'), '/') . '/reports/#suporte?id=' . rawurlencode($p['id']);
    $linhas = [
        'Pedido' => $p['id'],
        'Tipo' => NAVAL_SUP_TIPOS[$p['tipo']],
        'Serviço afectado' => NAVAL_SUP_SERVICOS[$p['servico'] ?? 'outro'] ?? 'Outro',
        'Impacto' => NAVAL_SUP_IMPACTOS[$p['impacto'] ?? ''] ?? '—',
        'Urgência' => NAVAL_SUP_URGENCIAS[$p['urgencia'] ?? ''] ?? '—',
        'Prioridade' => $p['prioridade'] . ' (matriz impacto × urgência)',
        'Primeira resposta até' => $dh($p['prazo_resposta'] ?? null),
        'Resolução até' => $dh($p['prazo']),
        'Aberto por' => $p['criado_nome'] . ' (' . $p['criado_por'] . (($p['criado_perfil'] ?? '') !== '' ? ', ' . $p['criado_perfil'] : '') . ')' . (($p['criado_email'] ?? '') !== '' ? ' · ' . $p['criado_email'] : ''),
        'Aberto em' => $dh($p['criado_em']),
        'Página' => $ctx['pagina'] ?? '',
        'Versão do portal' => trim(($ctx['versao'] ?? '') . (($ctx['revisao'] ?? '') !== '' ? ' · revisão ' . $ctx['revisao'] : '')),
        'Navegador' => $ctx['navegador'] ?? '',
        'Ecrã e idioma' => trim(($ctx['ecra'] ?? '') . (($ctx['idioma'] ?? '') !== '' ? ' · ' . $ctx['idioma'] : '')),
    ];
    $t = '';
    foreach ($linhas as $k => $v) {
        if ($v === '' || $v === null) continue;
        $t .= '<tr><th style="text-align:left;padding:4px 12px 4px 0;color:#44546d;font-weight:600;vertical-align:top">' . $h($k) . '</th><td style="padding:4px 0">' . $h($v) . '</td></tr>';
    }
    $desc = !empty($p['dados_pessoais'])
        ? '<p style="background:#fff4e0;padding:10px 12px;border-radius:8px"><b>Envolve dados pessoais:</b> a descrição e a captura ficam só no portal. Avaliar a comunicação à Agência de Protecção de Dados (Lei n.º 22/11) e aos titulares.</p>'
        : '<h3 style="margin:18px 0 6px;color:#273777">Descrição</h3><p style="white-space:pre-wrap;text-align:justify">' . ($p['descricao'] !== '' ? $h($p['descricao']) : '—') . '</p>';
    $cmd = naval_sup_enc_comando($p, $cfg);
    return '<div style="font:14px/1.5 Segoe UI,Arial,sans-serif;color:#0b1f3a">'
        . '<h2 style="margin:0 0 10px;color:#273777">' . $h($p['id'] . ' · ' . $p['titulo']) . '</h2>'
        . '<table style="border-collapse:collapse">' . $t . '</table>' . $desc
        . '<p><a href="' . $h($lig) . '" style="color:#0057b8;font-weight:600">Abrir o pedido no Portal RTM</a></p>'
        . ($cmd !== '' ? '<pre style="font:12px/1.4 Consolas,monospace;color:#44546d;border-top:1px solid #d5ddeb;padding-top:8px">' . $h($cmd) . '</pre>' : '')
        . '<p style="color:#44546d;font-size:12px">Correio automático do Portal RTM (gestão de pedidos ISO/IEC 20000-1). Responda a este correio para falar com quem abriu o pedido.</p></div>';
}

/** Confirmação a quem abriu o pedido: número, classificação e prazos. */
function naval_sup_enc_confirmacao_html(array $p): string
{
    $h = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); };
    $dh = function ($s) { return $s ? date('d-m-Y H:i', (int) strtotime((string) $s)) : '—'; };
    $lig = rtrim(naval_config('PORTAL_URL', 'https://geo.naval.co.ao:2051'), '/') . '/reports/#suporte?id=' . rawurlencode($p['id']);
    $hora = (int) date('G', (int) strtotime((string) $p['criado_em']));
    $saud = $hora < 13 ? 'Bom dia,' : ($hora < 20 ? 'Boa tarde,' : 'Boa noite,');
    return '<div style="font:14px/1.5 Segoe UI,Arial,sans-serif;color:#0b1f3a"><p>' . $saud . '</p>'
        . '<p style="text-align:justify">' . $h($p['criado_nome']) . ', o seu pedido <b>' . $h($p['id']) . '</b> («' . $h($p['titulo']) . '») foi registado e enviado à equipa de TI, '
        . 'com a prioridade <b>' . $h($p['prioridade']) . '</b>: primeira resposta até ' . $h($dh($p['prazo_resposta'] ?? null)) . ' e resolução até ' . $h($dh($p['prazo'])) . '.</p>'
        . '<p><a href="' . $h($lig) . '" style="color:#0057b8;font-weight:600">Acompanhar o pedido no Portal RTM</a></p>'
        . '<p style="color:#44546d;font-size:12px">Correio automático do Portal RTM.</p></div>';
}

/**
 * Envia o pedido à equipa de TI (e a confirmação a quem o abriu, na primeira vez) e grava o resultado no pedido.
 * Devolve o pedido actualizado, ou null se não existir. Nunca lança excepções.
 */
function naval_sup_encaminhar(string $id, ?int $agora = null): ?array
{
    $agora = $agora ?? time();
    try {
        $cfg = naval_sup_enc_config();
        $todos = naval_sup_todos();
        if (!isset($todos[$id])) return null;
        $p = $todos[$id];
        $enc = (array) ($p['encaminhamento'] ?? []) + ['estado' => '', 'tentativas' => 0, 'confirmado' => false];
        if ($enc['estado'] === 'enviado') return $p;
        if (empty($cfg['activo'])) {
            $enc = ['estado' => 'desligado', 'tentativas' => 0, 'confirmado' => (bool) $enc['confirmado'], 'para' => '', 'em' => date('Y-m-d H:i:s', $agora), 'erro' => ''];
        } else {
            $anexos = [];
            if (empty($p['dados_pessoais'])) {
                foreach ((array) ($p['anexos'] ?? []) as $a) {
                    $f = naval_sup_pasta_anexos() . '/' . basename((string) $a['ficheiro']);
                    if (is_file($f)) $anexos[] = ['nome' => $a['nome'], 'tipo' => $a['tipo'], 'conteudo' => (string) file_get_contents($f)];
                }
            }
            $r = naval_enviar_email([['email' => $cfg['destinatario'], 'nome' => 'Suporte de TI']], naval_sup_enc_assunto($p, $cfg), naval_sup_enc_html($p, $cfg), $anexos,
                ($p['criado_email'] ?? '') !== '' ? ['responder_a' => ['email' => $p['criado_email'], 'nome' => $p['criado_nome']]] : []);
            $enc = ['estado' => $r['ok'] ? 'enviado' : 'por_enviar', 'tentativas' => (int) $enc['tentativas'] + 1, 'confirmado' => (bool) $enc['confirmado'],
                'para' => $cfg['destinatario'], 'em' => date('Y-m-d H:i:s', $agora), 'erro' => $r['ok'] ? '' : mb_substr((string) $r['erro'], 0, 300)];
            if (!$r['ok'] && $enc['tentativas'] >= NAVAL_SUP_ENC_TENTATIVAS) $enc['estado'] = 'falhou';
            naval_registar($r['ok'] ? 'suporte_encaminhado' : 'suporte_encaminhamento_falhou', ['id' => $id, 'para' => $cfg['destinatario']] + ($r['ok'] ? [] : ['erro' => $enc['erro'], 'tentativa' => $enc['tentativas']]), 'portal');
            if ($r['ok'] && !$enc['confirmado'] && !empty($cfg['confirmar_requerente']) && ($p['criado_email'] ?? '') !== '') {
                $c = naval_enviar_email([['email' => $p['criado_email'], 'nome' => $p['criado_nome']]], 'Pedido ' . $p['id'] . ' registado · ' . $p['titulo'], naval_sup_enc_confirmacao_html($p));
                $enc['confirmado'] = $c['ok'];
            }
        }
        return naval_exclusivo('suporte', function () use ($id, $enc) {
            $todos = naval_sup_todos();
            if (!isset($todos[$id])) return null;
            $todos[$id]['encaminhamento'] = $enc;
            naval_json_gravar(naval_sup_ficheiro(), $todos);
            return $todos[$id];
        });
    } catch (Throwable $e) {
        error_log('[suporte] encaminhamento ' . $id . ': ' . $e->getMessage());
        return null;
    }
}

/** Rotina periódica: volta a enviar os pedidos por enviar (intervalo de 5 min × 2^(tentativas−1), até 10 tentativas). */
function naval_sup_encaminhar_pendentes(int $agora): int
{
    $n = 0;
    foreach (naval_sup_todos() as $id => $p) {
        $e = (array) ($p['encaminhamento'] ?? []);
        if (($e['estado'] ?? '') !== 'por_enviar') continue;
        $espera = 300 * (2 ** max(0, (int) ($e['tentativas'] ?? 1) - 1));
        if (strtotime((string) ($e['em'] ?? '')) + $espera > $agora) continue;
        naval_sup_encaminhar((string) $id, $agora);
        $n++;
    }
    return $n;
}
