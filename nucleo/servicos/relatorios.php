<?php
/**
 * Explorador de relatórios (relatórios criados pelas pessoas, como no Power BI, Looker ou Metabase), com governação:
 *
 *   - Camada semântica no portal (reports/js/relatorios/catalogo.js): áreas, dimensões e medidas com as regras da
 *     Naval. Um relatório guarda só a DEFINIÇÃO (área, linhas, colunas, medidas, filtros, gráfico), nunca dados: quem
 *     o abre vê os números com as SUAS permissões e rotas (ISO/IEC 27001 A.5.15, A.8.3).
 *   - Relatórios pessoais (só o dono), partilhados (todos os que podem abrir relatórios; nível G) e certificados
 *     (aprovados por quem tem «relatorios-certificar»; outra pessoa que não o autor, salvo o super administrador:
 *     controlo dos quatro olhos). Cada passo fica no registo de actividade (A.8.15).
 *   - Dados das áreas operacionais que não vêm do Pinguim BO (fecho de caixa, conciliação das carrinhas), lidos dos
 *     registos do portal por período, só de leitura e com o âmbito de rotas de quem pede.
 *
 *   - Subscrições por correio (todos os dias, às segundas-feiras, no dia 1): o correio leva o aviso e a ligação, nunca
 *     os números (ISO/IEC 27001 A.5.14, A.8.12); só recebe quem continua a poder ver o relatório e a área.
 *
 * Ficheiros: dados-privados/relatorios/REL-xxxxxxxxxx.json.
 */
defined('NAVAL_NUCLEO') || exit;

const NAVAL_REL_MAX_DIAS = 400;          // período máximo das áreas operacionais (pouco mais de um ano)
const NAVAL_REL_MAX_DEFINICAO = 20000;   // bytes da definição de um relatório
const NAVAL_REL_MAX_POR_PESSOA = 200;

function naval_rel_pasta(): string
{
    return naval_pasta('relatorios');
}

function naval_rel_id_valido(string $id): bool
{
    return (bool) preg_match('/^REL-[a-f0-9]{10}$/', $id);
}

function naval_rel_ler(string $id): ?array
{
    if (!naval_rel_id_valido($id)) return null;
    $r = naval_json_ler(naval_rel_pasta() . '/' . $id . '.json');
    return $r ?: null;
}

/** Todos os relatórios guardados. */
function naval_rel_todos(): array
{
    $out = [];
    foreach ((array) glob(naval_rel_pasta() . '/REL-*.json') as $f) {
        $r = naval_json_ler((string) $f);
        if ($r && isset($r['id'])) $out[] = $r;
    }
    return $out;
}

/** O relatório é visível para esta pessoa? Dono, partilhado ou certificado. */
function naval_rel_visivel(array $r, string $login): bool
{
    return strcasecmp((string) $r['dono'], $login) === 0 || ($r['visibilidade'] ?? '') === 'partilhado' || !empty($r['certificado']);
}

/** Relatórios que esta pessoa vê, os certificados primeiro e depois por nome. */
function naval_rel_lista(string $login): array
{
    $l = array_values(array_map(function ($r) use ($login) { return naval_rel_publico($r, $login); },
        array_filter(naval_rel_todos(), function ($r) use ($login) { return naval_rel_visivel($r, $login); })));
    usort($l, function ($a, $b) { return [empty($a['certificado']), mb_strtolower((string) $a['nome'])] <=> [empty($b['certificado']), mb_strtolower((string) $b['nome'])]; });
    return $l;
}

/**
 * Valida e normaliza a definição de um relatório (estrutura conhecida, só identificadores e valores simples).
 * Nunca contém SQL nem código: o portal traduz a definição para leituras das suas próprias APIs.
 */
