<?php
/**
 * Ordens do portal ao Pinguim BO (padrão «transactional outbox»): o portal ordena e o documento nasce no Pinguim BO,
 * software certificado (numeração, assinatura e movimento de stock dele). O portal nunca escreve nas tabelas do
 * Pinguim BO.
 *
 * Quem executa cada ordem (ORDENS_PINGUIM_MODO):
 *   robo    o robô do portal (ferramentas/robo-pinguim.mjs) abre o Pinguim BO no navegador com uma conta técnica, cria
 *           o documento pelos ecrãs do próprio Pinguim BO e devolve o número (v1/robo/ordens, chave ROBO_PINGUIM_CHAVE);
 *   manual  (omissão, até o robô estar configurado) o Back-Office cria o documento no Pinguim BO com os dados da ordem.
 * Nos dois casos o portal confirma sozinho: procura o documento no Pinguim BO (SQL, só SELECT) e compara as linhas com
 * a ordem; igual → confirmada (a requisição fica «transferida» e a reserva cai); diferente → divergente.
 *
 * Estados: por_executar → em_execucao (robô, com prazo) → gerada (número conhecido) → confirmada
 *          recusada (erro de negócio: volta ao Back-Office com o motivo) · divergente · cancelada
 * Idempotência: uma ordem por requisição e tipo (chave «PO-AAAA-NNNNNN/guia»); o robô verifica no Pinguim BO se o
 * documento já existe antes de o criar (nunca dois documentos para a mesma ordem).
 *
 * Ficheiros (fora da pasta pública): dados-privados/ordens-pinguim/<id>.json.
 */
defined('NAVAL_NUCLEO') || exit;

const NAVAL_ORD_ESTADOS = ['por_executar', 'em_execucao', 'gerada', 'confirmada', 'recusada', 'divergente', 'cancelada'];
const NAVAL_ORD_ESPERA = [60, 300, 900, 3600];   // segundos entre tentativas do robô depois de uma falha técnica

function naval_ord_pasta(): string
{
    return naval_pasta('ordens-pinguim');
}

function naval_ord_id_valido(string $id): bool
{
    return (bool) preg_match('/^GUIA-(RQ|PO)-\d{4}-\d{6}$/', $id);
}

function naval_ord_ler(string $id): ?array
{
    if (!naval_ord_id_valido($id)) throw new NavalErro('Ordem inválida.', 400);
    return naval_json_ler(naval_ord_pasta() . '/' . $id . '.json') ?: null;
}

function naval_ord_gravar(array $o): void
{
    naval_json_gravar(naval_ord_pasta() . '/' . $o['id'] . '.json', $o);
}

function naval_ord_hist(array &$o, string $accao, string $por, array $extra = []): void
{
    $o['historico'][] = ['em' => date('Y-m-d H:i:s'), 'por' => $por, 'accao' => $accao] + $extra;
}

function naval_ord_modo(): string
{
    return naval_config('ORDENS_PINGUIM_MODO', 'manual') === 'robo' ? 'robo' : 'manual';
}

/** Armazém da viatura no Pinguim BO: o confirmado em Stock por carrinha ou, por omissão, 100010NN. */
function naval_ord_armazem_viatura(string $rota): string
{
    naval_servico('stock');
    $c = naval_stock_carrinhas_confirmadas()[$rota]['codigo'] ?? '';
    return $c !== '' ? (string) $c : '1000' . $rota;
}

