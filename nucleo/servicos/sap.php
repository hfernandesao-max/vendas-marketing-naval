<?php
/**
 * Ficheiro de vendas RTM para o SAP (modelo «RTM_Sales_SAP_Upload_Format»).
 *
 * O Excel é gerado no portal a partir das vendas do Pinguim BO; aqui guardam-se, em dados-privados/sap/:
 *   config.json   correspondência de cada rota com o SAP (Loja, SAP location, empregado de vendas)
 *   lotes.json    lotes gerados (quem, quando, período, documentos, totais)
 *   lote-N.xlsx   Excel de cada lote
 *
 * Controlo de ficheiros emitidos (controlos de interface, boas práticas de auditoria):
 *   - numeração sequencial sem falhas; cada lote é emitido uma única vez e o ficheiro nunca é reescrito;
 *   - um documento só pode estar num lote válido: o servidor recusa lotes com documentos já emitidos;
 *   - impressão digital SHA-256 de cada ficheiro, para provar que o carregado no SAP é o emitido;
 *   - ciclo de vida: emitido → carregado no SAP (com a referência do SAP) ou anulado (motivo obrigatório,
 *     por outra pessoa que não quem o emitiu); só a anulação liberta os documentos para um novo lote;
 *   - quem emite não volta a descarregar o ficheiro; a segunda via fica para a auditoria (TI, AUD),
 *     identificada no nome do ficheiro e registada.
 */
defined('NAVAL_NUCLEO') || exit;

require_once __DIR__ . '/origem-stock.php';

// Configuração por omissão. A SAP storage location de cada venda é determinada automaticamente pela origem
// do stock (armazém principal que abasteceu a viatura); na rota só se indica para a substituir.
// SAP location por omissão: viaturas sem abastecimento registado (avariadas, em manutenção) não bloqueiam o envio.
const NAVAL_SAP_OMISSAO = ['prefixo' => 'FHR PIV', 'moeda' => 'AOA', 'unidade' => 'CAR', 'armazens' => NAVAL_ARMAZENS_PRINCIPAIS, 'local_omissao' => 'RTM2', 'rotas' => []];
const NAVAL_SAP_MODELO = '/reports/modelos/RTM_Sales_SAP_Upload_Format.xlsx';

function naval_sap_pasta(): string
{
    return naval_pasta('sap');
}

function naval_sap_config(): array
{
    $c = naval_json_ler(naval_sap_pasta() . '/config.json', NAVAL_SAP_OMISSAO);
    if (empty($c['armazens'])) $c['armazens'] = NAVAL_ARMAZENS_PRINCIPAIS;
    if (!isset($c['local_omissao'])) $c['local_omissao'] = NAVAL_SAP_OMISSAO['local_omissao'];
    return $c;
}

function naval_sap_lotes(): array
{
    $lotes = naval_json_ler(naval_sap_pasta() . '/lotes.json');
    foreach ($lotes as $i => $l) $lotes[$i] += ['estado' => 'emitido'];   // lotes anteriores ao controlo de estado
    return $lotes;
}

function naval_sap_gravar_lotes(array $lotes): void
{
    naval_json_gravar(naval_sap_pasta() . '/lotes.json', $lotes);
}

/** Documentos em lotes válidos (não anulados): {documento: {lote, em}}. */
function naval_sap_exportados(array $lotes): array
{
    $out = [];
    foreach ($lotes as $l) {
        if ($l['estado'] === 'anulado') continue;
        foreach ($l['documentos'] as $doc) if (!isset($out[$doc])) $out[$doc] = ['lote' => $l['numero'], 'em' => $l['em']];
    }
    return $out;
}

/**
 * Executa $f com exclusão mútua sobre os lotes (dois pedidos ao mesmo tempo não podem emitir o
 * mesmo documento nem o mesmo número).
 * @return mixed
 */
function naval_sap_exclusivo(callable $f)
{
    $h = fopen(naval_sap_pasta() . '/lotes.lock', 'c');
    if (!$h || !flock($h, LOCK_EX)) throw new NavalErro('O registo de lotes está ocupado. Tente de novo.', 503);
    try {
        return $f();
    } finally {
        flock($h, LOCK_UN);
        fclose($h);
    }
}

