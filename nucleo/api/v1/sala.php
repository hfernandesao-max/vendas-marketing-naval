<?php
/**
 * Painel da sala comercial (ecrã de TV): indicadores do dia por rota, sem conta nem palavra-passe, por uma ligação
 * própria (código aleatório de 256 bits) que o super administrador ou quem gere o painel cria e revoga no portal.
 *
 * GET v1/sala?t=<código>        indicadores agregados (sem clientes, NIF nem documentos)
 * GET v1/sala/ligacoes          ligações criadas (sem o código) — recurso «painel-sala» (G)
 * POST v1/sala/ligacoes {accao: criar (nome) | revogar (id), csrf}   o código só se mostra uma vez, ao criar
 *
 * O código guarda-se só como SHA-256 (como uma palavra-passe); cada acesso fica registado na ligação (última hora e IP).
 */
defined('NAVAL_API') || exit;
naval_servico('analise');
naval_servico('previsao');

function naval_sala_ficheiro(): string
{
    return naval_pasta('sala') . '/ligacoes.json';
}

if (($_GET['gerir'] ?? '') === '1') {
    $GLOBALS['naval_api_sensivel'] = true;
    if (!naval_pode('painel-sala', 'G')) throw new NavalErro('Sem permissão para gerir o painel da sala comercial.', 403);
    $lig = naval_json_ler(naval_sala_ficheiro());
    $vista = function (array $l) {
        return array_values(array_map(function ($id, $x) { unset($x['hash']); return ['id' => $id] + $x; }, array_keys($l), $l));
    };
    if (!naval_e_post()) naval_json(['ok' => true, 'ligacoes' => $vista($lig)]);
    $p = naval_pedido();
    naval_exigir_csrf($p);
    $eu = (array) naval_utilizador();
    $r = naval_exclusivo('sala-ligacoes', function () use ($p, $eu) {
        $lig = naval_json_ler(naval_sala_ficheiro());
        $accao = (string) ($p['accao'] ?? '');
        if ($accao === 'criar') {
            $nome = naval_texto($p['nome'] ?? '', 60);
            if ($nome === '') throw new NavalErro('Indique onde fica o ecrã (ex.: «Sala comercial · Viana»).', 400);
            if (count(array_filter($lig, function ($x) { return empty($x['revogado']); })) >= 20) throw new NavalErro('Demasiadas ligações activas: revogue as que já não usa.', 400);
            $codigo = bin2hex(random_bytes(32));
            $id = bin2hex(random_bytes(6));
            $lig[$id] = ['nome' => $nome, 'hash' => hash('sha256', $codigo), 'criado' => date('Y-m-d H:i:s'), 'criado_por' => $eu['login'], 'revogado' => null, 'ultimo_acesso' => null];
            naval_json_gravar(naval_sala_ficheiro(), $lig);
            naval_registar('sala_ligacao_criada', ['id' => $id, 'nome' => $nome]);
            return ['id' => $id, 'codigo' => $codigo];
        }
        if ($accao === 'revogar') {
            $id = (string) ($p['id'] ?? '');
            if (!isset($lig[$id])) throw new NavalErro('Ligação desconhecida.', 404);
            $lig[$id]['revogado'] = date('Y-m-d H:i:s');
            $lig[$id]['revogado_por'] = $eu['login'];
            naval_json_gravar(naval_sala_ficheiro(), $lig);
            naval_registar('sala_ligacao_revogada', ['id' => $id]);
            return ['id' => $id];
        }
        throw new NavalErro('Acção desconhecida.', 400);
    });
    naval_json(['ok' => true] + $r + ['ligacoes' => $vista(naval_json_ler(naval_sala_ficheiro()))]);
}

// ── Ecrã da sala: só com uma ligação válida ──
$GLOBALS['naval_api_sensivel'] = true;   // o código vai no endereço: a resposta nunca fica em caches intermédias
$codigo = (string) ($_GET['t'] ?? '');
$hash = preg_match('/^[a-f0-9]{64}$/', $codigo) ? hash('sha256', $codigo) : '';
$id = null;
foreach (naval_json_ler(naval_sala_ficheiro()) as $k => $x) {
    if ($hash !== '' && empty($x['revogado']) && hash_equals((string) $x['hash'], $hash)) $id = $k;
}
if ($id === null) {
    naval_registar('sala_acesso_recusado', [], 'painel-sala');
    throw new NavalErro('Ligação do painel inválida ou revogada.', 403);
}
// último acesso (no máximo um registo por minuto)
naval_exclusivo('sala-ligacoes', function () use ($id) {
    $lig = naval_json_ler(naval_sala_ficheiro());
    if (!isset($lig[$id]) || (string) ($lig[$id]['ultimo_acesso'] ?? '') > date('Y-m-d H:i:s', time() - 60)) return;
    $lig[$id]['ultimo_acesso'] = date('Y-m-d H:i:s');
    $lig[$id]['ip'] = naval_ip_cliente();
    naval_json_gravar(naval_sala_ficheiro(), $lig);
});
$r = naval_cache('sala|' . date('Y-m-d H') . floor((int) date('i') / 2), 120, function () {
    return naval_indicadores(time(), null);
});
// só totais por rota e o nome do promotor (sem clientes, documentos nem NIF)
naval_json(['ok' => true, 'hora' => $r['hora'], 'hoje' => $r['hoje'], 'ontem' => $r['ontem'], 'semana' => $r['semana'], 'mes' => $r['mes'],
    'rotas' => array_map(function ($x) {
        return array_intersect_key($x, array_flip(['rota', 'promotor', 'hoje', 'previsto_hoje', 'ontem', 'habitual_ontem', 'mes', 'mes_anterior', 'variacao_mes', 'sequencia']));
    }, $r['rotas'])]);
