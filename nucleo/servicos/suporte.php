<?php
/**
 * Suporte e incidentes (ISO/IEC 20000-1 e ITIL; incidentes de segurança pela ISO/IEC 27035).
 *
 * Qualquer pessoa com acesso abre um pedido; quem tem «G» na matriz (administrador da plataforma) gere todos.
 *   tipos       pedido (serviço) · incidente (algo não funciona) · seguranca (incidente de segurança) · melhoria
 *   prioridade  P1 crítica 4 h · P2 alta 8 h · P3 média 24 h · P4 baixa 72 h (prazo de resolução, horas seguidas)
 *   estados     novo → em_curso → resolvido → fechado (o pedido avalia de 1 a 5; sem avaliação, fecha ao fim de 7 dias)
 *               resolvido → em_curso (reaberto pelo pedido)
 * Incidentes P1/P2 e de segurança: a resolução exige a causa e as lições aprendidas (gestão de problemas). Um
 * incidente de segurança vai logo por correio ao super administrador; se envolver dados pessoais, lembra a avaliação
 * de comunicação à Agência de Protecção de Dados (Lei n.º 22/11). Os incidentes de segurança só os vê quem os abriu e
 * quem gere. Dados em dados-privados/suporte/pedidos.json; cada passo fica no histórico do pedido e no registo.
 */
defined('NAVAL_NUCLEO') || exit;

const NAVAL_SUP_TIPOS = ['pedido' => 'Pedido de serviço', 'incidente' => 'Incidente', 'seguranca' => 'Incidente de segurança', 'melhoria' => 'Sugestão de melhoria'];
const NAVAL_SUP_PRIORIDADES = ['P1' => 4, 'P2' => 8, 'P3' => 24, 'P4' => 72];
const NAVAL_SUP_ESTADOS = ['novo', 'em_curso', 'resolvido', 'fechado'];

function naval_sup_ficheiro(): string
{
    return naval_pasta('suporte') . '/pedidos.json';
}

function naval_sup_todos(): array
{
    return naval_json_ler(naval_sup_ficheiro());
}

/** O utilizador gere o suporte (vê e trata todos os pedidos)? */
function naval_sup_gere(): bool
{
    return naval_perfil() === 'SADM' || naval_pode('suporte', 'G');
}

/** Pedido visível para o utilizador: os seus; quem gere vê todos. */
function naval_sup_visivel(array $p, array $eu): bool
{
    return naval_sup_gere() || $p['criado_por'] === $eu['login'];
}

/** Prazo de resolução a partir da criação e da prioridade. */
function naval_sup_prazo(string $criado, string $prio): string
{
    return date('Y-m-d H:i:s', (int) strtotime($criado) + NAVAL_SUP_PRIORIDADES[$prio] * 3600);
}

/** Vista de um pedido para a página: o histórico e o estado do prazo. */
function naval_sup_publico(array $p, int $agora): array
{
    $fim = $p['resolvido_em'] ?? null;
    $p['fora_prazo'] = ($fim ? strtotime($fim) : $agora) > strtotime($p['prazo']);
    return $p;
}