function naval_rel_definicao($d): array
{
    if (!is_array($d)) throw new NavalErro('Definição do relatório inválida.', 400);
    $id = function ($v) { return is_string($v) && preg_match('/^[a-z][a-z0-9_]{0,40}(:[a-z]{2,10})?$/', $v); };
    $dominio = (string) ($d['dominio'] ?? '');
    if (!preg_match('/^[a-z][a-z0-9_]{1,40}$/', $dominio)) throw new NavalErro('Escolha a área do relatório.', 400);
    $lista = function ($v, int $max) use ($id) { return array_values(array_slice(array_filter((array) $v, $id), 0, $max)); };
    $medidas = $lista($d['medidas'] ?? [], 8);
    if (!$medidas) throw new NavalErro('Escolha pelo menos uma medida.', 400);
    $filtros = [];
    foreach (array_slice((array) ($d['filtros'] ?? []), 0, 12, true) as $campo => $vals) {
        if (!$id($campo)) continue;
        $filtros[$campo] = array_values(array_slice(array_map(function ($v) { return mb_substr((string) $v, 0, 120); }, array_filter((array) $vals, 'is_scalar')), 0, 200));
    }
    $out = [
        'dominio' => $dominio,
        'linhas' => $lista($d['linhas'] ?? [], 3),
        'coluna' => $id($d['coluna'] ?? null) ? (string) $d['coluna'] : null,
        'medidas' => $medidas,
        'filtros' => (object) $filtros,
        'ordem' => in_array($d['ordem'] ?? '', ['valor_desc', 'valor_asc', 'nome'], true) ? $d['ordem'] : 'valor_desc',
        'limite' => max(0, min(500, (int) ($d['limite'] ?? 0))),
        'grafico' => in_array($d['grafico'] ?? '', ['barras', 'colunas', 'linhas', 'nenhum'], true) ? $d['grafico'] : 'barras',
        'periodo' => in_array($d['periodo'] ?? '', ['actual', 'mes', 'mes_anterior', 'ano', 'ultimos_7', 'ultimos_30'], true) ? $d['periodo'] : 'actual',
    ];
    if (strlen((string) json_encode($out)) > NAVAL_REL_MAX_DEFINICAO) throw new NavalErro('O relatório é demasiado grande.', 400);
    return $out;
}

/** Guarda um relatório novo ou altera um existente (só o dono). Devolve o relatório. */
function naval_rel_guardar(array $p, array $eu, bool $podePartilhar): array
{
    $login = (string) $eu['login'];
    $nome = naval_texto((string) ($p['nome'] ?? ''), 80);
    if (mb_strlen($nome) < 3) throw new NavalErro('Dê um nome ao relatório (pelo menos 3 caracteres).', 400);
    $vis = ($p['visibilidade'] ?? 'pessoal') === 'partilhado' ? 'partilhado' : 'pessoal';
    if ($vis === 'partilhado' && !$podePartilhar) throw new NavalErro('O seu perfil não permite partilhar relatórios.', 403);
    $def = naval_rel_definicao($p['definicao'] ?? null);
    return naval_exclusivo('relatorios', function () use ($p, $login, $eu, $nome, $vis, $def) {
        $agora = date('Y-m-d H:i:s');
        $id = (string) ($p['id'] ?? '');
        if ($id !== '') {
            $r = naval_rel_ler($id);
            if (!$r) throw new NavalErro('Relatório desconhecido.', 404);
            if (strcasecmp((string) $r['dono'], $login) !== 0) throw new NavalErro('Só quem criou o relatório o pode alterar. Guarde uma cópia sua.', 403);
            if (isset($p['versao']) && (int) $p['versao'] !== (int) $r['versao']) throw new NavalErro('O relatório foi alterado entretanto. Abra-o de novo.', 409);
            // alterar um relatório certificado retira a certificação (tem de ser revisto de novo)
            $mudou = json_encode($r['definicao']) !== json_encode($def) || $r['nome'] !== $nome;
            $r = array_merge($r, ['nome' => $nome, 'descricao' => naval_texto((string) ($p['descricao'] ?? ''), 300), 'visibilidade' => $vis,
                'definicao' => $def, 'alterado_em' => $agora, 'versao' => (int) $r['versao'] + 1]);
            if ($mudou && !empty($r['certificado'])) $r = array_merge($r, ['certificado' => false, 'certificado_por' => null, 'certificado_em' => null]);
        } else {
            $meus = array_filter(naval_rel_todos(), function ($x) use ($login) { return strcasecmp((string) $x['dono'], $login) === 0; });
            if (count($meus) >= NAVAL_REL_MAX_POR_PESSOA) throw new NavalErro('Atingiu o limite de ' . NAVAL_REL_MAX_POR_PESSOA . ' relatórios: apague os que já não usa.', 400);
            do {
                $id = 'REL-' . bin2hex(random_bytes(5));
            } while (is_file(naval_rel_pasta() . '/' . $id . '.json'));
            $r = ['id' => $id, 'nome' => $nome, 'descricao' => naval_texto((string) ($p['descricao'] ?? ''), 300), 'visibilidade' => $vis,
                'definicao' => $def, 'dono' => $login, 'dono_nome' => (string) ($eu['nome'] ?? $login), 'criado_em' => $agora, 'alterado_em' => $agora,
                'versao' => 1, 'certificado' => false, 'certificado_por' => null, 'certificado_em' => null];
        }
        naval_json_gravar(naval_rel_pasta() . '/' . $id . '.json', $r);
        naval_registar('relatorio_guardado', ['id' => $id, 'nome' => $nome, 'dominio' => $def['dominio'], 'visibilidade' => $vis]);
        return $r;
    });
}