/** Ordem da Guia de Remessa de uma requisição validada (se já existir, devolve-a: idempotente). */
function naval_ord_da_requisicao(array $r, string $login): array
{
    $id = 'GUIA-' . $r['numero'];
    return naval_exclusivo('ordem-' . $id, function () use ($id, $r, $login) {
        $o = naval_ord_ler($id);
        if ($o) return $o;
        naval_servico('requisicoes');
        $arm = naval_req_armazem_artigos();
        $linhas = [];
        foreach ($r['linhas'] as $l) {
            $q = (float) ($l['entregue'] ?? $l['quantidade']);
            if ($q <= 0) continue;
            $linhas[] = ['codigo' => (string) $l['codigo'], 'artigo' => (string) $l['artigo'], 'unidade' => (string) ($l['unidade'] ?? ''), 'quantidade' => round($q, 3),
                'armazem_origem' => (string) ($arm[(string) $l['codigo']] ?? '')];
        }
        $agora = date('Y-m-d H:i:s');
        $o = ['id' => $id, 'chave' => $r['numero'] . '/guia', 'tipo' => 'guia_remessa', 'requisicao' => $r['numero'], 'rota' => (string) $r['rota'],
            'promotor' => (string) ($r['promotor'] ?? ''), 'data' => substr((string) ($r['confirmado_em'] ?? $agora), 0, 10),
            'armazem_destino' => naval_ord_armazem_viatura((string) $r['rota']), 'referencia' => $r['numero'], 'linhas' => $linhas,
            'modo' => naval_ord_modo(), 'estado' => $linhas ? 'por_executar' : 'cancelada', 'tentativas' => 0, 'proxima' => $agora,
            'documento' => null, 'erro' => $linhas ? null : 'Nada carregado: sem guia.', 'criada_por' => $login, 'criada_em' => $agora, 'historico' => []];
        naval_ord_hist($o, 'criada', $login, ['linhas' => count($linhas), 'modo' => $o['modo']]);
        naval_ord_gravar($o);
        naval_registar('ordem_pinguim_criada', ['id' => $id, 'rota' => $o['rota'], 'linhas' => count($linhas), 'modo' => $o['modo']], $login);
        return $o;
    });
}

/** Ordens (as mais recentes primeiro), no âmbito das rotas; $estados vazio = todas. */
function naval_ord_listar(?array $rotas, array $estados = [], int $dias = 30): array
{
    $desde = date('Y-m-d', time() - $dias * 86400);
    $out = [];
    foreach ((array) glob(naval_ord_pasta() . '/GUIA-*.json') as $f) {
        $o = naval_json_ler((string) $f);
        if (!$o || ($rotas !== null && !in_array((string) $o['rota'], $rotas, true))) continue;
        if ($estados && !in_array($o['estado'], $estados, true)) continue;
        if ($o['data'] < $desde && !in_array($o['estado'], ['por_executar', 'em_execucao', 'gerada', 'recusada', 'divergente'], true)) continue;
        $out[] = $o;
    }
    usort($out, function ($a, $b) { return strcmp($b['criada_em'], $a['criada_em']) ?: strcmp($b['id'], $a['id']); });
    return $out;
}

/** Muda uma ordem dentro do bloqueio dela. */
function naval_ord_alterar(string $id, callable $f): array
{
    return naval_exclusivo('ordem-' . $id, function () use ($id, $f) {
        $o = naval_ord_ler($id);
        if (!$o) throw new NavalErro('Ordem não encontrada.', 404);
        $o = $f($o);
        naval_ord_gravar($o);
        return $o;
    });
}

/**
 * Robô: entrega a próxima ordem a executar (a mais antiga por executar, ou em execução com o prazo esgotado) e
 * reserva-a por $prazo segundos. null se não houver nenhuma.
 */
function naval_ord_reclamar(string $robo, ?int $agora = null, int $prazo = 600): ?array
{
    $agora = $agora ?? time();
    $agoraTxt = date('Y-m-d H:i:s', $agora);
    $cands = array_values(array_filter(naval_ord_listar(null, ['por_executar', 'em_execucao'], 60), function ($o) use ($agoraTxt) {
        if (($o['modo'] ?? 'manual') !== 'robo') return false;
        if ($o['estado'] === 'em_execucao') return ($o['prazo'] ?? '') < $agoraTxt;
        return ($o['proxima'] ?? '') <= $agoraTxt;
    }));
    usort($cands, function ($a, $b) { return strcmp($a['criada_em'], $b['criada_em']); });
    foreach ($cands as $c) {
        try {
            return naval_ord_alterar($c['id'], function ($o) use ($robo, $agora, $agoraTxt, $prazo) {
                if (!in_array($o['estado'], ['por_executar', 'em_execucao'], true)) throw new NavalErro('ocupada', 409);
                if ($o['estado'] === 'em_execucao' && ($o['prazo'] ?? '') >= $agoraTxt) throw new NavalErro('ocupada', 409);
                $o['estado'] = 'em_execucao';
                $o['executor'] = $robo;
                $o['prazo'] = date('Y-m-d H:i:s', $agora + $prazo);
                $o['tentativas'] = (int) $o['tentativas'] + 1;
                naval_ord_hist($o, 'robo_iniciou', $robo, ['tentativa' => $o['tentativas']]);
                return $o;
            });
        } catch (NavalErro $e) {
            continue;
        }
    }
    return null;
}

