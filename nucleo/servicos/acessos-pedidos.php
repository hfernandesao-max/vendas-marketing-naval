<?php
/**
 * Pedidos de acesso e revisão de acessos pelo dono de cada função (ISO/IEC 27001:2022 A.5.15, A.5.18 e A.5.3;
 * ISO/IEC 27002 5.18: o acesso é pedido, autorizado pelo dono da informação e só então atribuído, por quem não o
 * autorizou).
 *
 *   1. Pedir: qualquer pessoa pede uma ou mais funções para si ou para outra conta, com o motivo e, se for temporário,
 *      o último dia. O portal recusa logo funções que a conta já tem e combinações proibidas.
 *   2. Autorizar: o dono de cada função aprova ou recusa a sua parte. Sem dono, autoriza o super administrador.
 *      Ninguém autoriza o pedido que fez nem um pedido para a sua própria conta.
 *   3. Aplicar: o super administrador atribui as funções (em Acessos, com a confirmação da identidade). Não aplica um
 *      pedido para a sua própria conta.
 *   4. Revisão trimestral: o dono de cada função confirma quem deve manter a sua função (ou indica a quem retirar);
 *      o super administrador fecha a revisão do trimestre com essas confirmações.
 *
 * Dados em dados-privados/pedidos-acesso.json; cada passo fica no histórico do pedido e nos registos.
 */
defined('NAVAL_NUCLEO') || exit;

const NAVAL_PA_ESTADOS = ['pendente', 'aprovado', 'recusado', 'aplicado', 'anulado'];
const NAVAL_PA_SADM = '*SADM';   // aprovador quando a função não tem dono (ou o dono não pode aprovar este pedido)

function naval_pa_ficheiro(): string
{
    return naval_pasta_privada() . '/pedidos-acesso.json';
}

function naval_pa_todos(): array
{
    return naval_json_ler(naval_pa_ficheiro());
}

/** Quem autoriza a função num pedido: o dono, se for uma conta activa que não pediu nem recebe o acesso; senão o SADM. */
function naval_pa_aprovador(string $funcao, array $p): string
{
    $dono = (string) (naval_funcoes()[$funcao]['dono'] ?? '');
    $u = $dono !== '' ? (naval_utilizadores()[$dono] ?? null) : null;
    if (!$u || !naval_conta_valida($u) || $dono === $p['criado_por'] || $dono === $p['conta']) return NAVAL_PA_SADM;
    return $dono;
}

/** Pode $eu autorizar esta função do pedido? */
function naval_pa_pode_aprovar(string $funcao, array $p, array $eu): bool
{
    $login = (string) ($eu['login'] ?? '');
    if ($login === $p['criado_por'] || $login === $p['conta']) return false;
    $a = naval_pa_aprovador($funcao, $p);
    return $a === $login || ($a === NAVAL_PA_SADM && ($eu['perfil'] ?? '') === 'SADM');
}

/** Funções do pedido que ainda faltam autorizar. */
function naval_pa_por_aprovar(array $p): array
{
    return array_values(array_diff((array) $p['funcoes'], array_keys((array) ($p['aprovacoes'] ?? []))));
}

function naval_pa_visivel(array $p, array $eu): bool
{
    $login = (string) ($eu['login'] ?? '');
    if (($eu['perfil'] ?? '') === 'SADM' || $login === $p['criado_por'] || $login === $p['conta']) return true;
    foreach ((array) $p['funcoes'] as $c) if (naval_pa_aprovador($c, $p) === $login) return true;
    return false;
}

