<?php
/**
 * Suporte e incidentes (ISO/IEC 20000-1 e ITIL 4; incidentes de segurança pela ISO/IEC 27035).
 * Classificação: tipo, serviço afectado (catálogo) e prioridade calculada pela matriz impacto × urgência, com prazo da
 * primeira resposta e da resolução; contexto automático (página, versão, navegador) e captura de ecrã opcional. Cada
 * pedido novo segue por correio para a equipa de TI (ITSupport, ferramenta de pedidos: suporte-encaminhar.php).
 *
 * Qualquer pessoa com acesso abre um pedido; quem tem «G» na matriz (administrador da plataforma) gere todos.
 *   tipos       pedido (serviço) · incidente (algo não funciona) · seguranca (incidente de segurança) · melhoria ·
 *               nao_conformidade (ISO/IEC 27001, cláusula 10.2: a resolução exige a causa e a acção correctiva)
 *   prioridade  P1 crítica 4 h · P2 alta 8 h · P3 média 24 h · P4 baixa 72 h (prazo de resolução, horas seguidas)
 *   estados     novo → em_curso → resolvido → fechado (o pedido avalia de 1 a 5; sem avaliação, fecha ao fim de 7 dias)
 *               resolvido → em_curso (reaberto pelo pedido)
 * Incidentes P1/P2 e de segurança: a resolução exige a causa e as lições aprendidas (gestão de problemas). Um
 * incidente de segurança vai logo por correio ao super administrador; se envolver dados pessoais, lembra a avaliação
 * de comunicação à Agência de Protecção de Dados (Lei n.º 22/11). Os incidentes de segurança só os vê quem os abriu e
 * quem gere. Dados em dados-privados/suporte/pedidos.json; cada passo fica no histórico do pedido e no registo.
 */
defined('NAVAL_NUCLEO') || exit;

const NAVAL_SUP_TIPOS = ['pedido' => 'Pedido de serviço', 'incidente' => 'Incidente', 'seguranca' => 'Incidente de segurança', 'melhoria' => 'Sugestão de melhoria', 'nao_conformidade' => 'Não conformidade'];
/** Prazo de resolução (horas seguidas) por prioridade. */
const NAVAL_SUP_PRIORIDADES = ['P1' => 4, 'P2' => 8, 'P3' => 24, 'P4' => 72];
/** Prazo da primeira resposta (horas) por prioridade. */
const NAVAL_SUP_RESPOSTA = ['P1' => 0.25, 'P2' => 1, 'P3' => 4, 'P4' => 8];
/** Catálogo de serviços (ISO/IEC 20000-1, 8.2): o serviço afectado classifica o pedido. */
const NAVAL_SUP_SERVICOS = ['portal' => 'Portal RTM', 'app' => 'App de gestão', 'smartphone' => 'Smartphone RTM', 'impressora' => 'Impressora móvel',
    'pinguim' => 'Pinguim BO', 'sap' => 'SAP', 'rede' => 'Rede e Internet', 'acessos' => 'Contas e acessos', 'outro' => 'Outro'];
/** Impacto (quem é afectado) e urgência (quanto tempo pode esperar): a prioridade vem da matriz (ITIL 4). */
const NAVAL_SUP_IMPACTOS = ['alto' => 'Toda a empresa ou vários departamentos', 'medio' => 'Uma rota, equipa ou departamento', 'baixo' => 'Só eu'];
const NAVAL_SUP_URGENCIAS = ['alta' => 'Não consigo trabalhar', 'media' => 'Trabalho com dificuldade', 'baixa' => 'Pode esperar'];
const NAVAL_SUP_MATRIZ = [
    'alto' => ['alta' => 'P1', 'media' => 'P2', 'baixa' => 'P3'],
    'medio' => ['alta' => 'P2', 'media' => 'P3', 'baixa' => 'P4'],
    'baixo' => ['alta' => 'P3', 'media' => 'P4', 'baixa' => 'P4'],
];
const NAVAL_SUP_ANEXO_MAX = 5242880;   // 5 MB (captura de ecrã)
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

