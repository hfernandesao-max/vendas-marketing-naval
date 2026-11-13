<?php
/**
 * Requisições de reposição da carrinha ao armazém (reposição pelo consumo, «replenish what was sold»).
 *
 * Quando a contagem da carrinha é validada por quem a fez (conciliação), o sistema cria sozinho uma requisição em
 * rascunho com as quantidades vendidas nesse dia (uma por rota e dia, nunca duplicada). O promotor pode mudar as
 * quantidades e juntar outros artigos. Ciclo de vida do documento (como os documentos de armazém dos ERP):
 *   rascunho  editável (gravar quantas vezes for preciso; controlo de versão contra gravações simultâneas)
 *   emitida   fechada ao emitir o PDF ou imprimir: não se altera mais; precisa de mais mercadoria → nova requisição
 *   carregada o armazém confirmou a carga na carrinha, com a quantidade entregue de cada artigo (por omissão a
 *             requisitada); quem emitiu não confirma a sua própria carga (segregação de funções)
 *   anulada   rascunho que não vai ser usado (fica registado, com o motivo)
 * O âmbito por rota (SUP e PRO só as suas rotas) verifica-se na API (nucleo/api/v1/requisicoes.php).
 * Numeração sequencial por ano, sem saltos nem repetições: RQ-AAAA-000001. Cada passo fica no histórico do documento
 * e no registo de auditoria. Reimprimir um documento emitido gera uma 2.ª via (marcada no PDF) e fica registado.
 *
 * Ficheiros (fora da pasta pública): dados-privados/requisicoes/<ano>/<numero>.json e _sequencia.json.
 */
defined('NAVAL_NUCLEO') || exit;

const NAVAL_REQ_MAX_LINHAS = 300;

function naval_req_pasta(string $ano): string
{
    if (!preg_match('/^\d{4}$/', $ano)) throw new NavalErro('Ano inválido.', 400);
    return naval_pasta('requisicoes/' . $ano);
}

function naval_req_id_valido(string $id): bool
{
    return (bool) preg_match('/^RQ-\d{4}-\d{6}$/', $id);
}

/** Lê uma requisição (null se não existir). */
function naval_req_ler(string $id): ?array
{
    if (!naval_req_id_valido($id)) throw new NavalErro('Requisição inválida.', 400);
    $r = naval_json_ler(naval_req_pasta(substr($id, 3, 4)) . '/' . $id . '.json');
    return $r ?: null;
}

function naval_req_gravar_ficheiro(array $r): void
{
    naval_json_gravar(naval_req_pasta((string) $r['ano']) . '/' . $r['numero'] . '.json', $r);
}

/** Próximo número do ano (dentro de um bloqueio: sem números repetidos nem saltos). */
function naval_req_proximo(string $ano): string
{
    $f = naval_req_pasta($ano) . '/_sequencia.json';
    $s = naval_json_ler($f);
    $n = (int) ($s['ultimo'] ?? 0) + 1;
    naval_json_gravar($f, ['ultimo' => $n]);
    return sprintf('RQ-%s-%06d', $ano, $n);
}

/** Limpa as linhas recebidas: artigo com código, quantidade ≥ 0 (as linhas a zero saem), sem códigos repetidos. */
function naval_req_linhas(array $linhas): array
{
    $out = [];
    foreach (array_slice($linhas, 0, NAVAL_REQ_MAX_LINHAS) as $l) {
        $l = (array) $l;
        $cod = naval_texto($l['codigo'] ?? '', 40);
        if ($cod === '' || !preg_match('/^[\w.\-\/]{1,40}$/u', $cod)) continue;
        $q = $l['quantidade'] ?? null;
        if (!is_numeric($q)) throw new NavalErro('Quantidade inválida no artigo ' . $cod . '.', 400);
        $q = round((float) $q, 3);
        if ($q < 0 || $q > 1000000) throw new NavalErro('Quantidade inválida no artigo ' . $cod . '.', 400);
        if ($q <= 0) continue;
        $out[$cod] = [
            'codigo' => $cod,
            'artigo' => naval_texto($l['artigo'] ?? $cod, 120),
            'unidade' => naval_texto($l['unidade'] ?? '', 12),
            'sugerida' => isset($l['sugerida']) && is_numeric($l['sugerida']) ? round((float) $l['sugerida'], 3) : null,
            'quantidade' => $q,
            'origem' => ($l['origem'] ?? '') === 'vendido' ? 'vendido' : 'adicionado',
        ];
    }
    return array_values($out);
}

