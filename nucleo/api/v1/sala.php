<?php
/**
 * Painel da sala comercial (ecrã de TV): indicadores do dia por rota, sem conta nem palavra-passe, por uma ligação
 * própria (código aleatório de 256 bits) que o super administrador ou quem gere o painel cria e revoga no portal.
 *
 * GET v1/sala (cabeçalho X-Painel-Codigo) indicadores agregados (sem clientes, NIF nem documentos); o código vai no
 *                               fragmento do endereço da página (#t=…), que não chega aos registos do Apache nem a intermediários
 * GET v1/sala/ligacoes          ligações criadas (sem o código) — recurso «painel-sala» (G)
 * POST v1/sala/ligacoes {accao: criar (nome) | revogar (id) | configurar (id, ecras, segundos), csrf}   o código só se mostra
 *                               uma vez, ao criar; «configurar» escolhe os ecrãs, a ordem (arrastada no portal) e o tempo de cada um
 *
 * O código guarda-se só como SHA-256 (como uma palavra-passe); cada acesso fica registado na ligação (última hora e IP).
 *
 * Onde fica o ecrã (classificação da informação, ISO/IEC 27001 A.5.12 e A.7.7 «ecrã limpo»; protecção de dados
 * pessoais, Lei 22/11): cada ligação tem um local, e o servidor só envia o que esse local pode mostrar.
 *   - «interna» (sala comercial fechada, só a equipa): vendas em Kz, classificações por rota com o nome do promotor e
 *     destaques — reconhecimento positivo, nunca o último lugar;
 *   - «aberta» (open space, recepção, corredor: visitantes, fornecedores e outras equipas podem ver): só o progresso
 *     da equipa em percentagem e tendências (▲ ▼), contagens e destaques por rota, sem valores em Kz, sem nomes de
 *     pessoas e sem classificações. As classificações não chegam a ser enviadas.
 *   - em nenhum ecrã: clientes, NIF, documentos, caixa, faltas, perdas, anulados ou risco por pessoa.
 */
defined('NAVAL_API') || exit;
naval_servico('analise');
naval_servico('previsao');

/** Ecrãs do painel da TV, pela ordem de origem. */
const NAVAL_SALA_ECRAS = ['hoje', 'rank-hoje', 'rank-mes', 'destaques'];
/** Ecrãs que podem estar num espaço aberto (versão sem valores em Kz nem nomes). */
const NAVAL_SALA_ECRAS_ABERTA = ['hoje', 'destaques'];
const NAVAL_SALA_LOCAIS = ['interna', 'aberta'];

/** Dados para um ecrã em espaço aberto (função pura): percentagens, tendências e contagens; sem Kz nem nomes. */
function naval_sala_aberta(array $r): array
{
    $hoje = (array) ($r['hoje'] ?? []);
    $c = (array) ($r['compras']['hoje'] ?? []);
    return [
        'hoje' => ['progresso' => $hoje['progresso'] ?? null, 'documentos' => (int) ($hoje['documentos'] ?? 0)],
        'ontem' => ['variacao' => $r['ontem']['variacao'] ?? null],
        'semana' => ['variacao' => $r['semana']['variacao'] ?? null],
        'mes' => ['variacao' => $r['mes']['variacao'] ?? null],
        'compras' => ['hoje' => ['compras' => (int) ($c['compras'] ?? 0)]],
        'rotas' => array_map(function ($x) {
            return ['rota' => (string) $x['rota'],
                'progresso_hoje' => !empty($x['previsto_hoje']) ? round((float) $x['hoje'] / (float) $x['previsto_hoje'], 3) : null,
                'variacao_mes' => $x['variacao_mes'] ?? null, 'sequencia' => (int) ($x['sequencia'] ?? 0),
                'ontem_acima' => (float) ($x['habitual_ontem'] ?? 0) > 0 && (float) ($x['ontem'] ?? 0) > (float) $x['habitual_ontem']];
        }, (array) ($r['rotas'] ?? [])),
    ];
}

/** Ecrãs de uma ligação, limitados ao que o local permite. */
function naval_sala_ecras_local(array $ecras, string $local): array
{
    return array_values(array_filter($ecras, function ($e) use ($local) { return $local !== 'aberta' || in_array($e, NAVAL_SALA_ECRAS_ABERTA, true); }));
}

function naval_sala_ficheiro(): string
{
    return naval_pasta('sala') . '/ligacoes.json';
}