/** Abre um pedido. */
function naval_sup_criar(array $eu, array $d): array
{
    $tipo = (string) ($d['tipo'] ?? '');
    $prio = (string) ($d['prioridade'] ?? 'P3');
    $titulo = trim((string) ($d['titulo'] ?? ''));
    $desc = trim((string) ($d['descricao'] ?? ''));
    if (!isset(NAVAL_SUP_TIPOS[$tipo])) throw new NavalErro('Escolha o tipo do pedido.', 400);
    if (!isset(NAVAL_SUP_PRIORIDADES[$prio])) throw new NavalErro('Prioridade inválida.', 400);
    if (mb_strlen($titulo) < 4 || mb_strlen($titulo) > 120) throw new NavalErro('O título tem de ter entre 4 e 120 caracteres.', 400);
    if (mb_strlen($desc) > 5000) throw new NavalErro('A descrição tem no máximo 5000 caracteres.', 400);
    $p = naval_exclusivo('suporte', function () use ($eu, $tipo, $prio, $titulo, $desc, $d) {
        $todos = naval_sup_todos();
        $n = 1 + max(array_merge([0], array_map(function ($x) { return (int) substr($x, 4); }, array_keys($todos))));
        $id = 'SUP-' . str_pad((string) $n, 6, '0', STR_PAD_LEFT);
        $agora = date('Y-m-d H:i:s');
        $p = ['id' => $id, 'tipo' => $tipo, 'prioridade' => $prio, 'titulo' => $titulo, 'descricao' => $desc, 'estado' => 'novo',
            'criado_por' => (string) $eu['login'], 'criado_nome' => (string) ($eu['nome'] ?? $eu['login']), 'criado_em' => $agora,
            'prazo' => naval_sup_prazo($agora, $prio), 'atribuido' => '', 'dados_pessoais' => !empty($d['dados_pessoais']),
            'resolucao' => '', 'causa' => '', 'licoes' => '', 'resolvido_em' => null, 'fechado_em' => null, 'satisfacao' => null,
            'historico' => [['em' => $agora, 'por' => (string) $eu['login'], 'accao' => 'criado', 'texto' => '']]];
        $todos[$id] = $p;
        naval_json_gravar(naval_sup_ficheiro(), $todos);
        return $p;
    });
    naval_registar('suporte_criado', ['id' => $p['id'], 'tipo' => $tipo, 'prioridade' => $prio]);
    if ($tipo === 'seguranca' || in_array($prio, ['P1', 'P2'], true)) {
        naval_sup_avisar($p, $tipo === 'seguranca' ? 'incidente de segurança ' . $p['id'] : 'pedido ' . $prio . ' ' . $p['id'], array_merge(
            [NAVAL_SUP_TIPOS[$tipo] . ' ' . $p['id'] . ' (' . $prio . ', resolver até ' . date('d-m-Y H:i', (int) strtotime($p['prazo'])) . '): ' . $titulo, 'Aberto por ' . $p['criado_nome'] . '.'],
            $p['dados_pessoais'] ? ['Envolve dados pessoais: avaliar a comunicação à Agência de Protecção de Dados (Lei n.º 22/11) e aos titulares.'] : []
        ));
    }
    return $p;
}

/**
 * Acções sobre um pedido: comentar (quem o abriu ou quem gere), estado / prioridade / atribuir / resolver (quem gere),
 * reabrir e avaliar (quem o abriu).
 */
function naval_sup_accao(array $eu, string $id, string $accao, array $d): array
{
    $gere = naval_sup_gere();
    $p = naval_exclusivo('suporte', function () use ($eu, $id, $accao, $d, $gere) {
        $todos = naval_sup_todos();
        if (!isset($todos[$id]) || !naval_sup_visivel($todos[$id], $eu)) throw new NavalErro('Pedido não encontrado.', 404);
        $p = $todos[$id];
        $dono = $p['criado_por'] === $eu['login'];
        $agora = date('Y-m-d H:i:s');
        $texto = trim((string) ($d['texto'] ?? ''));
        if (mb_strlen($texto) > 5000) throw new NavalErro('O texto tem no máximo 5000 caracteres.', 400);
        $hist = ['em' => $agora, 'por' => (string) $eu['login'], 'accao' => $accao, 'texto' => $texto];
        switch ($accao) {
            case 'comentar':
                if ($texto === '') throw new NavalErro('Escreva o comentário.', 400);
                break;
            case 'atender':
                if (!$gere) throw new NavalErro('Sem permissão.', 403);
                $p['estado'] = 'em_curso';
                $p['atribuido'] = (string) ($d['atribuido'] ?? $eu['login']);
                break;
            case 'prioridade':
                if (!$gere) throw new NavalErro('Sem permissão.', 403);
                $nova = (string) ($d['prioridade'] ?? '');
                if (!isset(NAVAL_SUP_PRIORIDADES[$nova])) throw new NavalErro('Prioridade inválida.', 400);
                $hist['texto'] = $p['prioridade'] . ' → ' . $nova . ($texto !== '' ? ': ' . $texto : '');
                $p['prioridade'] = $nova;
                $p['prazo'] = naval_sup_prazo($p['criado_em'], $nova);
                break;
            case 'resolver':
                if (!$gere) throw new NavalErro('Sem permissão.', 403);
                if ($texto === '') throw new NavalErro('Descreva a resolução.', 400);
                $grave = $p['tipo'] === 'seguranca' || ($p['tipo'] === 'incidente' && in_array($p['prioridade'], ['P1', 'P2'], true));
                $causa = trim((string) ($d['causa'] ?? ''));
                $licoes = trim((string) ($d['licoes'] ?? ''));
                if ($grave && ($causa === '' || $licoes === '')) throw new NavalErro('Num incidente grave ou de segurança, indique a causa e as lições aprendidas.', 400);
                $p['estado'] = 'resolvido';
                $p['resolucao'] = $texto;
                $p['causa'] = mb_substr($causa, 0, 2000);
                $p['licoes'] = mb_substr($licoes, 0, 2000);
                $p['resolvido_em'] = $agora;
                break;
            case 'reabrir':
                if (!$dono && !$gere) throw new NavalErro('Sem permissão.', 403);
                if ($p['estado'] !== 'resolvido') throw new NavalErro('Só se reabre um pedido resolvido.', 409);
                if ($texto === '') throw new NavalErro('Diga o que continua por resolver.', 400);
                $p['estado'] = 'em_curso';
                $p['resolvido_em'] = null;
                break;
            case 'avaliar':
                if (!$dono) throw new NavalErro('Só quem abriu o pedido o avalia.', 403);
                if ($p['estado'] !== 'resolvido') throw new NavalErro('Só se avalia um pedido resolvido.', 409);
                $n = (int) ($d['satisfacao'] ?? 0);
                if ($n < 1 || $n > 5) throw new NavalErro('Avalie de 1 a 5.', 400);
                $p['satisfacao'] = $n;
                $p['estado'] = 'fechado';
                $p['fechado_em'] = $agora;
                $hist['texto'] = $n . '/5' . ($texto !== '' ? ': ' . $texto : '');
                break;
            default:
                throw new NavalErro('Acção desconhecida.', 400);
        }
        $p['historico'][] = $hist;
        $todos[$id] = $p;
        naval_json_gravar(naval_sup_ficheiro(), $todos);
        return $p;
    });
    naval_registar('suporte_' . $accao, ['id' => $id]);
    return $p;
}