function naval_req_hist(array &$r, string $accao, string $login, array $extra = []): void
{
    $r['historico'][] = ['em' => date('Y-m-d H:i:s'), 'por' => $login, 'accao' => $accao] + $extra;
}

/** Cria uma requisição em rascunho. */
function naval_req_criar(string $rota, string $data, array $linhas, string $login, array $origem = ['tipo' => 'manual'], string $nota = ''): array
{
    if (!preg_match('/^[A-Za-z0-9]{1,10}$/', $rota)) throw new NavalErro('Rota inválida.', 400);
    if (!naval_data_valida($data)) throw new NavalErro('Data inválida.', 400);
    $ano = substr($data, 0, 4);
    // num pedido manual, a quantidade sugerida e a origem nunca vêm do navegador; e só se regista (e ganha número)
    // ao gravar com pelo menos um artigo — «Criar» só abre o formulário no ecrã
    if (($origem['tipo'] ?? '') !== 'conciliacao') {
        if (!array_filter($linhas, function ($l) { return (float) (((array) $l)['quantidade'] ?? 0) > 0; })) {
            throw new NavalErro('Adicione pelo menos um artigo com quantidade antes de gravar.', 400);
        }
        $linhas = array_map(function ($l) { return ['sugerida' => null, 'origem' => 'adicionado'] + array_diff_key((array) $l, ['sugerida' => 1, 'origem' => 1]); }, $linhas);
        naval_req_validar_stock(naval_req_linhas($linhas));
    }
    return naval_exclusivo('requisicoes-' . $ano, function () use ($rota, $data, $linhas, $login, $origem, $nota, $ano) {
        // um só rascunho manual aberto por rota e dia (sem rascunhos repetidos nem números gastos por engano)
        if (($origem['tipo'] ?? '') !== 'conciliacao') {
            foreach ((array) glob(naval_req_pasta($ano) . '/RQ-*.json') as $f) {
                $x = naval_json_ler((string) $f);
                if ($x && $x['estado'] === 'rascunho' && $x['rota'] === $rota && $x['data'] === $data && ($x['origem']['tipo'] ?? '') !== 'conciliacao') {
                    throw new NavalErro('Já existe o rascunho ' . $x['numero'] . ' para este promotor neste dia: abra-o para adicionar artigos.', 409);
                }
            }
        }
        $agora = date('Y-m-d H:i:s');
        $num = naval_req_proximo($ano);
        $r = [
            'numero' => $num, 'ano' => $ano, 'rota' => $rota, 'promotor' => naval_promotores()[$rota] ?? $rota, 'data' => $data,
            'origem' => $origem, 'estado' => 'rascunho', 'linhas' => naval_req_linhas($linhas), 'nota' => naval_texto($nota, 500),
            'versao' => 1, 'criado_por' => $login, 'criado_em' => $agora, 'alterado_por' => $login, 'alterado_em' => $agora,
            'emitida_por' => null, 'emitida_em' => null, 'impressoes' => [], 'historico' => [],
        ];
        naval_req_hist($r, $origem['tipo'] === 'conciliacao' ? 'criada_conciliacao' : 'criada', $login, ['linhas' => count($r['linhas'])]);
        naval_req_gravar_ficheiro($r);
        naval_registar('requisicao_criada', ['numero' => $num, 'rota' => $rota, 'origem' => $origem['tipo'], 'linhas' => count($r['linhas'])]);
        return $r;
    });
}

/**
 * Requisição automática da conciliação: as quantidades vendidas nesse dia (reposição pelo consumo). Uma só por rota e
 * dia (se já existir, devolve-a); sem nada vendido não cria nada.
 */