/** Prazo da primeira resposta a partir da criação e da prioridade. */
function naval_sup_prazo_resposta(string $criado, string $prio): string
{
    return date('Y-m-d H:i:s', (int) strtotime($criado) + (int) round(NAVAL_SUP_RESPOSTA[$prio] * 3600));
}

/**
 * Prioridade pela matriz impacto × urgência (ITIL 4). Incidente de segurança: pelo menos P2; sugestão de melhoria: P4.
 * Sem impacto e urgência (pedidos antigos), usa a prioridade indicada.
 */
function naval_sup_prioridade(string $tipo, string $impacto, string $urgencia, string $indicada = 'P3'): string
{
    $p = NAVAL_SUP_MATRIZ[$impacto][$urgencia] ?? (isset(NAVAL_SUP_PRIORIDADES[$indicada]) ? $indicada : 'P3');
    if ($tipo === 'seguranca' && $p > 'P2') $p = 'P2';
    if ($tipo === 'melhoria') $p = 'P4';
    return $p;
}

/** Contexto automático do pedido: página, ecrã e idioma (do portal) e versão e navegador (do servidor). */
function naval_sup_contexto(array $c): array
{
    $pag = (string) ($c['pagina'] ?? '');
    $ecra = (string) ($c['ecra'] ?? '');
    $idioma = (string) ($c['idioma'] ?? '');
    $versao = '';
    $revisao = '';
    try {
        if (!function_exists('naval_act_estado')) naval_servico('actualizacao');
        $e = naval_act_estado();
        $versao = (string) ($e['versao'] ?? '');
        $revisao = substr((string) ($e['commit'] ?? ''), 0, 12);
    } catch (Throwable $e) {
        // sem actualizador: fica sem versão
    }
    return [
        'pagina' => preg_match('/^[a-z0-9-]{1,40}$/', $pag) ? $pag : '',
        'ecra' => preg_match('/^\d{2,5}x\d{2,5}$/', $ecra) ? $ecra : '',
        'idioma' => in_array($idioma, ['pt', 'en', 'ti'], true) ? $idioma : '',
        'versao' => $versao,
        'revisao' => $revisao,
        'navegador' => mb_substr(preg_replace('/[\x00-\x1f]/', '', (string) ($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 300),
    ];
}

/** Captura de ecrã do pedido (PNG, JPEG ou WebP até 5 MB, conferida pelo conteúdo): [nome, tipo, bytes] ou null. */
function naval_sup_anexo_validar($a): ?array
{
    if (!is_array($a) || empty($a['dados'])) return null;
    $b = base64_decode((string) $a['dados'], true);
    if ($b === false || $b === '') throw new NavalErro('A imagem não é válida.', 400);
    if (strlen($b) > NAVAL_SUP_ANEXO_MAX) throw new NavalErro('A imagem tem no máximo 5 MB.', 400);
    $tipo = strncmp($b, "\x89PNG\r\n\x1a\n", 8) === 0 ? 'png' : (strncmp($b, "\xff\xd8\xff", 3) === 0 ? 'jpg' : (substr($b, 0, 4) === 'RIFF' && substr($b, 8, 4) === 'WEBP' ? 'webp' : ''));
    if ($tipo === '') throw new NavalErro('A captura tem de ser uma imagem PNG, JPEG ou WebP.', 400);
    return ['ext' => $tipo, 'tipo' => ['png' => 'image/png', 'jpg' => 'image/jpeg', 'webp' => 'image/webp'][$tipo], 'bytes' => $b];
}

function naval_sup_pasta_anexos(): string
{
    return naval_pasta('suporte/anexos');
}

/** Vista de um pedido para a página: o histórico e o estado do prazo. */
function naval_sup_publico(array $p, int $agora): array
{
    $fim = $p['resolvido_em'] ?? null;
    $p['fora_prazo'] = ($fim ? strtotime($fim) : $agora) > strtotime($p['prazo']);
    $resp = $p['respondido_em'] ?? null;
    $p['resposta_fora_prazo'] = !empty($p['prazo_resposta']) && ($resp ? strtotime($resp) : ($fim ? strtotime($fim) : $agora)) > strtotime($p['prazo_resposta']);
    $p['anexos'] = array_map(function ($a) { return ['nome' => $a['nome'], 'tipo' => $a['tipo'], 'bytes' => $a['bytes']]; }, (array) ($p['anexos'] ?? []));
    return $p;
}

/** Abre um pedido. */
function naval_sup_criar(array $eu, array $d): array
{
    $tipo = (string) ($d['tipo'] ?? '');
    $servico = (string) ($d['servico'] ?? 'outro');
    $impacto = (string) ($d['impacto'] ?? '');
    $urgencia = (string) ($d['urgencia'] ?? '');
    $titulo = trim((string) ($d['titulo'] ?? ''));
    $desc = trim((string) ($d['descricao'] ?? ''));
    if (!isset(NAVAL_SUP_TIPOS[$tipo])) throw new NavalErro('Escolha o tipo do pedido.', 400);
    if (!isset(NAVAL_SUP_SERVICOS[$servico])) throw new NavalErro('Escolha o serviço afectado.', 400);
    if (($impacto !== '' || $urgencia !== '') && (!isset(NAVAL_SUP_IMPACTOS[$impacto]) || !isset(NAVAL_SUP_URGENCIAS[$urgencia]))) {
        throw new NavalErro('Indique o impacto e a urgência.', 400);
    }
    if ($impacto === '' && isset($d['prioridade']) && !isset(NAVAL_SUP_PRIORIDADES[(string) $d['prioridade']])) throw new NavalErro('Prioridade inválida.', 400);
    $prio = naval_sup_prioridade($tipo, $impacto, $urgencia, (string) ($d['prioridade'] ?? 'P3'));
    if (mb_strlen($titulo) < 4 || mb_strlen($titulo) > 120) throw new NavalErro('O título tem de ter entre 4 e 120 caracteres.', 400);
    if (mb_strlen($desc) > 5000) throw new NavalErro('A descrição tem no máximo 5000 caracteres.', 400);
    $anexo = naval_sup_anexo_validar($d['anexo'] ?? null);
    $contexto = naval_sup_contexto((array) ($d['contexto'] ?? []));
    $p = naval_exclusivo('suporte', function () use ($eu, $tipo, $prio, $titulo, $desc, $d, $servico, $impacto, $urgencia, $anexo, $contexto) {
        $todos = naval_sup_todos();
        $cont = naval_pasta('suporte') . '/contador.json';
        $n = 1 + max(array_merge([0, (int) (naval_json_ler($cont)['ultimo'] ?? 0)], array_map(function ($x) { return (int) substr($x, 4); }, array_keys($todos))));
        naval_json_gravar($cont, ['ultimo' => $n]);
        $id = 'SUP-' . str_pad((string) $n, 6, '0', STR_PAD_LEFT);
        $agora = date('Y-m-d H:i:s');
        $anexos = [];
        if ($anexo) {
            $f = $id . '-1.' . $anexo['ext'];
            file_put_contents(naval_sup_pasta_anexos() . '/' . $f, $anexo['bytes']);
            $anexos[] = ['ficheiro' => $f, 'nome' => 'captura-' . $id . '.' . $anexo['ext'], 'tipo' => $anexo['tipo'], 'bytes' => strlen($anexo['bytes']), 'sha256' => hash('sha256', $anexo['bytes'])];
        }
        $p = ['id' => $id, 'tipo' => $tipo, 'servico' => $servico, 'impacto' => $impacto, 'urgencia' => $urgencia, 'prioridade' => $prio,
            'titulo' => $titulo, 'descricao' => $desc, 'estado' => 'novo',
            'criado_por' => (string) $eu['login'], 'criado_nome' => (string) ($eu['nome'] ?? $eu['login']), 'criado_email' => naval_email_valido($eu['email'] ?? null) ? trim((string) $eu['email']) : '',
            'criado_perfil' => (string) ($eu['perfil'] ?? ''), 'criado_em' => $agora,
            'prazo' => naval_sup_prazo($agora, $prio), 'prazo_resposta' => naval_sup_prazo_resposta($agora, $prio), 'respondido_em' => null,
            'contexto' => $contexto, 'anexos' => $anexos, 'encaminhamento' => null,
            'atribuido' => '', 'dados_pessoais' => !empty($d['dados_pessoais']),
            'resolucao' => '', 'causa' => '', 'licoes' => '', 'correctiva' => '', 'resolvido_em' => null, 'fechado_em' => null, 'satisfacao' => null,
            'historico' => [['em' => $agora, 'por' => (string) $eu['login'], 'accao' => 'criado', 'texto' => '']]];
        $todos[$id] = $p;
        naval_json_gravar(naval_sup_ficheiro(), $todos);
        return $p;
    });
    naval_registar('suporte_criado', ['id' => $p['id'], 'tipo' => $tipo, 'servico' => $servico, 'prioridade' => $prio]);
    // encaminhamento para a equipa de TI (ITSupport) e confirmação a quem abriu: nunca impede a criação
    naval_servico('suporte-encaminhar');
    $p = naval_sup_encaminhar($p['id']) ?? $p;
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
        // primeira resposta: a primeira acção de quem gere (que não seja quem abriu o pedido)
        if ($gere && !$dono && empty($p['respondido_em']) && in_array($accao, ['comentar', 'atender', 'resolver'], true)) $p['respondido_em'] = $agora;
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
                $p['prazo_resposta'] = naval_sup_prazo_resposta($p['criado_em'], $nova);
                break;
            case 'resolver':
                if (!$gere) throw new NavalErro('Sem permissão.', 403);
                if ($texto === '') throw new NavalErro('Descreva a resolução.', 400);
                $grave = $p['tipo'] === 'seguranca' || ($p['tipo'] === 'incidente' && in_array($p['prioridade'], ['P1', 'P2'], true));
                $causa = trim((string) ($d['causa'] ?? ''));
                $licoes = trim((string) ($d['licoes'] ?? ''));
                if ($grave && ($causa === '' || $licoes === '')) throw new NavalErro('Num incidente grave ou de segurança, indique a causa e as lições aprendidas.', 400);
                $correctiva = trim((string) ($d['correctiva'] ?? ''));
                if ($p['tipo'] === 'nao_conformidade' && ($causa === '' || $correctiva === '')) throw new NavalErro('Numa não conformidade, indique a causa e a acção correctiva.', 400);
                $p['correctiva'] = mb_substr($correctiva, 0, 2000);
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

/** Fecha os pedidos resolvidos há mais de 7 dias sem avaliação e apaga os fechados há mais de RETENCAO_SUPORTE_DIAS
 *  (3 anos por omissão; ISO/IEC 27701). O contador dos números não recua. Rotina periódica. */
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
        $limite = $agora - max(365, (int) naval_config('RETENCAO_SUPORTE_DIAS', '1095')) * 86400;
        foreach ($todos as $id => $p) {
            if ($p['estado'] === 'fechado' && $p['fechado_em'] && strtotime($p['fechado_em']) < $limite) {
                foreach ((array) ($p['anexos'] ?? []) as $a) @unlink(naval_sup_pasta_anexos() . '/' . basename((string) $a['ficheiro']));
                unset($todos[$id]);
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
        'resposta' => NAVAL_SUP_RESPOSTA,
        'servicos' => NAVAL_SUP_SERVICOS,
        'impactos' => NAVAL_SUP_IMPACTOS,
        'urgencias' => NAVAL_SUP_URGENCIAS,
        'matriz' => NAVAL_SUP_MATRIZ,
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