/** O pedido para o portal: com os nomes, quem autoriza cada função e o que $eu pode fazer. */
function naval_pa_publico(array $p, array $eu): array
{
    $f = naval_funcoes();
    $us = naval_utilizadores();
    $nome = function (string $l) use ($us) { return (string) ($us[$l]['nome'] ?? $l); };
    $linhas = [];
    foreach ((array) $p['funcoes'] as $c) {
        $a = naval_pa_aprovador($c, $p);
        $ap = $p['aprovacoes'][$c] ?? null;
        $linhas[] = ['funcao' => $c, 'nome' => (string) ($f[$c]['nome'] ?? $c), 'aprovador' => $a === NAVAL_PA_SADM ? '' : $a,
            'aprovador_nome' => $a === NAVAL_PA_SADM ? '' : $nome($a), 'sem_dono' => $a === NAVAL_PA_SADM,
            'aprovado_por' => $ap['por'] ?? null, 'aprovado_em' => $ap['em'] ?? null,
            'posso_aprovar' => $p['estado'] === 'pendente' && !$ap && naval_pa_pode_aprovar($c, $p, $eu)];
    }
    $login = (string) ($eu['login'] ?? '');
    return [
        'id' => $p['id'], 'conta' => $p['conta'], 'conta_nome' => $nome($p['conta']), 'funcoes' => $linhas, 'fim' => $p['fim'],
        'motivo' => $p['motivo'], 'estado' => $p['estado'], 'criado_por' => $p['criado_por'], 'criado_nome' => $nome($p['criado_por']),
        'criado_em' => $p['criado_em'], 'recusa' => $p['recusa'] ?? null, 'aplicado' => $p['aplicado'] ?? null,
        'historico' => (array) $p['historico'],
        'posso_anular' => in_array($p['estado'], ['pendente', 'aprovado'], true) && ($login === $p['criado_por'] || ($eu['perfil'] ?? '') === 'SADM'),
        'posso_recusar' => $p['estado'] === 'pendente' && (bool) array_filter($linhas, function ($x) { return $x['posso_aprovar']; }),
    ];
}

/** Contas activas para escolher no pedido (login e nome). */
function naval_pa_contas(): array
{
    $out = [];
    foreach (naval_utilizadores() as $l => $u) if (naval_conta_valida($u)) $out[] = ['login' => (string) $l, 'nome' => (string) ($u['nome'] ?? $l)];
    usort($out, function ($a, $b) { return strcasecmp($a['nome'], $b['nome']); });
    return $out;
}

/** Vista para a página: os pedidos que $eu vê, as funções que se podem pedir e a revisão da área de $eu. */
function naval_pa_vista(array $eu): array
{
    $todos = naval_pa_todos();
    krsort($todos);
    $ped = [];
    foreach ($todos as $p) if (naval_pa_visivel($p, $eu)) $ped[] = naval_pa_publico($p, $eu);
    $funcoes = [];
    foreach (naval_funcoes() as $c => $x) {
        if (empty($x['activo'])) continue;
        $funcoes[] = ['codigo' => (string) $c, 'nome' => (string) $x['nome'], 'descricao' => (string) ($x['descricao'] ?? ''),
            'tipo' => (string) ($x['tipo'] ?? 'complementar'), 'conflitos' => array_values((array) ($x['conflitos'] ?? [])), 'tem_dono' => (string) ($x['dono'] ?? '') !== ''];
    }
    // menor privilégio: cada pessoa só recebe as suas funções (o servidor recusa as que a outra conta já tem)
    $login = (string) ($eu['login'] ?? '');
    $minhas = [$login => array_values((array) (naval_utilizadores()[$login]['funcoes'] ?? []))];
    return ['pedidos' => $ped, 'funcoes' => $funcoes, 'contas' => naval_pa_contas(), 'funcoes_contas' => (object) $minhas,
        'por_decidir' => count(array_filter($ped, function ($p) { return $p['posso_recusar']; })), 'revisao' => naval_pa_revisao_area($eu)];
}

function naval_pa_data(string $d): bool
{
    return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && checkdate((int) substr($d, 5, 2), (int) substr($d, 8, 2), (int) substr($d, 0, 4));
}