function naval_req_da_conciliacao(string $data, string $rota, array $linhasConciliacao, string $login): ?array
{
    $f = naval_pasta('requisicoes') . '/_conciliacoes.json';
    return naval_exclusivo('requisicoes-conciliacoes', function () use ($f, $data, $rota, $linhasConciliacao, $login) {
        $idx = naval_json_ler($f);
        $k = $data . '|' . $rota;
        if (!empty($idx[$k])) return naval_req_ler((string) $idx[$k]);
        $linhas = [];
        $stock = naval_req_stock_principal();
        foreach ($linhasConciliacao as $l) {
            $q = (float) ($l['vendido'] ?? 0);
            if ($q <= 0) continue;
            // reposição pelo vendido, mas nunca mais do que o stock do armazém principal (o vendido fica como sugerido)
            $pede = $stock !== null ? min($q, max(0.0, (float) ($stock[(string) $l['codigo']] ?? 0))) : $q;
            $linhas[] = ['codigo' => (string) $l['codigo'], 'artigo' => (string) $l['artigo'], 'unidade' => (string) ($l['unidade'] ?? ''),
                'sugerida' => $q, 'quantidade' => $pede, 'origem' => 'vendido'];
        }
        if (!$linhas) return null;
        $r = naval_req_criar($rota, $data, $linhas, 'automatico', ['tipo' => 'conciliacao', 'data' => $data, 'rota' => $rota, 'validada_por' => $login]);
        $idx[$k] = $r['numero'];
        naval_json_gravar($f, $idx);
        return $r;
    });
}

/** Número da requisição criada pela conciliação de uma rota e dia (ou null). */
function naval_req_da_conciliacao_numero(string $data, string $rota): ?string
{
    $idx = naval_json_ler(naval_pasta('requisicoes') . '/_conciliacoes.json');
    return isset($idx[$data . '|' . $rota]) ? (string) $idx[$data . '|' . $rota] : null;
}

/** Abre a requisição para alterar, com as verificações comuns (âmbito, estado, versão). */
function naval_req_alterar(string $id, ?int $versao, callable $f): array
{
    return naval_exclusivo('requisicao-' . $id, function () use ($id, $versao, $f) {
        $r = naval_req_ler($id);
        if (!$r) throw new NavalErro('Requisição não encontrada.', 404);
        if ($r['estado'] === 'emitida') throw new NavalErro('Esta requisição já foi emitida e está fechada. Para mais mercadoria, crie uma nova requisição.', 409);
        if ($r['estado'] !== 'rascunho') throw new NavalErro('Esta requisição foi anulada.', 409);
        if ($versao !== null && $versao !== (int) $r['versao']) {
            throw new NavalErro('A requisição foi alterada por outra pessoa entretanto. Volte a abrir para ver a versão actual.', 409, ['codigo' => 'versao']);
        }
        $r = $f($r);
        $r['versao'] = (int) $r['versao'] + 1;
        naval_req_gravar_ficheiro($r);
        return $r;
    });
}

/** Grava o rascunho (artigos, quantidades e nota). */
function naval_req_gravar(string $id, array $linhas, string $nota, ?int $versao, string $login): array
{
    return naval_req_alterar($id, $versao, function ($r) use ($linhas, $nota, $login) {
        $sug = array_column($r['linhas'], 'sugerida', 'codigo');
        $orig = array_column($r['linhas'], 'origem', 'codigo');
        $novas = naval_req_linhas(array_map(function ($l) use ($sug, $orig) {
            $l = (array) $l;
            $c = (string) ($l['codigo'] ?? '');
            // a quantidade sugerida e a origem não vêm do navegador: ficam as do sistema
            return ['sugerida' => $sug[$c] ?? null, 'origem' => $orig[$c] ?? 'adicionado'] + $l;
        }, $linhas));
        if (!array_filter($novas, function ($l) { return (float) $l['quantidade'] > 0; })) {
            throw new NavalErro('A requisição ficaria sem artigos: adicione artigos ou anule-a.', 400);
        }
        naval_req_validar_stock($novas);
        $r['linhas'] = $novas;
        $r['nota'] = naval_texto($nota, 500);
        $r['alterado_por'] = $login;
        $r['alterado_em'] = date('Y-m-d H:i:s');
        naval_req_hist($r, 'gravada', $login, ['linhas' => count($novas)]);
        return $r;
    });
}