/**
 * Resultado do robô: ok com o número do documento → gerada; erro de negócio (sem stock, artigo inactivo…) →
 * recusada, volta ao Back-Office; erro técnico → volta à fila com espera crescente (até 4 tentativas; depois recusada).
 */
function naval_ord_resultado(string $id, string $robo, bool $ok, string $documento = '', string $erro = '', string $tipoErro = 'tecnico', ?int $agora = null): array
{
    $agora = $agora ?? time();
    return naval_ord_alterar($id, function ($o) use ($robo, $ok, $documento, $erro, $tipoErro, $agora) {
        if ($o['estado'] !== 'em_execucao' || ($o['executor'] ?? '') !== $robo) throw new NavalErro('Esta ordem não está a ser executada por este robô.', 409);
        unset($o['prazo']);
        if ($ok) {
            $doc = naval_texto($documento, 60);
            if ($doc === '') throw new NavalErro('Falta o número do documento gerado.', 400);
            $o['estado'] = 'gerada';
            $o['documento'] = ['numero' => $doc, 'id' => null, 'por' => $robo, 'em' => date('Y-m-d H:i:s', $agora)];
            $o['erro'] = null;
            naval_ord_hist($o, 'documento_gerado', $robo, ['documento' => $doc]);
            return $o;
        }
        $o['erro'] = naval_texto($erro, 300) ?: 'Erro sem descrição.';
        if ($tipoErro === 'negocio' || (int) $o['tentativas'] >= count(NAVAL_ORD_ESPERA)) {
            $o['estado'] = 'recusada';
            naval_ord_hist($o, 'recusada', $robo, ['erro' => $o['erro']]);
        } else {
            $o['estado'] = 'por_executar';
            $o['proxima'] = date('Y-m-d H:i:s', $agora + NAVAL_ORD_ESPERA[max(0, (int) $o['tentativas'] - 1)]);
            naval_ord_hist($o, 'falha_tecnica', $robo, ['erro' => $o['erro'], 'nova_tentativa' => $o['proxima']]);
        }
        return $o;
    });
}

/** Back-Office: a ordem foi executada à mão no Pinguim BO (modo manual ou recusada): fica gerada com o número. */
function naval_ord_executada_manual(string $id, string $documento, string $login): array
{
    $doc = naval_texto($documento, 60);
    if (mb_strlen($doc) < 3) throw new NavalErro('Indique o número do documento criado no Pinguim BO.', 400);
    return naval_ord_alterar($id, function ($o) use ($doc, $login) {
        if (!in_array($o['estado'], ['por_executar', 'recusada', 'divergente'], true)) throw new NavalErro('Esta ordem não está à espera de execução.', 409);
        $o['estado'] = 'gerada';
        $o['documento'] = ['numero' => $doc, 'id' => null, 'por' => $login, 'em' => date('Y-m-d H:i:s')];
        $o['erro'] = null;
        naval_ord_hist($o, 'executada_manual', $login, ['documento' => $doc]);
        naval_registar('ordem_pinguim_manual', ['id' => $o['id'], 'documento' => $doc], $login);
        return $o;
    });
}

/** Back-Office: pôr uma ordem recusada ou divergente outra vez na fila (depois de corrigir a causa). */
function naval_ord_repetir(string $id, string $login): array
{
    return naval_ord_alterar($id, function ($o) use ($login) {
        if (!in_array($o['estado'], ['recusada', 'divergente'], true)) throw new NavalErro('Só se repete uma ordem recusada ou divergente.', 409);
        $o['estado'] = 'por_executar';
        $o['modo'] = naval_ord_modo();
        $o['tentativas'] = 0;
        $o['proxima'] = date('Y-m-d H:i:s');
        naval_ord_hist($o, 'repetida', $login);
        return $o;
    });
}