/** Novo pedido. */
function naval_pa_criar(array $q, array $eu): array
{
    $eu_login = (string) ($eu['login'] ?? '');
    $conta = trim((string) ($q['conta'] ?? $eu_login));
    $us = naval_utilizadores();
    $u = $us[$conta] ?? null;
    if (!$u || !naval_conta_valida($u)) throw new NavalErro('Escolha uma conta activa.', 400);
    if (empty($u['funcoes'])) {
        throw new NavalErro('Esta conta ainda tem um perfil manual: o super administrador tem de a passar primeiro para funções (Acessos › Contas sem funções).', 409);
    }
    $f = naval_funcoes();
    $funcoes = array_values(array_unique(array_filter(array_map('strval', (array) ($q['funcoes'] ?? [])))));
    sort($funcoes);
    if (!$funcoes) throw new NavalErro('Escolha pelo menos uma função.', 400);
    foreach ($funcoes as $c) {
        if (empty($f[$c]) || empty($f[$c]['activo'])) throw new NavalErro('Função inválida: ' . $c . '.', 400);
        if (in_array($c, (array) $u['funcoes'], true)) throw new NavalErro('A conta já tem a função «' . $f[$c]['nome'] . '».', 409);
    }
    $conf = naval_funcoes_conflitos(array_merge((array) $u['funcoes'], $funcoes));
    if ($conf) {
        $nomes = array_map(function ($p) use ($f) { return '«' . $f[$p[0]]['nome'] . '» e «' . $f[$p[1]]['nome'] . '»'; }, $conf);
        throw new NavalErro('Separação de funções: a mesma pessoa não pode ter ' . implode('; ', $nomes) . '.', 400);
    }
    $motivo = naval_texto($q['motivo'] ?? '', 500);
    if (mb_strlen($motivo) < 10) throw new NavalErro('Explique o motivo do pedido (pelo menos 10 caracteres).', 400);
    $fim = trim((string) ($q['fim'] ?? ''));
    if ($fim !== '' && (!naval_pa_data($fim) || $fim < date('Y-m-d'))) throw new NavalErro('O último dia tem de ser hoje ou depois.', 400);
    return naval_exclusivo('pedidos-acesso', function () use ($conta, $funcoes, $motivo, $fim, $eu_login) {
        $t = naval_pa_todos();
        foreach ($t as $x) {
            if ($x['conta'] === $conta && in_array($x['estado'], ['pendente', 'aprovado'], true) && array_intersect($x['funcoes'], $funcoes)) {
                throw new NavalErro('Já há um pedido em curso para esta conta com a mesma função (' . $x['id'] . ').', 409);
            }
        }
        $n = 0;
        foreach (array_keys($t) as $id) $n = max($n, (int) substr((string) $id, 3));
        $id = sprintf('PA-%06d', $n + 1);
        $agora = date('Y-m-d H:i:s');
        $p = ['id' => $id, 'conta' => $conta, 'funcoes' => $funcoes, 'fim' => $fim, 'motivo' => $motivo, 'estado' => 'pendente',
            'criado_por' => $eu_login, 'criado_em' => $agora, 'aprovacoes' => [], 'historico' => [['em' => $agora, 'por' => $eu_login, 'accao' => 'pedido', 'texto' => $motivo]]];
        $t[$id] = $p;
        naval_json_gravar(naval_pa_ficheiro(), $t);
        naval_registar('acesso_pedido', ['id' => $id, 'conta' => $conta, 'funcoes' => $funcoes, 'fim' => $fim]);
        naval_pa_avisar($p);
        return $p;
    });
}

/** Avisa por correio quem tem de autorizar (os donos; sem dono, os super administradores). */
function naval_pa_avisar(array $p): void
{
    try {
        naval_servico('email');
        $us = naval_utilizadores();
        $para = [];
        foreach ((array) $p['funcoes'] as $c) {
            $a = naval_pa_aprovador($c, $p);
            $logins = $a === NAVAL_PA_SADM
                ? array_keys(array_filter($us, function ($u, $l) use ($p) { return ($u['perfil'] ?? '') === 'SADM' && naval_conta_valida($u) && $l !== $p['criado_por'] && $l !== $p['conta']; }, ARRAY_FILTER_USE_BOTH))
                : [$a];
            foreach ($logins as $l) if (naval_email_valido($us[$l]['email'] ?? null)) $para[(string) $us[$l]['email']] = true;
        }
        if (!$para) return;
        $nome = htmlspecialchars((string) ($us[$p['conta']]['nome'] ?? $p['conta']), ENT_QUOTES, 'UTF-8');
        naval_enviar_email(array_keys($para), 'Portal RTM · pedido de acesso ' . $p['id'] . ' por autorizar',
            '<p>Boa tarde,</p><p>Há um pedido de acesso por autorizar: <b>' . $p['id'] . '</b>, para ' . $nome . '.</p>'
            . '<p>Decida em Portal RTM › Administração › Suporte › Pedidos de acesso.</p><p>Obrigado.</p>');
    } catch (Throwable $e) {
        naval_registar('acesso_pedido_aviso_falhou', ['id' => $p['id'], 'erro' => $e->getMessage()]);
    }
}