/** Fecha os pedidos resolvidos há mais de 7 dias sem avaliação (rotina periódica). */
function naval_sup_fechar_antigos(int $agora): int
{
    return naval_exclusivo('suporte', function () use ($agora) {
        $todos = naval_sup_todos();
        $n = 0;
        foreach ($todos as $id => $p) {
            if ($p['estado'] === 'resolvido' && $p['resolvido_em'] && strtotime($p['resolvido_em']) < $agora - 7 * 86400) {
                $todos[$id]['estado'] = 'fechado';
                $todos[$id]['fechado_em'] = date('Y-m-d H:i:s', $agora);
                $todos[$id]['historico'][] = ['em' => date('Y-m-d H:i:s', $agora), 'por' => 'automatico', 'accao' => 'fechado', 'texto' => 'Sem avaliação em 7 dias.'];
                $n++;
            }
        }
        if ($n) naval_json_gravar(naval_sup_ficheiro(), $todos);
        return $n;
    });
}

/** Lista e indicadores para a página: os pedidos visíveis e, para quem gere, os indicadores do serviço. */
function naval_sup_vista(array $eu, int $agora): array
{
    $lista = [];
    foreach (naval_sup_todos() as $p) if (naval_sup_visivel($p, $eu)) $lista[] = naval_sup_publico($p, $agora);
    usort($lista, function ($a, $b) { return strcmp($b['criado_em'], $a['criado_em']); });
    $abertos = array_filter($lista, function ($p) { return in_array($p['estado'], ['novo', 'em_curso'], true); });
    $resolvidos30 = array_filter($lista, function ($p) use ($agora) { return $p['resolvido_em'] && strtotime($p['resolvido_em']) >= $agora - 30 * 86400; });
    $horas = array_map(function ($p) { return (strtotime($p['resolvido_em']) - strtotime($p['criado_em'])) / 3600; }, $resolvidos30);
    $aval = array_values(array_filter(array_column($lista, 'satisfacao')));
    $noPrazo = array_filter($resolvidos30, function ($p) { return !$p['fora_prazo']; });
    return [
        'gere' => naval_sup_gere(),
        'tipos' => NAVAL_SUP_TIPOS,
        'prioridades' => NAVAL_SUP_PRIORIDADES,
        'pedidos' => $lista,
        'indicadores' => [
            'abertos' => count($abertos),
            'fora_prazo' => count(array_filter($abertos, function ($p) { return $p['fora_prazo']; })),
            'seguranca_abertos' => count(array_filter($abertos, function ($p) { return $p['tipo'] === 'seguranca'; })),
            'horas_medias' => $horas ? round(array_sum($horas) / count($horas), 1) : null,
            'no_prazo_pct' => $resolvidos30 ? (int) round(100 * count($noPrazo) / count($resolvidos30)) : null,
            'satisfacao' => $aval ? round(array_sum($aval) / count($aval), 1) : null,
        ],
    ];
}

function naval_sup_avisar(array $p, string $assunto, array $linhas): void
{
    try {
        if (!function_exists('naval_act_avisar')) naval_servico('actualizacao');
        naval_act_avisar($assunto, array_merge($linhas, ['Ver em Portal RTM › Administração › Suporte.']));
    } catch (Throwable $e) {
        error_log('[suporte] ' . $e->getMessage());
    }
}