if (($_GET['gerir'] ?? '') === '1') {
    $GLOBALS['naval_api_sensivel'] = true;
    if (!naval_pode('painel-sala', 'G')) throw new NavalErro('Sem permissão para gerir o painel da sala comercial.', 403);
    $lig = naval_json_ler(naval_sala_ficheiro());
    $vista = function (array $l) {
        return array_map(function ($id, $x) { unset($x['hash']); return ['id' => $id] + $x; }, array_keys($l), $l);
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
            $local = (string) ($p['local'] ?? '');
            if (!in_array($local, NAVAL_SALA_LOCAIS, true)) throw new NavalErro('Indique se o ecrã fica numa sala interna ou num espaço aberto.', 400);
            if (count(array_filter($lig, function ($x) { return empty($x['revogado']); })) >= 20) throw new NavalErro('Demasiadas ligações activas: revogue as que já não usa.', 400);
            $codigo = bin2hex(random_bytes(32));
            $id = bin2hex(random_bytes(6));
            $lig[$id] = ['nome' => $nome, 'hash' => hash('sha256', $codigo), 'criado' => date('Y-m-d H:i:s'), 'criado_por' => $eu['login'], 'revogado' => null, 'ultimo_acesso' => null, 'local' => $local];
            if ($local === 'aberta') $lig[$id]['config'] = ['ecras' => NAVAL_SALA_ECRAS_ABERTA, 'segundos' => 20];
            naval_json_gravar(naval_sala_ficheiro(), $lig);
            naval_registar('sala_ligacao_criada', ['id' => $id, 'nome' => $nome, 'local' => $local]);
            return ['id' => $id, 'codigo' => $codigo];
        }
        if ($accao === 'configurar') {
            $id = (string) ($p['id'] ?? '');
            if (!isset($lig[$id]) || !empty($lig[$id]['revogado'])) throw new NavalErro('Ligação desconhecida.', 404);
            $local = in_array($p['local'] ?? '', NAVAL_SALA_LOCAIS, true) ? (string) $p['local'] : (string) ($lig[$id]['local'] ?? 'interna');
            $ecras = array_values(array_unique(array_filter((array) ($p['ecras'] ?? []), function ($e) { return in_array($e, NAVAL_SALA_ECRAS, true); })));
            if ($local === 'aberta' && array_diff($ecras, NAVAL_SALA_ECRAS_ABERTA)) {
                throw new NavalErro('Num espaço aberto só podem estar «Vendas de hoje» e «Destaques» (sem valores nem nomes): as classificações ficam para a sala interna.', 400);
            }
            if (!$ecras) throw new NavalErro('Escolha pelo menos um ecrã.', 400);
            $lig[$id]['local'] = $local;
            $seg = (int) ($p['segundos'] ?? 20);
            $lig[$id]['config'] = ['ecras' => $ecras, 'segundos' => in_array($seg, [10, 20, 30, 60], true) ? $seg : 20];
            naval_json_gravar(naval_sala_ficheiro(), $lig);
            naval_registar('sala_ligacao_configurada', ['id' => $id, 'ecras' => $ecras, 'local' => $local]);
            return ['id' => $id];
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
$codigo = (string) ($_SERVER['HTTP_X_PAINEL_CODIGO'] ?? '');
$hash = preg_match('/^[a-f0-9]{64}$/', $codigo) ? hash('sha256', $codigo) : '';
$id = null;
$config = null;
$local = 'interna';
foreach (naval_json_ler(naval_sala_ficheiro()) as $k => $x) {
    if ($hash !== '' && empty($x['revogado']) && hash_equals((string) $x['hash'], $hash)) {
        $id = $k;
        $config = $x['config'] ?? null;
        $local = in_array($x['local'] ?? '', NAVAL_SALA_LOCAIS, true) ? (string) $x['local'] : 'interna';
    }
}
if ($id === null) {
    // no máximo um registo por minuto e por IP (o ritmo da API já limita as tentativas)
    $ip = naval_ip_cliente();
    $marca = naval_pasta('limites') . '/sala-recusa-' . substr(hash('sha256', $ip), 0, 16);
    if (!is_file($marca) || filemtime($marca) < time() - 60) {
        @touch($marca);
        naval_registar('sala_acesso_recusado', [], 'painel-sala');
    }
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
$config = $config ?: ['ecras' => $local === 'aberta' ? NAVAL_SALA_ECRAS_ABERTA : NAVAL_SALA_ECRAS, 'segundos' => 20];
$config['ecras'] = naval_sala_ecras_local((array) $config['ecras'], $local) ?: NAVAL_SALA_ECRAS_ABERTA;
if ($local === 'aberta') naval_json(['ok' => true, 'local' => 'aberta', 'config' => $config, 'hora' => $r['hora']] + naval_sala_aberta($r));
// sala interna: só totais por rota e o nome do promotor (sem clientes, documentos nem NIF)
naval_json(['ok' => true, 'local' => 'interna', 'config' => $config, 'hora' => $r['hora'], 'hoje' => $r['hoje'], 'ontem' => $r['ontem'], 'semana' => $r['semana'], 'mes' => $r['mes'], 'compras' => $r['compras'] ?? null,
    'rotas' => array_map(function ($x) {
        return array_intersect_key($x, array_flip(['rota', 'promotor', 'hoje', 'previsto_hoje', 'ontem', 'habitual_ontem', 'mes', 'mes_anterior', 'variacao_mes', 'sequencia']));
    }, $r['rotas'])]);