/** Emite (fecha) a requisição: a partir daqui não se altera. Só com pelo menos um artigo. */
function naval_req_emitir(string $id, ?int $versao, string $login): array
{
    $r = naval_req_alterar($id, $versao, function ($r) use ($login) {
        if (!$r['linhas']) throw new NavalErro('A requisição não tem artigos.', 400);
        naval_req_validar_stock((array) $r['linhas']);   // o stock pode ter mudado desde a gravação
        $r['estado'] = 'emitida';
        $r['emitida_por'] = $login;
        $r['emitida_em'] = date('Y-m-d H:i:s');
        $r['impressoes'][] = ['em' => $r['emitida_em'], 'por' => $login, 'via' => 1];
        naval_req_hist($r, 'emitida', $login, ['linhas' => count($r['linhas'])]);
        return $r;
    });
    naval_registar('requisicao_emitida', ['numero' => $id, 'rota' => $r['rota'], 'linhas' => count($r['linhas'])]);
    return $r;
}

/** Reimpressão de uma requisição emitida: 2.ª via (ou seguinte), registada. */
function naval_req_reimprimir(string $id, string $login): array
{
    return naval_exclusivo('requisicao-' . $id, function () use ($id, $login) {
        $r = naval_req_ler($id);
        if (!$r) throw new NavalErro('Requisição não encontrada.', 404);
        if ($r['estado'] !== 'emitida') throw new NavalErro('Só se reimprime uma requisição emitida.', 409);
        $via = count($r['impressoes']) + 1;
        $r['impressoes'][] = ['em' => date('Y-m-d H:i:s'), 'por' => $login, 'via' => $via];
        naval_req_hist($r, 'reimpressa', $login, ['via' => $via]);
        naval_req_gravar_ficheiro($r);
        naval_registar('requisicao_reimpressa', ['numero' => $id, 'via' => $via]);
        return $r;
    });
}

/** Anula um rascunho, com motivo. */
function naval_req_anular(string $id, string $motivo, ?int $versao, string $login): array
{
    $motivo = naval_texto($motivo, 300);
    if (mb_strlen($motivo) < 5) throw new NavalErro('Indique o motivo da anulação.', 400);
    $r = naval_req_alterar($id, $versao, function ($r) use ($motivo, $login) {
        $r['estado'] = 'anulada';
        $r['anulada_por'] = $login;
        $r['anulada_em'] = date('Y-m-d H:i:s');
        $r['motivo_anulacao'] = $motivo;
        naval_req_hist($r, 'anulada', $login, ['motivo' => $motivo]);
        return $r;
    });
    naval_registar('requisicao_anulada', ['numero' => $id, 'rota' => $r['rota']]);
    return $r;
}

/**
 * Rascunhos sem artigos: os de dias anteriores anulam-se sozinhos (registados como anulados, com o motivo, para a
 * numeração continuar completa e auditável); os de hoje anulam-se com «Anular rascunhos sem artigos». Devolve os
 * números anulados.
 */
function naval_req_anular_vazios(?string $dia, string $login, ?array $rotas = null): array
{
    $feitas = [];
    $hoje = date('Y-m-d');
    foreach ([(int) date('Y') - 1, (int) date('Y')] as $a) {
        foreach ((array) glob(naval_req_pasta((string) $a) . '/RQ-*.json') as $f) {
            $x = naval_json_ler((string) $f);
            if (!$x || $x['estado'] !== 'rascunho' || count((array) $x['linhas']) > 0) continue;
            if ($dia === null ? $x['data'] >= $hoje : $x['data'] !== $dia) continue;
            if ($rotas !== null && !in_array((string) $x['rota'], $rotas, true)) continue;
            $motivo = $dia === null ? 'Rascunho sem artigos anulado automaticamente no fim do dia.' : 'Rascunho sem artigos anulado em conjunto.';
            naval_req_anular((string) $x['numero'], $motivo, null, $login);
            $feitas[] = (string) $x['numero'];
        }
    }
    return $feitas;
}