/** Apaga um relatório (só o dono, ou o super administrador). */
function naval_rel_apagar(string $id, array $eu, bool $sadm): void
{
    naval_exclusivo('relatorios', function () use ($id, $eu, $sadm) {
        $r = naval_rel_ler($id);
        if (!$r) throw new NavalErro('Relatório desconhecido.', 404);
        if (!$sadm && strcasecmp((string) $r['dono'], (string) $eu['login']) !== 0) throw new NavalErro('Só quem criou o relatório o pode apagar.', 403);
        @unlink(naval_rel_pasta() . '/' . $id . '.json');
        naval_registar('relatorio_apagado', ['id' => $id, 'nome' => $r['nome']]);
    });
}

/** Certifica ou retira a certificação (quatro olhos: não o próprio autor, salvo o super administrador). */
function naval_rel_certificar(string $id, bool $certificar, array $eu, bool $sadm): array
{
    return naval_exclusivo('relatorios', function () use ($id, $certificar, $eu, $sadm) {
        $r = naval_rel_ler($id);
        if (!$r) throw new NavalErro('Relatório desconhecido.', 404);
        if ($certificar && !$sadm && strcasecmp((string) $r['dono'], (string) $eu['login']) === 0) {
            throw new NavalErro('O autor não certifica o próprio relatório: tem de ser outra pessoa (controlo dos quatro olhos).', 403);
        }
        $r = array_merge($r, $certificar
            ? ['certificado' => true, 'certificado_por' => (string) $eu['login'], 'certificado_em' => date('Y-m-d H:i:s')]
            : ['certificado' => false, 'certificado_por' => null, 'certificado_em' => null]);
        naval_json_gravar(naval_rel_pasta() . '/' . $id . '.json', $r);
        naval_registar($certificar ? 'relatorio_certificado' : 'relatorio_certificacao_retirada', ['id' => $id, 'nome' => $r['nome']]);
        return $r;
    });
}

/** Dias AAAA-MM-DD de um período, validado (no máximo NAVAL_REL_MAX_DIAS). */
function naval_rel_dias(string $de, string $ate): array
{
    if (!naval_data_valida($de) || !naval_data_valida($ate) || $de > $ate) throw new NavalErro('Período inválido.', 400);
    $out = [];
    for ($t = strtotime($de . ' 12:00'); $t <= strtotime($ate . ' 12:00'); $t += 86400) {
        $out[] = date('Y-m-d', $t);
        if (count($out) > NAVAL_REL_MAX_DIAS) throw new NavalErro('Escolha um período de no máximo ' . NAVAL_REL_MAX_DIAS . ' dias.', 400);
    }
    return $out;
}

/**
 * Fecho de caixa por período: uma linha por dia (resumo) e uma por movimento (entregas, TPA, depósitos).
 * Só leitura dos ficheiros do fecho; os valores são os gravados (os mesmos da página Fecho de caixa).
 */