/** Back-Office: cancelar uma ordem que não vai ser executada (com motivo; a requisição volta a conferida). */
function naval_ord_cancelar(string $id, string $motivo, string $login): array
{
    $m = naval_texto($motivo, 300);
    if (mb_strlen($m) < 5) throw new NavalErro('Indique o motivo do cancelamento.', 400);
    return naval_ord_alterar($id, function ($o) use ($m, $login) {
        if (in_array($o['estado'], ['confirmada', 'cancelada', 'em_execucao'], true)) throw new NavalErro('Esta ordem já não se pode cancelar.', 409);
        $o['estado'] = 'cancelada';
        $o['erro'] = $m;
        naval_ord_hist($o, 'cancelada', $login, ['motivo' => $m]);
        naval_registar('ordem_pinguim_cancelada', ['id' => $o['id'], 'motivo' => $m], $login);
        return $o;
    });
}

// ── Confirmação pelo Pinguim BO ─────────────────────────────────────────────────────────────────────────────────

/**
 * Procura, entre os documentos do Pinguim BO, o da ordem (função pura, testável). $docs: [{id, numero, rota, em,
 * linhas: {codigo: quantidade}}]; $usados: ids já ligados a outras ordens. Com o número conhecido (gerada) manda o
 * número; sem ele, a mesma rota, a partir do dia da ordem e com as mesmas linhas. Devolve [doc|null, igual: bool].
 */
function naval_ord_casar(array $o, array $docs, array $usados = []): array
{
    $alvo = [];
    foreach ($o['linhas'] as $l) $alvo[(string) $l['codigo']] = round((float) $l['quantidade'], 3);
    ksort($alvo);
    $igual = function (array $d) use ($alvo) {
        $x = array_map(function ($q) { return round(abs((float) $q), 3); }, (array) $d['linhas']);
        ksort($x);
        return $x === $alvo;
    };
    $num = (string) ($o['documento']['numero'] ?? '');
    $norm = function (string $s) { return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $s)); };
    if ($num !== '') {
        foreach ($docs as $d) if ($norm((string) $d['numero']) === $norm($num) || $norm((string) $d['numero']) === $norm(preg_replace('/^.*?\s/', '', $num))) return [$d, $igual($d)];
    }
    foreach ($docs as $d) {
        if (isset($usados[(string) $d['id']]) || (string) $d['rota'] !== (string) $o['rota'] || substr((string) $d['em'], 0, 10) < $o['data']) continue;
        if ($igual($d)) return [$d, true];
    }
    return [null, false];
}

