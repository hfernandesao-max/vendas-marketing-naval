<?php
/**
 * Ficheiro de vendas RTM para o SAP (modelo «RTM_Sales_SAP_Upload_Format»).
 *
 * O Excel é gerado no portal a partir das vendas do Pinguim BO; aqui guardam-se, em dados-privados/sap/:
 *   config.json   correspondência de cada rota com o SAP (Loja, SAP location, empregado de vendas)
 *   lotes.json    lotes gerados (quem, quando, período, documentos, totais)
 *   lote-N.xlsx   Excel de cada lote
 * Os documentos já incluídos num lote ficam marcados, para não serem carregados duas vezes no SAP.
 */
defined('NAVAL_NUCLEO') || exit;

// Configuração por omissão: o exemplo do modelo (rota 1011 → loja «1011 - FHR PIV», SAP location RTM2)
const NAVAL_SAP_OMISSAO = ['prefixo' => 'FHR PIV', 'moeda' => 'AOA', 'unidade' => 'CAR', 'rotas' => ['1011' => ['local' => 'RTM2']]];
const NAVAL_SAP_MODELO = '/reports/modelos/RTM_Sales_SAP_Upload_Format.xlsx';

function naval_sap_pasta(): string
{
    return naval_pasta('sap');
}

function naval_sap_config(): array
{
    return naval_json_ler(naval_sap_pasta() . '/config.json', NAVAL_SAP_OMISSAO);
}

function naval_sap_lotes(): array
{
    return naval_json_ler(naval_sap_pasta() . '/lotes.json');
}

/** Resumo para a página: configuração, lotes (mais recentes primeiro, sem a lista de documentos) e documentos já exportados. */
function naval_sap_resumo(): array
{
    $lotes = naval_sap_lotes();
    $exportados = [];
    foreach ($lotes as $l) foreach ($l['documentos'] as $doc) if (!isset($exportados[$doc])) $exportados[$doc] = ['lote' => $l['numero'], 'em' => $l['em']];
    $resumo = array_map(function ($l) { unset($l['documentos']); return $l; }, array_reverse($lotes));
    return ['config' => naval_sap_config(), 'lotes' => array_slice($resumo, 0, 200), 'exportados' => $exportados];
}

/** Lote N (dados e caminho do Excel); lança NavalErro 404 se não existir. */
function naval_sap_lote(int $n): array
{
    $f = naval_sap_pasta() . '/lote-' . $n . '.xlsx';
    if ($n < 1 || !is_file($f)) throw new NavalErro('Ficheiro não encontrado.', 404);
    $lote = null;
    foreach (naval_sap_lotes() as $l) if ((int) $l['numero'] === $n) $lote = $l;
    return ['ficheiro' => $f, 'nome' => $lote['ficheiro'] ?? ('RTM_Sales_SAP_Upload_lote_' . $n . '.xlsx')];
}

/** Grava a configuração (só valores limpos; rotas sem nenhum valor são retiradas). */
function naval_sap_gravar_config(array $c): array
{
    $nova = ['prefixo' => naval_texto($c['prefixo'] ?? 'FHR PIV', 40), 'moeda' => strtoupper(naval_texto($c['moeda'] ?? 'AOA', 5)), 'unidade' => strtoupper(naval_texto($c['unidade'] ?? 'CAR', 10)), 'rotas' => []];
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

/** Regista um lote novo com o Excel gerado no portal. Devolve o lote (sem a lista de documentos). */
function naval_sap_novo_lote(array $q, string $xlsx, array $eu): array
{
    $docs = array_values(array_unique(array_filter(array_map(function ($d) { return naval_texto($d, 60); }, (array) ($q['documentos'] ?? [])))));
    if (!$docs) throw new NavalErro('O ficheiro não tem documentos.');
    if ($xlsx === '' || substr($xlsx, 0, 2) !== 'PK' || strlen($xlsx) > 10 * 1024 * 1024) throw new NavalErro('O Excel não chegou ao servidor. Tente de novo.');
    $lotes = naval_sap_lotes();
    $n = $lotes ? max(array_column($lotes, 'numero')) + 1 : 1;
    $de = (string) ($q['de'] ?? '');
    $ate = (string) ($q['ate'] ?? '');
    $soData = function ($s) { return (string) preg_replace('/[^0-9-]/', '', $s); };
    $nome = 'RTM_Sales_SAP_Upload_' . $soData($de) . ($ate !== $de ? '_a_' . $soData($ate) : '') . '_lote' . $n . '.xlsx';
    naval_ficheiro_gravar(naval_sap_pasta() . '/lote-' . $n . '.xlsx', $xlsx);
    $lote = [
        'numero' => $n, 'em' => date('Y-m-d H:i:s'), 'por' => $eu['login'], 'por_nome' => $eu['nome'] ?? $eu['login'],
        'de' => $de, 'ate' => $ate, 'rotas' => array_values(array_map('strval', (array) ($q['rotas'] ?? []))),
        'n_documentos' => count($docs), 'linhas' => (int) ($q['linhas'] ?? 0), 'total' => round((float) ($q['total'] ?? 0), 2),
        'repetidos' => (int) ($q['repetidos'] ?? 0), 'ficheiro' => $nome, 'documentos' => $docs,
    ];
    $lotes[] = $lote;
    naval_json_gravar(naval_sap_pasta() . '/lotes.json', $lotes);
    naval_registar('sap_lote', ['lote' => $n, 'documentos' => count($docs), 'total' => $lote['total'], 'periodo' => $de . ' a ' . $ate]);
    unset($lote['documentos']);
    return $lote;
}