function naval_rel_caixa(string $de, string $ate): array
{
    naval_servico('caixa');
    $soma = function (array $l) { return round(array_sum(array_map(function ($x) { return (float) ($x['valor'] ?? 0); }, $l)), 2); };
    $dias = [];
    $mov = [];
    foreach (naval_rel_dias($de, $ate) as $d) {
        $c = naval_json_ler(naval_caixa_ficheiro($d));
        if (!$c) continue;
        $ent = (array) ($c['entregas'] ?? []);
        $tpa = (array) ($c['tpa'] ?? []);
        $sai = (array) ($c['saidas'] ?? []);
        $si = isset($c['saldo_inicial']) ? round((float) $c['saldo_inicial'], 2) : null;
        $teo = $si === null ? null : round($si + $soma($ent) - $soma($sai), 2);
        $dias[] = ['dia' => $d, 'estado' => (string) ($c['estado'] ?? ''), 'saldo_inicial' => $si, 'entregue' => $soma($ent), 'multicaixa' => $soma($tpa),
            'depositos' => $soma($sai), 'saldo_teorico' => $teo, 'contagem' => isset($c['contagem_fisica']) ? round((float) $c['contagem_fisica'], 2) : null,
            'diferenca' => isset($c['diferenca']) ? round((float) $c['diferenca'], 2) : null, 'entregas' => count($ent), 'n_depositos' => count($sai),
            'fechada_por' => (string) ($c['fechada_por'] ?? ''), 'aprovada_por' => (string) ($c['aprovada_por'] ?? '')];
        foreach ($ent as $l) $mov[] = ['dia' => $d, 'tipo' => 'entrega', 'rota' => (string) ($l['rota'] ?? ''), 'empresa' => (string) ($l['empresa'] ?? ''), 'banco' => '', 'valor' => round((float) ($l['valor'] ?? 0), 2)];
        foreach ($tpa as $l) $mov[] = ['dia' => $d, 'tipo' => 'multicaixa', 'rota' => (string) ($l['rota'] ?? ''), 'empresa' => (string) ($l['empresa'] ?? ''), 'banco' => (string) ($l['banco'] ?? ''), 'valor' => round((float) ($l['valor'] ?? 0), 2)];
        foreach ($sai as $l) $mov[] = ['dia' => $d, 'tipo' => 'deposito', 'rota' => '', 'empresa' => '', 'banco' => (string) ($l['banco'] ?? ''), 'valor' => round((float) ($l['valor'] ?? 0), 2),
            'aprovado' => !empty($l['aprovado_por']) ? 'sim' : 'nao'];
    }
    return ['dias' => $dias, 'movimentos' => $mov];
}

/**
 * Conciliação das carrinhas por período, a partir dos registos gravados (contagens e aprovações): uma linha por rota
 * e dia (resumo) e uma por artigo. Não consulta o Pinguim BO: usa a base guardada no momento da contagem.
 */
function naval_rel_conciliacao(string $de, string $ate, ?array $rotas): array
{
    naval_servico('carrinha');
    $res = [];
    $art = [];
    $prom = naval_promotores();
    foreach (naval_rel_dias($de, $ate) as $d) {
        $pasta = naval_pasta('carrinha/' . $d);
        foreach ((array) glob($pasta . '/*.json') as $f) {
            $rota = basename((string) $f, '.json');
            if (!preg_match('/^[A-Za-z0-9]{1,10}$/', $rota) || ($rotas !== null && !in_array($rota, $rotas, true))) continue;
            $reg = naval_carr_ler($d, $rota);
            if (empty($reg['base'])) continue;   // sem contagem: só a página da conciliação calcula (lê o Pinguim BO)
            $linhas = naval_carr_linhas((array) $reg['base'] + ['post' => naval_carr_posteriores($reg)], $reg['contagem'], (array) ($reg['precos'] ?? []));
            $r = naval_carr_resumo($linhas, $reg);
            $res[] = ['dia' => $d, 'rota' => $rota, 'promotor' => (string) ($prom[$rota] ?? $rota), 'estado' => $r['estado'], 'artigos' => $r['artigos'],
                'diferencas' => $r['diferencas'], 'alertas' => $r['alertas'], 'falta_kz' => $r['falta_kz'], 'sobra_kz' => $r['sobra_kz'],
                'carregado' => $r['carregado'], 'vendido' => $r['vendido'], 'contado_por' => (string) ($reg['contado_por'] ?? ''),
                'aprovado_por' => (string) ($reg['aprovado_por'] ?? '')];
            foreach ($linhas as $l) {
                $art[] = ['dia' => $d, 'rota' => $rota, 'promotor' => (string) ($prom[$rota] ?? $rota), 'codigo' => $l['codigo'], 'artigo' => $l['artigo'],
                    'inicial' => $l['inicial'], 'carregado' => $l['carregado'], 'vendido' => $l['vendido'], 'devolvido' => $l['devolvido'], 'final' => $l['final'],
                    'esperado' => $l['esperado'], 'contado' => $l['contado'], 'diferenca' => $l['diferenca'], 'valor_diferenca' => $l['valor']];
            }
        }
    }
    return ['rotas' => $res, 'artigos' => $art];
}

/**
 * Páginas de origem de cada área do catálogo (reports/js/relatorios/catalogo.js; o teste unitário confirma que as
 * duas listas são iguais). Quem não vê nenhuma das páginas não recebe as subscrições dessa área.
 */
