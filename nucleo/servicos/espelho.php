<?php
/**
 * Espelho da apresentação: com o modo apresentação activo, a app de gestão mostrada no ecrã do computador e a app no
 * telemóvel da mesma pessoa mostram o mesmo ecrã ao mesmo tempo (secção, período, dia, rota, documento ou cliente
 * aberto, posição na página, conversa do assistente e idioma). Quem mexe — no computador ou no telemóvel — conduz; o outro segue.
 *
 * Só o estado do ecrã passa pelo servidor (nunca os dados: cada lado lê os seus com a própria sessão e o próprio
 * âmbito); um ficheiro por conta, só enquanto o modo apresentação estiver activo; nada mais é guardado.
 */
defined('NAVAL_NUCLEO') || exit;

const NAVAL_ESPELHO_MAX = 65536;

function naval_espelho_ficheiro(string $login): string
{
    return naval_pasta('espelho') . '/' . hash('sha256', strtolower(trim($login))) . '.json';
}

/** Estado do espelho da conta: {ate, seq, origem, estado, em}. */
function naval_espelho_ler(string $login): array
{
    return naval_json_ler(naval_espelho_ficheiro($login), ['ate' => 0, 'seq' => 0, 'origem' => '', 'estado' => null]);
}

/** Começo (ate > 0) ou fim (ate = 0) do modo apresentação da conta: o espelho recomeça vazio. */
function naval_espelho_activar(string $login, int $ate): void
{
    if ($login === '') return;
    naval_exclusivo('espelho', function () use ($login, $ate) {
        $d = naval_espelho_ler($login);
        naval_json_gravar(naval_espelho_ficheiro($login), ['ate' => $ate, 'seq' => (int) $d['seq'] + 1, 'origem' => '', 'estado' => null, 'em' => time()], false);
    });
}

/** Valida o estado do ecrã (só as chaves conhecidas; nunca dados de negócio além da conversa do assistente). */
function naval_espelho_estado($e): array
{
    if (!is_array($e)) throw new NavalErro('Estado inválido.', 400);
    $id = function ($v) {
        $v = (string) $v;
        return preg_match('/^[a-z_]{1,20}$/', $v) ? $v : null;
    };
    $out = [
        'sep' => $id($e['sep'] ?? ''),
        'periodo' => $id($e['periodo'] ?? ''),
        'ordem' => $id($e['ordem'] ?? ''),
        // folha aberta: rota («1004»), documento («d:FR A/123|2026-11-14») ou cliente («c:C0042»)
        'folha' => isset($e['folha']) && preg_match('/^[\p{L}\p{N} _.\/|:#-]{1,80}$/u', (string) $e['folha']) ? (string) $e['folha'] : null,
        'pv' => $id($e['pv'] ?? ''),
        'pa' => $id($e['pa'] ?? ''),
        'dia' => isset($e['dia']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $e['dia']) ? (string) $e['dia'] : null,
        'rolar' => isset($e['rolar']) && is_numeric($e['rolar']) ? max(0.0, min(1.0, round((float) $e['rolar'], 3))) : 0.0,
        'idioma' => in_array($e['idioma'] ?? '', ['pt', 'en', 'ti'], true) ? $e['idioma'] : 'pt',
        'pensar' => !empty($e['pensar']),
        'menu' => !empty($e['menu']),
        'conversa' => [],
    ];
    foreach (array_slice(is_array($e['conversa'] ?? null) ? $e['conversa'] : [], -12) as $m) {
        if (!is_array($m)) continue;
        $out['conversa'][] = [
            'papel' => ($m['papel'] ?? '') === 'assistente' ? 'assistente' : 'pessoa',
            'texto' => mb_substr((string) ($m['texto'] ?? ''), 0, 4000),
            'erro' => !empty($m['erro']),
        ];
    }
    if (strlen((string) json_encode($out)) > NAVAL_ESPELHO_MAX) throw new NavalErro('Estado demasiado grande.', 413);
    return $out;
}

/** Grava o ecrã de quem está a conduzir; devolve o número de sequência. */
function naval_espelho_gravar(string $login, string $cliente, $estado): int
{
    if (!preg_match('/^[a-z0-9]{8,32}$/', $cliente)) throw new NavalErro('Cliente inválido.', 400);
    $e = naval_espelho_estado($estado);
    return (int) naval_exclusivo('espelho', function () use ($login, $cliente, $e) {
        $d = naval_espelho_ler($login);
        if ((int) $d['ate'] <= time()) throw new NavalErro('O modo apresentação não está activo.', 409);
        $seq = (int) $d['seq'] + 1;
        naval_json_gravar(naval_espelho_ficheiro($login), ['ate' => (int) $d['ate'], 'seq' => $seq, 'origem' => $cliente, 'estado' => $e, 'em' => time()], false);
        return $seq;
    });
}