/** Lista das requisições do período (dia do movimento), no âmbito de rotas do utilizador; as mais recentes primeiro. */
function naval_req_listar(string $de, string $ate, ?array $rotas): array
{
    naval_req_anular_vazios(null, 'automatico');
    $out = [];
    for ($a = (int) substr($de, 0, 4); $a <= (int) substr($ate, 0, 4); $a++) {
        foreach ((array) glob(naval_req_pasta((string) $a) . '/RQ-*.json') as $f) {
            $r = naval_json_ler((string) $f);
            if (!$r || $r['data'] < $de || $r['data'] > $ate) continue;
            if ($rotas !== null && !in_array((string) $r['rota'], $rotas, true)) continue;
            unset($r['historico']);
            $r['n_linhas'] = count($r['linhas']);
            $r['quantidade'] = round(array_sum(array_column($r['linhas'], 'quantidade')), 3);
            $out[] = $r;
        }
    }
    usort($out, function ($a, $b) { return strcmp($b['numero'], $a['numero']); });
    return $out;
}

/**
 * Artigos que se podem requisitar: os que existem nos armazéns (com a quantidade disponível hoje, do Pinguim BO) e,
 * se o stock não estiver disponível, os artigos já requisitados ou conciliados (sem disponível).
 */
function naval_req_catalogo(): array
{
    $art = [];
    try {
        naval_servico('stock');
        $d = naval_stock(date('Y-m-d'), '');
        $principais = naval_req_armazens_principais();
        foreach ((array) ($d['armazens'] ?? []) as $a) {
            // só o armazém principal (nunca os armazéns das carrinhas nem outros armazéns)
            if (!isset($principais[(string) ($a['cod_armazem'] ?? '')])) continue;
            foreach ((array) ($a['itens'] ?? []) as $i) {
                $c = (string) ($i['codigo'] ?? '');
                if ($c === '') continue;
                $art[$c] = ['codigo' => $c, 'artigo' => (string) ($i['produto'] ?? $c), 'armazem' => (string) ($a['armazem'] ?? ''),
                    'disponivel' => round(($art[$c]['disponivel'] ?? 0) + (float) ($i['quantidade'] ?? 0), 3)];
            }
        }
    } catch (Throwable $e) {
        error_log('[requisicoes catalogo] ' . $e->getMessage());
    }
    if (!$art) {
        foreach ([date('Y'), (string) ((int) date('Y') - 1)] as $ano) {
            foreach ((array) glob(naval_req_pasta($ano) . '/RQ-*.json') as $f) {
                foreach ((array) (naval_json_ler((string) $f)['linhas'] ?? []) as $l) {
                    $art[$l['codigo']] = $art[$l['codigo']] ?? ['codigo' => $l['codigo'], 'artigo' => $l['artigo'], 'unidade' => $l['unidade'], 'disponivel' => null];
                }
            }
        }
    }
    uasort($art, function ($a, $b) { return strcmp($a['artigo'], $b['artigo']); });
    return array_values($art);
}

/** Armazéns principais (os do ficheiro para o SAP: 1150 alimentar e 1151 não alimentar, por omissão). */
function naval_req_armazens_principais(): array
{
    naval_servico('sap');
    $a = (array) (naval_sap_config()['armazens'] ?? []);
    return $a ?: NAVAL_ARMAZENS_PRINCIPAIS;
}

/**
 * Stock de cada artigo no armazém principal {código: quantidade}, ou null se não se souber (sem ligação ao Pinguim
 * BO): nesse caso não se bloqueia a operação, mas o ecrã avisa. $GLOBALS['naval_req_stock'] substitui-o nos testes.
 */
function naval_req_stock_principal(): ?array
{
    if (array_key_exists('naval_req_stock', $GLOBALS)) return $GLOBALS['naval_req_stock'];
    static $m = null;
    if ($m !== null) return $m ?: null;
    $m = [];
    foreach (naval_req_catalogo() as $a) if ($a['disponivel'] !== null) $m[(string) $a['codigo']] = (float) $a['disponivel'];
    return $m ?: null;
}