/** Aprovar (cada um a sua parte), recusar ou anular. */
function naval_pa_decidir(string $id, string $accao, array $eu, string $nota = ''): array
{
    if (!preg_match('/^PA-\d{6}$/', $id)) throw new NavalErro('Pedido inválido.', 400);
    return naval_exclusivo('pedidos-acesso', function () use ($id, $accao, $eu, $nota) {
        $t = naval_pa_todos();
        $p = $t[$id] ?? null;
        if (!$p) throw new NavalErro('Pedido não encontrado.', 404);
        $login = (string) ($eu['login'] ?? '');
        $agora = date('Y-m-d H:i:s');
        $nota = naval_texto($nota, 500);
        if ($accao === 'aprovar' || $accao === 'recusar') {
            if ($p['estado'] !== 'pendente') throw new NavalErro('Este pedido já não está por autorizar.', 409);
            $minhas = array_values(array_filter(naval_pa_por_aprovar($p), function ($c) use ($p, $eu) { return naval_pa_pode_aprovar($c, $p, $eu); }));
            if (!$minhas) throw new NavalErro('Segregação de funções: só o dono da função autoriza, e nunca o pedido que fez nem o acesso para a sua própria conta.', 403);
            if ($accao === 'recusar') {
                if (mb_strlen($nota) < 5) throw new NavalErro('Indique o motivo da recusa.', 400);
                $p['estado'] = 'recusado';
                $p['recusa'] = ['por' => $login, 'em' => $agora, 'nota' => $nota];
            } else {
                foreach ($minhas as $c) $p['aprovacoes'][$c] = ['por' => $login, 'em' => $agora];
                if (!naval_pa_por_aprovar($p)) $p['estado'] = 'aprovado';
            }
            $p['historico'][] = ['em' => $agora, 'por' => $login, 'accao' => $accao, 'texto' => $nota, 'funcoes' => $minhas];
        } elseif ($accao === 'anular') {
            if (!in_array($p['estado'], ['pendente', 'aprovado'], true)) throw new NavalErro('Este pedido já não se pode anular.', 409);
            if ($login !== $p['criado_por'] && ($eu['perfil'] ?? '') !== 'SADM') throw new NavalErro('Só quem pediu (ou o super administrador) anula o pedido.', 403);
            $p['estado'] = 'anulado';
            $p['historico'][] = ['em' => $agora, 'por' => $login, 'accao' => 'anular', 'texto' => $nota];
        } else {
            throw new NavalErro('Acção desconhecida.', 400);
        }
        $t[$id] = $p;
        naval_json_gravar(naval_pa_ficheiro(), $t);
        naval_registar('acesso_pedido_' . $accao, ['id' => $id, 'conta' => $p['conta'], 'estado' => $p['estado']]);
        return $p;
    });
}

/** Pedidos autorizados à espera de serem aplicados (para a página Acessos do super administrador). */
function naval_pa_para_aplicar(): array
{
    $eu = (array) naval_utilizador();
    $out = [];
    foreach (naval_pa_todos() as $p) if ($p['estado'] === 'aprovado') $out[] = naval_pa_publico($p, $eu) + ['posso_aplicar' => ($eu['login'] ?? '') !== $p['conta']];
    return $out;
}

/**
 * Aplica um pedido autorizado (só o super administrador, pela página Acessos): junta as funções à conta, com o último
 * dia se for temporário, e recalcula o perfil. Recusa se a combinação passou a ser proibida entretanto.
 */