/** Quem pode pedir a segunda via (auditoria: ver sem emitir) — TI, AUD e o super administrador. */
function naval_sap_pode_segunda_via(): bool
{
    return in_array(naval_nivel('sap-exportar'), ['V', 'A'], true);
}

/** Resumo para a página: configuração, lotes (mais recentes primeiro, sem a lista de documentos) e documentos já exportados. */
function naval_sap_resumo(): array
{
    $lotes = naval_sap_lotes();
    $exportados = naval_sap_exportados($lotes);
    $resumo = array_map(function ($l) { unset($l['documentos']); return $l; }, array_reverse($lotes));
    $config = naval_sap_config();
    return ['config' => $config, 'origens' => naval_origem_stock($config['armazens']), 'lotes' => array_slice($resumo, 0, 200), 'exportados' => $exportados,
        'segunda_via' => naval_sap_pode_segunda_via()];
}

/** Segunda via do lote N (só auditoria): ficheiro original, com o nome marcado como segunda via. */
function naval_sap_segunda_via(int $n): array
{
    if (!naval_sap_pode_segunda_via()) {
        naval_registar('sap_segunda_via_recusada', ['lote' => $n]);
        throw new NavalErro('O ficheiro de um lote só é emitido uma vez. A segunda via é reservada à auditoria (TI e Auditoria); se o ficheiro se perdeu antes de ser carregado no SAP, anule o lote e gere um novo.', 403);
    }
    $f = naval_sap_pasta() . '/lote-' . $n . '.xlsx';
    if ($n < 1 || !is_file($f)) throw new NavalErro('Ficheiro não encontrado.', 404);
    $lote = null;
    foreach (naval_sap_lotes() as $l) if ((int) $l['numero'] === $n) $lote = $l;
    if (!$lote) throw new NavalErro('Ficheiro não encontrado.', 404);
    if (!empty($lote['sha256']) && !hash_equals($lote['sha256'], hash_file('sha256', $f))) {
        naval_registar('sap_ficheiro_alterado', ['lote' => $n]);
        throw new NavalErro('O ficheiro guardado do lote ' . $n . ' não corresponde ao emitido (impressão digital diferente). Avise a TI.', 409);
    }
    naval_registar('sap_segunda_via', ['lote' => $n, 'estado' => $lote['estado']]);
    return ['ficheiro' => $f, 'nome' => 'SEGUNDA_VIA_' . $lote['ficheiro']];
}

/** Lote N e a sua posição na lista; lança NavalErro 404 se não existir. */
function naval_sap_procurar(array $lotes, int $n): int
{
    foreach ($lotes as $i => $l) if ((int) $l['numero'] === $n) return $i;
    throw new NavalErro('Lote ' . $n . ' não encontrado.', 404);
}

/** Confirma que o lote foi carregado no SAP (referência do SAP opcional). */
function naval_sap_confirmar(int $n, string $referencia, array $eu): array
{
    return naval_sap_exclusivo(function () use ($n, $referencia, $eu) {
        $lotes = naval_sap_lotes();
        $i = naval_sap_procurar($lotes, $n);
        if ($lotes[$i]['estado'] !== 'emitido') throw new NavalErro('O lote ' . $n . ' já está ' . $lotes[$i]['estado'] . '.', 409);
        $lotes[$i]['estado'] = 'carregado';
        $lotes[$i]['carregado'] = ['em' => date('Y-m-d H:i:s'), 'por' => $eu['login'], 'por_nome' => $eu['nome'] ?? $eu['login'], 'referencia' => naval_texto($referencia, 60)];
        naval_sap_gravar_lotes($lotes);
        naval_registar('sap_lote_carregado', ['lote' => $n, 'referencia' => $lotes[$i]['carregado']['referencia']]);
        $l = $lotes[$i];
        unset($l['documentos']);
        return $l;
    });
}

/**
 * Anula um lote ainda não carregado no SAP: motivo obrigatório e outra pessoa que não quem o emitiu
 * (princípio dos quatro olhos; o super administrador também pode). Liberta os documentos.
 */