const NAVAL_REL_DOMINIOS = [
    'vendas' => ['vendas-dia', 'vendas-promotor', 'vendas-cliente'],
    'artigos' => ['vendas-artigo', 'vendas-promotor-artigo'],
    'cobrancas' => ['pagamentos'],
    'carteira' => ['clientes'],
    'cadastros' => ['cadastros', 'cadastros-clientes'],
    'cargas' => ['plano-cargas', 'registo-cargas'],
    'stock' => ['stock-carrinha', 'stock-armazem'],
    'requisicoes' => ['requisicoes-carrinha'],
    'caixa' => ['caixa-marketing'],
    'caixa_movimentos' => ['caixa-marketing'],
    'conciliacao' => ['conciliacao-carrinha'],
    'conciliacao_artigos' => ['conciliacao-carrinha'],
    'pedidos' => ['pedidos-clientes'],
    'alertas' => ['alertas'],
    'equipamentos' => ['equipamentos'],
    'suporte' => ['suporte'],
];
const NAVAL_REL_FREQUENCIAS = ['diaria', 'semanal', 'mensal'];

/** O relatório como cada pessoa o vê: a sua própria subscrição, sem as dos outros (privacidade). */
function naval_rel_publico(array $r, string $login): array
{
    $subs = (array) ($r['subscricoes'] ?? []);
    $minha = null;
    foreach ($subs as $l => $s) if (strcasecmp((string) $l, $login) === 0) $minha = $s['frequencia'] ?? null;
    unset($r['subscricoes']);
    $r['minha_subscricao'] = $minha;
    if (strcasecmp((string) $r['dono'], $login) === 0) $r['subscritores'] = count($subs);
    return $r;
}

/** Subscreve (ou cancela, com frequência «nenhuma») o envio de um relatório por correio. Só a própria pessoa. */
function naval_rel_subscrever(string $id, string $frequencia, array $eu): array
{
    if ($frequencia !== 'nenhuma' && !in_array($frequencia, NAVAL_REL_FREQUENCIAS, true)) throw new NavalErro('Frequência inválida.', 400);
    $login = (string) $eu['login'];
    if ($frequencia !== 'nenhuma' && !naval_email_valido($eu['email'] ?? null)) {
        throw new NavalErro('A sua conta não tem endereço de correio electrónico: peça ao administrador para o acrescentar.', 400);
    }
    return naval_exclusivo('relatorios', function () use ($id, $frequencia, $login) {
        $r = naval_rel_ler($id);
        if (!$r || !naval_rel_visivel($r, $login)) throw new NavalErro('Relatório desconhecido.', 404);
        $subs = (array) ($r['subscricoes'] ?? []);
        foreach (array_keys($subs) as $l) if (strcasecmp((string) $l, $login) === 0) unset($subs[$l]);
        if ($frequencia !== 'nenhuma') $subs[$login] = ['frequencia' => $frequencia, 'desde' => date('Y-m-d H:i:s')];
        $r['subscricoes'] = (object) $subs;
        naval_json_gravar(naval_rel_pasta() . '/' . $id . '.json', $r);
        naval_registar($frequencia === 'nenhuma' ? 'relatorio_subscricao_cancelada' : 'relatorio_subscrito', ['id' => $id, 'frequencia' => $frequencia]);
        return $r;
    });
}

/** Frequências a enviar neste dia: todos os dias, à segunda-feira (semanal) e no dia 1 (mensal). */
function naval_rel_frequencias_do_dia(int $agora): array
{
    $f = ['diaria'];
    if (date('N', $agora) === '1') $f[] = 'semanal';
    if (date('j', $agora) === '1') $f[] = 'mensal';
    return $f;
}

/** Pode esta pessoa receber o relatório? Conta válida, correio, vê o relatório, «relatorios» e a página da área. */
function naval_rel_pode_receber(array $r, string $login, array $u, array $matriz): bool
{
    if (!naval_conta_valida($u) || !naval_email_valido($u['email'] ?? null) || !naval_rel_visivel($r, $login)) return false;
    $perfil = (string) $u['perfil'];
    $nivel = function (string $rec) use ($matriz, $perfil) { return $perfil === 'SADM' ? 'A' : (string) ($matriz[$rec][$perfil] ?? ''); };
    if ($nivel('relatorios') === '') return false;
    $paginas = NAVAL_REL_DOMINIOS[(string) ($r['definicao']['dominio'] ?? '')] ?? [];
    return (bool) array_filter($paginas, function ($p) use ($nivel) { return $nivel($p) !== ''; });
}