function naval_pa_aplicar(string $id, array $eu): array
{
    if (($eu['perfil'] ?? '') !== 'SADM') throw new NavalErro('Só o super administrador aplica pedidos de acesso.', 403);
    if (!preg_match('/^PA-\d{6}$/', $id)) throw new NavalErro('Pedido inválido.', 400);
    return naval_exclusivo('pedidos-acesso', function () use ($id, $eu) {
        $t = naval_pa_todos();
        $p = $t[$id] ?? null;
        if (!$p || $p['estado'] !== 'aprovado') throw new NavalErro('Este pedido não está autorizado para aplicar.', 409);
        $login = (string) $eu['login'];
        if ($login === $p['conta']) throw new NavalErro('Segregação de funções: não aplica um pedido de acesso para a sua própria conta.', 403);
        $d = naval_utilizadores();
        $u = $d[$p['conta']] ?? null;
        if (!$u || !naval_conta_valida($u) || empty($u['funcoes'])) throw new NavalErro('A conta deixou de estar activa ou de usar funções.', 409);
        $funcoes = array_values(array_unique(array_merge((array) $u['funcoes'], (array) $p['funcoes'])));
        sort($funcoes);
        $u['perfil'] = naval_funcoes_perfil($funcoes, $login);
        $u['funcoes'] = $funcoes;
        if ($p['fim'] !== '') {
            $fim = (array) ($u['funcoes_fim'] ?? []);
            foreach ((array) $p['funcoes'] as $c) $fim[$c] = $p['fim'];
            ksort($fim);
            $u['funcoes_fim'] = $fim;
        }
        $u['alterado'] = date('Y-m-d H:i');
        $u['alterado_por'] = $login;
        $d[$p['conta']] = $u;
        naval_gravar_utilizadores($d);
        $agora = date('Y-m-d H:i:s');
        $p['estado'] = 'aplicado';
        $p['aplicado'] = ['por' => $login, 'em' => $agora];
        $p['historico'][] = ['em' => $agora, 'por' => $login, 'accao' => 'aplicar', 'texto' => ''];
        $t[$id] = $p;
        naval_json_gravar(naval_pa_ficheiro(), $t);
        naval_registar('conta_alterada', ['conta' => $p['conta'], 'funcoes' => implode(', ', $funcoes), 'perfil' => $u['perfil'], 'pedido' => $id]
            + ($p['fim'] !== '' ? ['funcoes_fim' => $p['fim']] : []));
        return naval_pa_publico($p, $eu);
    });
}

// ── Revisão trimestral pelo dono de cada função ──

/** Revisão aberta (a mais recente por confirmar), ou null. */
function naval_pa_revisao_aberta(): ?array
{
    naval_servico('conformidade');
    $fs = glob(naval_conf_pasta() . '/revisao-*.json') ?: [];
    rsort($fs);
    foreach ($fs as $f) {
        $r = naval_json_ler($f);
        if ($r && empty($r['confirmada'])) return $r + ['_ficheiro' => $f];
    }
    return null;
}

/** Funções de que $login é dono. */
function naval_pa_funcoes_do_dono(string $login): array
{
    return array_keys(array_filter(naval_funcoes(), function ($x) use ($login) { return $login !== '' && (string) ($x['dono'] ?? '') === $login && !empty($x['activo']); }));
}

/** Contas com cada uma das funções indicadas (agora), para a revisão do dono. */
function naval_pa_contas_com(array $funcoes): array
{
    $f = naval_funcoes();
    $out = [];
    foreach (naval_utilizadores() as $l => $u) {
        if (!naval_conta_valida($u)) continue;
        foreach (array_intersect((array) ($u['funcoes'] ?? []), $funcoes) as $c) {
            $out[] = ['conta' => (string) $l, 'nome' => (string) ($u['nome'] ?? $l), 'funcao' => (string) $c, 'funcao_nome' => (string) ($f[$c]['nome'] ?? $c),
                'ate' => (string) ($u['funcoes_fim'][$c] ?? '')];
        }
    }
    return $out;
}