/** Documentos de entrada nas viaturas (guias) desde um dia, lidos do Pinguim BO (só SELECT). null sem ligação. */
function naval_ord_documentos_pinguim(string $desde): ?array
{
    if (!naval_bd_configurada()) return null;
    $tipos = array_values(array_filter(array_map('trim', explode(',', (string) naval_config('ORDENS_PINGUIM_TIPOS', 'GTC')))));
    if (!$tipos) return [];
    $marcas = [];
    $p = [':de' => naval_bd_data($desde)];
    foreach ($tipos as $i => $t) {
        $marcas[] = ':t' . $i;
        $p[':t' . $i] = $t;
    }
    try {
        $rows = naval_bd_consultar("
            SELECT DH.ID, DC.CODE AS TIPO, LTRIM(RTRIM(COALESCE(DH.DOC_PREFIX, ''))) AS PREFIXO, DH.DOC_NUMBER AS NUMERO,
                   CONVERT(VARCHAR(19), COALESCE(DH.CLOCK_DATE, DH.DOC_DATE), 120) AS EM,
                   COALESCE(S.CODE, SUBSTRING(S.DESCRIPTION, 1, 4)) AS ROTA,
                   LTRIM(RTRIM(DMD.PRODUCT_CODE)) AS CODIGO, CAST(SUM(ABS(DMD.QNT)) AS FLOAT) AS QTD
            FROM DOCMOVDETAIL DMD
            INNER JOIN DOCHEADER DH ON DH.ID = DMD.DOCHEADER_ID
            INNER JOIN DOCCONFIG DC ON DC.ID = DH.DOCCONFIG_ID
            LEFT JOIN STORE S ON S.ID = DH.STORE_ID
            WHERE DMD.DELETED = 0 AND DH.DELETED = 0 AND DH.DOC_DATE >= :de AND DC.CODE IN (" . implode(', ', $marcas) . ")
            GROUP BY DH.ID, DC.CODE, DH.DOC_PREFIX, DH.DOC_NUMBER, COALESCE(DH.CLOCK_DATE, DH.DOC_DATE), COALESCE(S.CODE, SUBSTRING(S.DESCRIPTION, 1, 4)),
                     LTRIM(RTRIM(DMD.PRODUCT_CODE))", $p);
    } catch (Throwable $e) {
        error_log('[ordens Pinguim BO SQL] ' . $e->getMessage());
        return null;
    }
    $docs = [];
    foreach ($rows as $r) {
        $id = (string) $r['ID'];
        if (!isset($docs[$id])) {
            $docs[$id] = ['id' => $id, 'numero' => trim($r['TIPO'] . ' ' . trim($r['PREFIXO'] . '/' . $r['NUMERO'], '/')), 'rota' => trim((string) $r['ROTA']),
                'em' => (string) $r['EM'], 'linhas' => []];
        }
        $docs[$id]['linhas'][(string) $r['CODIGO']] = ($docs[$id]['linhas'][(string) $r['CODIGO']] ?? 0) + (float) $r['QTD'];
    }
    return array_values($docs);
}

/**
 * Tarefa de 5 em 5 minutos: confirma pelo Pinguim BO as ordens geradas (e as por executar, caso o documento tenha sido
 * criado à mão sem o número). Devolve {confirmadas, divergentes}.
 */
function naval_ord_tick(?int $agora = null): array
{
    $agora = $agora ?? time();
    $abertas = naval_ord_listar(null, ['por_executar', 'em_execucao', 'gerada', 'recusada'], 30);
    if (!$abertas) return [];
    $desde = min(array_column($abertas, 'data'));
    $docs = naval_ord_documentos_pinguim($desde);
    if ($docs === null) return ['sem_ligacao' => true];
    $usados = [];
    foreach (naval_ord_listar(null, ['confirmada'], 30) as $c) if (!empty($c['documento']['id'])) $usados[(string) $c['documento']['id']] = true;
    $feito = ['confirmadas' => 0, 'divergentes' => 0];
    naval_servico('requisicoes');
    foreach ($abertas as $o) {
        if ($o['estado'] === 'em_execucao') continue;
        [$d, $igual] = naval_ord_casar($o, $docs, $usados);
        if ($d === null) continue;
        $usados[(string) $d['id']] = true;
        $novo = naval_ord_alterar($o['id'], function ($x) use ($d, $igual) {
            $x['documento'] = ['numero' => (string) $d['numero'], 'id' => (string) $d['id'], 'por' => $x['documento']['por'] ?? 'pinguim', 'em' => (string) $d['em']];
            $x['estado'] = $igual ? 'confirmada' : 'divergente';
            $x['erro'] = $igual ? null : 'O documento ' . $d['numero'] . ' no Pinguim BO tem linhas diferentes da carga conferida.';
            naval_ord_hist($x, $igual ? 'confirmada_pinguim' : 'divergente_pinguim', 'sistema', ['documento' => $d['numero']]);
            return $x;
        });
        if ($igual) {
            naval_req_transferida((string) $novo['requisicao'], $novo['documento']);
            $feito['confirmadas']++;
        } else {
            $feito['divergentes']++;
            naval_registar('ordem_pinguim_divergente', ['id' => $novo['id'], 'documento' => $d['numero']], 'sistema');
        }
    }
    return array_filter($feito);
}