function naval_sap_anular(int $n, string $motivo, array $eu): array
{
    $motivo = naval_texto($motivo, 300);
    if (naval_comprimento($motivo) < 10) throw new NavalErro('Indique o motivo da anulação (pelo menos 10 caracteres).');
    return naval_sap_exclusivo(function () use ($n, $motivo, $eu) {
        $lotes = naval_sap_lotes();
        $i = naval_sap_procurar($lotes, $n);
        $l = $lotes[$i];
        if ($l['estado'] === 'carregado') throw new NavalErro('O lote ' . $n . ' já foi carregado no SAP: a correcção faz-se no SAP (estorno), não no portal.', 409);
        if ($l['estado'] !== 'emitido') throw new NavalErro('O lote ' . $n . ' já está ' . $l['estado'] . '.', 409);
        if ($l['por'] === $eu['login'] && ($eu['perfil'] ?? '') !== 'SADM') {
            throw new NavalErro('A anulação tem de ser feita por outra pessoa que não quem emitiu o lote (controlo de quatro olhos).', 403);
        }
        $lotes[$i]['estado'] = 'anulado';
        $lotes[$i]['anulado'] = ['em' => date('Y-m-d H:i:s'), 'por' => $eu['login'], 'por_nome' => $eu['nome'] ?? $eu['login'], 'motivo' => $motivo];
        naval_sap_gravar_lotes($lotes);
        naval_registar('sap_lote_anulado', ['lote' => $n, 'motivo' => $motivo, 'documentos' => count($l['documentos'])]);
        $r = $lotes[$i];
        unset($r['documentos']);
        return $r;
    });
}


/** Grava a configuração (só valores limpos; rotas sem nenhum valor são retiradas). */
function naval_sap_gravar_config(array $c): array
{
    $nova = ['prefixo' => naval_texto($c['prefixo'] ?? 'FHR PIV', 40), 'moeda' => strtoupper(naval_texto($c['moeda'] ?? 'AOA', 5)), 'unidade' => strtoupper(naval_texto($c['unidade'] ?? 'CAR', 10)),
        'armazens' => [], 'local_omissao' => strtoupper(naval_texto($c['local_omissao'] ?? NAVAL_SAP_OMISSAO['local_omissao'], 20)), 'rotas' => []];
    if ($nova['local_omissao'] === '' || in_array($nova['local_omissao'], ['N/A', 'NA', '-'], true)) {
        throw new NavalErro('Indique uma SAP location por omissão válida no SAP (por exemplo RTM2): «N/A» seria recusado no carregamento.');
    }
    // Armazéns principais → SAP location (código do armazém no Pinguim BO, nome e localização no SAP)
    foreach ((array) ($c['armazens'] ?? NAVAL_ARMAZENS_PRINCIPAIS) as $cod => $a) {
        $cod = naval_texto($cod, 20);
        $local = strtoupper(naval_texto($a['local'] ?? '', 20));
        if ($cod === '' || $local === '') continue;
        $nova['armazens'][$cod] = ['nome' => naval_texto($a['nome'] ?? '', 80), 'local' => $local];
    }
    if (!$nova['armazens']) throw new NavalErro('Indique pelo menos um armazém principal com a SAP location.');
    foreach ((array) ($c['rotas'] ?? []) as $rota => $m) {
        $rota = naval_texto($rota, 20);
        if ($rota === '') continue;
        $x = ['local' => strtoupper(naval_texto($m['local'] ?? '', 20)), 'loja' => naval_texto($m['loja'] ?? '', 60), 'empregado' => naval_texto($m['empregado'] ?? '', 120)];
        $x = array_filter($x, function ($v) { return $v !== ''; });
        if ($x) $nova['rotas'][$rota] = $x;
    }
    naval_json_gravar(naval_sap_pasta() . '/config.json', $nova);
    naval_registar('sap_config', ['rotas' => count($nova['rotas'])]);
    return $nova;
}

