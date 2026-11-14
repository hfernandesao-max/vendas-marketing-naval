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
    $l = array_values(array_filter(naval_rel_todos(), function ($r) use ($login) { return naval_rel_visivel($r, $login); }));
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
            $linhas = naval_carr_linhas((array) $reg['base'], $reg['contagem'], (array) ($reg['precos'] ?? []));
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