/** Mensagem da subscrição: aviso e ligação ao relatório (os números ficam no portal, com as permissões de quem abre). */
function naval_rel_email_html(array $r, string $nome, string $frequencia, string $ligacao): string
{
    $e = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
    $azul = '#1E3A8A';
    $freq = ['diaria' => 'todos os dias', 'semanal' => 'às segundas-feiras', 'mensal' => 'no dia 1 de cada mês'][$frequencia] ?? $frequencia;
    $txt = 'font-family:Segoe UI,Arial,sans-serif;color:#1C2340;font-size:14px;line-height:1.5;text-align:justify';
    $h = '<div style="' . $txt . ';max-width:640px">';
    $h .= '<div style="border-bottom:3px solid ' . $azul . ';padding-bottom:8px;margin-bottom:14px"><div style="font-size:20px;font-weight:700;color:' . $azul . '">'
        . $e($r['nome']) . '</div><div style="color:#4A5568">Relatório do Portal RTM' . (!empty($r['certificado']) ? ' · certificado' : '') . '</div></div>';
    $h .= '<p>' . $e($nome) . ', o relatório «' . $e($r['nome']) . '» está actualizado com os dados de hoje.</p>';
    if (!empty($r['descricao'])) $h .= '<p style="color:#4A5568">' . $e($r['descricao']) . '</p>';
    $h .= '<p><a href="' . $e($ligacao) . '" style="display:inline-block;background:' . $azul . ';color:#fff;text-decoration:none;padding:9px 18px;border-radius:8px;font-weight:600">Abrir o relatório</a></p>';
    $h .= '<p style="font-size:12px;color:#4A5568">Por segurança, os números não seguem neste correio: abra o relatório no portal, onde vê os dados com as suas permissões. '
        . 'Recebe este aviso ' . $e($freq) . '. Para deixar de o receber, abra o relatório e escolha «Não receber» em «Receber por correio».</p>';
    return $h . '</div>';
}

/**
 * Envia os avisos das subscrições do dia (tarefa periódica, uma vez por dia a partir de RELATORIOS_HORA, 07:30).
 * Um correio por pessoa e relatório; quem deixou de poder ver o relatório ou a área não recebe. Devolve o resumo.
 */
function naval_rel_subscricoes_enviar(int $agora): ?array
{
    $hora = naval_config('RELATORIOS_HORA', '07:30');
    if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $hora)) $hora = '07:30';
    if (date('H:i', $agora) < $hora) return null;
    $marca = naval_rel_pasta() . '/envio-' . date('Y-m-d', $agora) . '.json';
    if (is_file($marca)) return null;
    return naval_exclusivo('relatorios-envio', function () use ($agora, $marca) {
        if (is_file($marca)) return null;
        naval_servico('email');
        $freqs = naval_rel_frequencias_do_dia($agora);
        $utilizadores = naval_utilizadores();
        $matriz = naval_matriz();
        $base = rtrim(naval_config('PORTAL_URL', 'https://geo.naval.co.ao:2051'), '/') . '/reports/#relatorios?r=';
        $res = ['enviados' => 0, 'ignorados' => 0, 'falhas' => []];
        foreach (naval_rel_todos() as $r) {
            foreach ((array) ($r['subscricoes'] ?? []) as $login => $s) {
                if (!in_array($s['frequencia'] ?? '', $freqs, true)) continue;
                $u = $utilizadores[(string) $login] ?? null;
                if (!$u || !naval_rel_pode_receber($r, (string) $login, $u, $matriz)) {
                    $res['ignorados']++;
                    continue;
                }
                $nome = (string) ($u['nome'] ?? $login);
                $x = naval_enviar_email([['email' => trim((string) $u['email']), 'nome' => $nome]], 'Relatório · ' . $r['nome'],
                    naval_rel_email_html($r, $nome, (string) $s['frequencia'], $base . $r['id']));
                if ($x['ok']) $res['enviados']++;
                else $res['falhas'][] = $r['id'] . ' ' . $login . ': ' . $x['erro'];
            }
        }
        naval_json_gravar($marca, $res + ['hora' => date('H:i:s', $agora)]);
        naval_registar('relatorios_subscricoes', $res, 'sistema');
        return $res;
    });
}