/**
 * Emite um lote novo com o Excel gerado no portal. Recusa (409) se algum documento já estiver num
 * lote válido. Devolve o lote (sem a lista de documentos).
 */
function naval_sap_novo_lote(array $q, string $xlsx, array $eu): array
{
    $docs = array_values(array_unique(array_filter(array_map(function ($d) { return naval_texto($d, 60); }, (array) ($q['documentos'] ?? [])))));
    if (!$docs) throw new NavalErro('O ficheiro não tem documentos.');
    if ($xlsx === '' || substr($xlsx, 0, 2) !== 'PK' || strlen($xlsx) > 10 * 1024 * 1024) throw new NavalErro('O Excel não chegou ao servidor. Tente de novo.');
    return naval_sap_exclusivo(function () use ($q, $docs, $xlsx, $eu) {
        $lotes = naval_sap_lotes();
        $emitidos = naval_sap_exportados($lotes);
        $dup = array_values(array_filter($docs, function ($d) use ($emitidos) { return isset($emitidos[$d]); }));
        if ($dup) {
            naval_registar('sap_lote_recusado', ['motivo' => 'documentos_ja_emitidos', 'documentos' => array_slice($dup, 0, 20)]);
            $lotesDup = array_values(array_unique(array_map(function ($d) use ($emitidos) { return $emitidos[$d]['lote']; }, $dup)));
            throw new NavalErro(count($dup) . ' documento(s) já foram emitidos para o SAP (lote ' . implode(', ', $lotesDup) . ') e não podem ser emitidos outra vez. Actualize a página.', 409,
                ['duplicados' => array_slice($dup, 0, 50)]);
        }
        $n = $lotes ? max(array_column($lotes, 'numero')) + 1 : 1;
        $f = naval_sap_pasta() . '/lote-' . $n . '.xlsx';
        if (is_file($f)) throw new NavalErro('Já existe um ficheiro com o número ' . $n . '. Avise a TI.', 409);   // nunca reescrever um ficheiro emitido
        $de = (string) ($q['de'] ?? '');
        $ate = (string) ($q['ate'] ?? '');
        $soData = function ($s) { return (string) preg_replace('/[^0-9-]/', '', $s); };
        $nome = 'RTM_Sales_SAP_Upload_' . $soData($de) . ($ate !== $de ? '_a_' . $soData($ate) : '') . '_lote' . $n . '.xlsx';
        naval_ficheiro_gravar($f, $xlsx);
        $lote = [
            'numero' => $n, 'estado' => 'emitido', 'em' => date('Y-m-d H:i:s'), 'por' => $eu['login'], 'por_nome' => $eu['nome'] ?? $eu['login'],
            'de' => $de, 'ate' => $ate, 'rotas' => array_values(array_map('strval', (array) ($q['rotas'] ?? []))),
            'n_documentos' => count($docs), 'linhas' => (int) ($q['linhas'] ?? 0), 'total' => round((float) ($q['total'] ?? 0), 2),
            'ficheiro' => $nome, 'bytes' => strlen($xlsx), 'sha256' => hash('sha256', $xlsx), 'documentos' => $docs,
        ];
        // Excepção registada: linhas que seguiram com a SAP location por omissão (viatura sem abastecimento)
        $om = (array) ($q['omissao'] ?? []);
        if ((int) ($om['linhas'] ?? 0) > 0) {
            $lote['omissao'] = ['linhas' => (int) $om['linhas'], 'rotas' => array_values(array_map('strval', (array) ($om['rotas'] ?? []))),
                'local' => naval_texto($om['local'] ?? '', 20)];
        }
        $lotes[] = $lote;
        naval_sap_gravar_lotes($lotes);
        naval_registar('sap_lote', ['lote' => $n, 'documentos' => count($docs), 'total' => $lote['total'], 'periodo' => $de . ' a ' . $ate, 'sha256' => $lote['sha256']]
            + (isset($lote['omissao']) ? ['location_por_omissao' => $lote['omissao']['linhas'] . ' linhas (rotas ' . implode(', ', $lote['omissao']['rotas']) . ')'] : []));
        unset($lote['documentos']);
        return $lote;
    });
}
