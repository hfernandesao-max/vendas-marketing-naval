<?php
/**
 * Entrega do dinheiro pelo vendedor (app «Naval Operações»): o vendedor regista no telemóvel as notas e moedas que
 * entrega à tesouraria; a declaração chega logo ao portal, para a tesouraria contar e validar.
 *
 * Prática de tesouraria (declaração do portador + contagem independente às cegas + conferência com o sistema):
 *   - o vendedor declara por denominação (as mesmas notas da folha de caixa), com o vendido em dinheiro no Pinguim BO
 *     ao lado para conferir antes de entregar;
 *   - a tesouraria continua a contar às cegas na folha de caixa; só na conferência aparecem lado a lado o declarado,
 *     o contado e o recebido no Pinguim BO — a diferença entre o declarado e o contado aponta logo onde está o erro;
 *   - a declaração altera-se enquanto a tesouraria não registou a entrega dessa rota nem a folha saiu de «aberta»;
 *     cada alteração fica no histórico (quem, quando, valor).
 * Ficheiros: dados-privados/caixa-entregas/AAAA-MM-DD.json {rota: {notas, total, por, em, historico}}.
 */
defined('NAVAL_NUCLEO') || exit;
require_once __DIR__ . '/caixa.php';

function naval_cxe_ficheiro(string $dia): string
{
    if (!naval_data_valida($dia)) throw new NavalErro('Data inválida.', 400);
    return naval_pasta('caixa-entregas') . '/' . $dia . '.json';
}

/** Declarações do dia: {rota: {...}}; $rotas limita às rotas da pessoa (null = todas). */
function naval_cxe_ler(string $dia, ?array $rotas = null): array
{
    $d = naval_json_ler(naval_cxe_ficheiro($dia));
    if ($rotas === null) return $d;
    return array_intersect_key($d, array_flip(array_map('strval', $rotas)));
}

/** A tesouraria já registou a entrega desta rota na folha de caixa? */
function naval_cxe_registada(array $caixa, string $rota): bool
{
    foreach ((array) ($caixa['entregas'] ?? []) as $l) {
        if ((string) ($l['rota'] ?? '') === $rota && ($l['valor'] ?? null) !== null) return true;
    }
    return false;
}

/** Regista (ou corrige) a declaração do vendedor. Devolve a declaração gravada. */
function naval_cxe_declarar(string $dia, string $rota, $notas, string $login): array
{
    if ($dia > date('Y-m-d')) throw new NavalErro('Data inválida.', 400);
    if (!preg_match('/^\d{3,6}$/', $rota)) throw new NavalErro('Rota inválida.', 400);
    [$porNotas, $total] = naval_caixa_por_notas($notas);
    if ($total === null) throw new NavalErro('Indique as notas e moedas que vai entregar.', 400);
    if ($total <= 0) throw new NavalErro('O total entregue tem de ser maior do que zero.', 400);
    $caixa = naval_caixa_ler($dia);
    if (($caixa['estado'] ?? 'aberta') !== 'aberta') throw new NavalErro('A folha de caixa deste dia já foi fechada: fale com a tesouraria.', 409);
    if (naval_cxe_registada($caixa, $rota)) throw new NavalErro('A tesouraria já contou e registou a entrega desta rota.', 409);
    $f = naval_cxe_ficheiro($dia);
    $r = naval_exclusivo('caixa-entregas-' . $dia, function () use ($f, $rota, $porNotas, $total, $login) {
        $d = naval_json_ler($f);
        $agora = date('Y-m-d H:i:s');
        $antes = $d[$rota] ?? null;
        $hist = (array) ($antes['historico'] ?? []);
        $hist[] = ['em' => $agora, 'por' => $login, 'total' => $total, 'accao' => $antes ? 'corrigida' : 'declarada'];
        $d[$rota] = ['rota' => $rota, 'notas' => $porNotas, 'total' => $total, 'por' => $login, 'em' => $agora, 'historico' => array_slice($hist, -20)];
        naval_json_gravar($f, $d);
        return $d[$rota];
    });
    naval_registar('caixa_entrega_declarada', ['dia' => $dia, 'rota' => $rota, 'total' => $total]);
    // a tesouraria recebe logo no telemóvel: há dinheiro para contar
    try {
        naval_servico('notificacoes');
        $tx = naval_notif_txt('entrega', ['r' => $rota, 'v' => number_format($total, 0, ',', ' ')]);
        naval_notificar('entrega_dinheiro', $tx['pt'][0], $tx['pt'][1], ['chave' => 'entrega|' . $dia . '|' . $rota . '|' . $r['em'], 'url' => '../reports/#caixa-marketing', 'textos' => $tx]);
    } catch (Throwable $e) {
        error_log('[entrega de dinheiro] ' . $e->getMessage());
    }
    return $r;
}

/**
 * Conferência por rota (função pura, testada): declarado pelo vendedor × contado pela tesouraria × recebido em
 * dinheiro no Pinguim BO. Devolve {rota: {declarado, contado, pinguim, dif_declarado, dif_pinguim}}.
 */
function naval_cxe_conferencia(array $declaracoes, array $caixa, ?array $esperado): array
{
    $out = [];
    $rotas = array_unique(array_merge(array_keys($declaracoes), array_map(function ($l) { return (string) ($l['rota'] ?? ''); }, (array) ($caixa['entregas'] ?? []))));
    foreach (array_filter($rotas) as $r) {
        $contado = null;
        foreach ((array) ($caixa['entregas'] ?? []) as $l) {
            if ((string) ($l['rota'] ?? '') === (string) $r && ($l['valor'] ?? null) !== null) $contado = round(($contado ?? 0) + (float) $l['valor'], 2);
        }
        $dec = isset($declaracoes[$r]) ? round((float) $declaracoes[$r]['total'], 2) : null;
        $pin = $esperado !== null ? round((float) ($esperado[$r]['dinheiro'] ?? 0), 2) : null;
        $out[(string) $r] = ['declarado' => $dec, 'contado' => $contado, 'pinguim' => $pin,
            'dif_declarado' => $dec !== null && $contado !== null ? round($contado - $dec, 2) : null,
            'dif_pinguim' => $pin !== null && $contado !== null ? round($contado - $pin, 2) : null];
    }
    ksort($out);
    return $out;
}