/** A parte da revisão aberta que cabe a $eu (como dono), ou null. */
function naval_pa_revisao_area(array $eu): ?array
{
    $login = (string) ($eu['login'] ?? '');
    $minhas = naval_pa_funcoes_do_dono($login);
    if (!$minhas) return null;
    $r = naval_pa_revisao_aberta();
    if (!$r) return null;
    return ['trimestre' => $r['trimestre'], 'contas' => naval_pa_contas_com($minhas), 'confirmada' => $r['donos'][$login] ?? null];
}

/** O dono confirma a sua parte: mantém todos, ou indica a quem retirar a função (o super administrador retira). */
function naval_pa_revisao_confirmar(array $eu, array $retirar, string $notas): array
{
    $login = (string) ($eu['login'] ?? '');
    $minhas = naval_pa_funcoes_do_dono($login);
    if (!$minhas) throw new NavalErro('Não é dono de nenhuma função.', 403);
    $r = naval_pa_revisao_aberta();
    if (!$r) throw new NavalErro('Não há revisão de acessos aberta.', 404);
    if (!empty($r['donos'][$login])) throw new NavalErro('Já confirmou a sua parte da revisão de ' . $r['trimestre'] . '.', 409);
    $validas = [];
    foreach (naval_pa_contas_com($minhas) as $x) $validas[$x['conta'] . '|' . $x['funcao']] = $x;
    $ret = [];
    foreach ($retirar as $x) {
        $k = (string) ($x['conta'] ?? '') . '|' . (string) ($x['funcao'] ?? '');
        if (isset($validas[$k])) $ret[] = ['conta' => $validas[$k]['conta'], 'funcao' => $validas[$k]['funcao']];
    }
    $notas = naval_texto($notas, 1000);
    if ($ret && mb_strlen($notas) < 5) throw new NavalErro('Explique porque se retira o acesso.', 400);
    return naval_exclusivo('revisao-acessos', function () use ($r, $login, $ret, $notas, $validas) {
        $f = (string) $r['_ficheiro'];
        $j = naval_json_ler($f);
        $j['donos'][$login] = ['em' => date('Y-m-d H:i:s'), 'contas' => count($validas), 'retirar' => $ret, 'notas' => $notas];
        naval_json_gravar($f, $j);
        naval_registar('revisao_acessos_dono', ['trimestre' => $j['trimestre'], 'contas' => count($validas), 'retirar' => count($ret)]);
        return $j['donos'][$login];
    });
}

/** Donos que ainda não confirmaram a sua parte da revisão (login => nome). */
function naval_pa_revisao_donos_em_falta(array $r): array
{
    $us = naval_utilizadores();
    $out = [];
    foreach (naval_funcoes() as $x) {
        $d = (string) ($x['dono'] ?? '');
        if ($d === '' || empty($x['activo']) || !empty($r['donos'][$d]) || !naval_conta_valida($us[$d] ?? null)) continue;
        $out[$d] = (string) ($us[$d]['nome'] ?? $d);
    }
    return $out;
}

/** Avisa cada dono de função de que a revisão do trimestre está aberta e tem de confirmar a sua parte. */
function naval_pa_revisao_avisar_donos(string $tri): int
{
    $us = naval_utilizadores();
    $para = [];
    foreach (naval_funcoes() as $x) {
        $d = (string) ($x['dono'] ?? '');
        if ($d !== '' && !empty($x['activo']) && naval_conta_valida($us[$d] ?? null) && naval_email_valido($us[$d]['email'] ?? null)) $para[(string) $us[$d]['email']] = true;
    }
    if (!$para) return 0;
    try {
        naval_servico('email');
        naval_enviar_email(array_keys($para), 'Portal RTM · revisão de acessos ' . $tri,
            '<p>Boa tarde,</p><p>Está aberta a revisão de acessos do trimestre <b>' . $tri . '</b>. Confirme quem deve manter as funções de que é dono, '
            . 'em Portal RTM › Administração › Suporte › Revisão de acessos da sua área.</p><p>Obrigado.</p>');
    } catch (Throwable $e) {
        naval_registar('revisao_aviso_falhou', ['trimestre' => $tri, 'erro' => $e->getMessage()], 'conformidade');
        return 0;
    }
    return count($para);
}