/** Nunca mais do que o stock do armazém principal (ao criar, gravar e emitir). */
function naval_req_validar_stock(array $linhas): void
{
    $stock = naval_req_stock_principal();
    if ($stock === null) return;
    $acima = [];
    foreach ($linhas as $l) {
        $q = (float) ($l['quantidade'] ?? 0);
        $s = max(0.0, (float) ($stock[(string) $l['codigo']] ?? 0));
        if ($q > $s + 0.0005) $acima[] = $l['artigo'] . ' (' . rtrim(rtrim(number_format($q, 3, ',', ' '), '0'), ',') . ' pedidos, ' . rtrim(rtrim(number_format($s, 3, ',', ' '), '0'), ',') . ' em stock)';
    }
    if ($acima) throw new NavalErro('Quantidade acima do stock do armazém principal: ' . implode('; ', $acima) . '.', 400, ['codigo' => 'stock']);
}

/** Confirma a carga de uma requisição emitida: quantidade entregue por artigo (por omissão, a requisitada). */
function naval_req_confirmar(string $id, array $entregue, string $login): array
{
    $r = naval_exclusivo('requisicao-' . $id, function () use ($id, $entregue, $login) {
        $r = naval_req_ler($id);
        if (!$r) throw new NavalErro('Requisição não encontrada.', 404);
        if ($r['estado'] === 'carregada') throw new NavalErro('A carga desta requisição já foi confirmada.', 409);
        if ($r['estado'] !== 'emitida') throw new NavalErro('Só se confirma a carga de uma requisição emitida.', 409);
        if (naval_config('CARRINHA_SEGREGACAO', '1') !== '0' && strcasecmp((string) $r['emitida_por'], $login) === 0) {
            throw new NavalErro('Segregação de funções: quem emitiu a requisição não confirma a carga.', 403);
        }
        foreach ($r['linhas'] as &$l) {
            $q = $entregue[$l['codigo']] ?? $l['quantidade'];
            if (!is_numeric($q) || (float) $q < 0 || (float) $q > 1000000) throw new NavalErro('Quantidade entregue inválida no artigo ' . $l['codigo'] . '.', 400);
            $l['entregue'] = round((float) $q, 3);
        }
        unset($l);
        $r['estado'] = 'carregada';
        $r['confirmado_por'] = $login;
        $r['confirmado_em'] = date('Y-m-d H:i:s');
        $r['versao'] = (int) $r['versao'] + 1;
        $dif = count(array_filter($r['linhas'], function ($l) { return abs($l['entregue'] - $l['quantidade']) > 0.0001; }));
        naval_req_hist($r, 'carga_confirmada', $login, ['diferencas' => $dif]);
        naval_req_gravar_ficheiro($r);
        return $r;
    });
    naval_registar('requisicao_carregada', ['numero' => $id, 'rota' => $r['rota']]);
    return $r;
}

/**
 * Requisições para o stock das carrinhas: por rota, as emitidas por carregar (de qualquer dia até à data) e as
 * carregadas nesse dia. {rota: [{numero, estado, linhas, emitida_em, confirmado_em, confirmado_por}]}.
 */
function naval_req_carga(string $data, ?array $rotas): array
{
    $out = [];
    $anos = array_unique([substr($data, 0, 4), (string) ((int) substr($data, 0, 4) - 1)]);
    foreach ($anos as $ano) {
        foreach ((array) glob(naval_req_pasta($ano) . '/RQ-*.json') as $f) {
            $r = naval_json_ler((string) $f);
            if (!$r || ($rotas !== null && !in_array((string) $r['rota'], $rotas, true))) continue;
            $pendente = $r['estado'] === 'emitida' && substr((string) $r['emitida_em'], 0, 10) <= $data;
            $carregada = $r['estado'] === 'carregada' && substr((string) $r['confirmado_em'], 0, 10) === $data;
            if (!$pendente && !$carregada) continue;
            $out[(string) $r['rota']][] = ['numero' => $r['numero'], 'estado' => $r['estado'], 'linhas' => $r['linhas'], 'emitida_em' => $r['emitida_em'],
                'emitida_por' => $r['emitida_por'], 'confirmado_em' => $r['confirmado_em'] ?? null, 'confirmado_por' => $r['confirmado_por'] ?? null];
        }
    }
    foreach ($out as &$l) usort($l, function ($a, $b) { return strcmp($a['numero'], $b['numero']); });
    unset($l);
    return $out;
}
