<?php
/**
 * Ficheiro de vendas RTM para o SAP (modelo «RTM_Sales_SAP_Upload_Format»).
 *
 * O Excel é gerado no portal a partir das vendas do Pinguim BO; este serviço guarda:
 *   - a correspondência de cada rota com o SAP (Loja, SAP location, empregado de vendas)
 *   - os lotes gerados (quem, quando, período, documentos, totais) e o Excel de cada lote
 *   - os documentos já incluídos num ficheiro, para não serem carregados duas vezes no SAP
 * Ficheiros em dados-privados/sap/.
 *
 * GET               configuração, lotes e documentos já exportados
 * GET ?xlsx=N       Excel do lote N
 * POST {accao: config | lote, csrf, ...}   nível E (Financeiro e Back-office)
 */
ini_set('display_errors', '0');
header('Cache-Control: no-store');
require_once __DIR__ . '/../../geo/auth/auth_check.php';
naval_exigir(['sap-exportar']);

function resp(array $d, int $codigo = 200): void
{
    http_response_code($codigo);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}

function sap_pasta(): string
{
    $d = naval_pasta_privada() . '/sap';
    if (!is_dir($d)) @mkdir($d, 0770, true);
    return $d;
}

function sap_ler(string $nome, array $omissao): array
{
    $f = sap_pasta() . '/' . $nome;
    $d = is_file($f) ? json_decode((string) file_get_contents($f), true) : null;
    return is_array($d) ? $d : $omissao;
}

function sap_gravar(string $nome, array $d): void
{
    file_put_contents(sap_pasta() . '/' . $nome, json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

function t80($v, int $max = 80): string
{
    $s = trim(preg_replace('/\s+/u', ' ', (string) $v));
    return preg_replace('/^(.{0,' . $max . '}).*$/us', '$1', $s);
}

// Configuração por omissão: o exemplo do modelo (rota 1011 → loja «1011 - FHR PIV», SAP location RTM2)
$CONFIG_OMISSAO = ['prefixo' => 'FHR PIV', 'moeda' => 'AOA', 'unidade' => 'CAR', 'rotas' => ['1011' => ['local' => 'RTM2']]];
$podeGerar = naval_pode_exportar('sap-exportar');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if (isset($_GET['modelo'])) {
        // Modelo original do SAP: o portal escreve os dados por cima, mantendo formatação, tema e larguras
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        readfile(__DIR__ . '/../modelos/RTM_Sales_SAP_Upload_Format.xlsx');
        exit;
    }
    if (isset($_GET['xlsx'])) {
        $n = (int) $_GET['xlsx'];
        $f = sap_pasta() . '/lote-' . $n . '.xlsx';
        if ($n < 1 || !is_file($f)) resp(['erro' => 'Ficheiro não encontrado.'], 404);
        $lote = null;
        foreach (sap_ler('lotes.json', []) as $l) if ((int) $l['numero'] === $n) $lote = $l;
        naval_registar('exportacao', ['pagina' => 'sap-exportar', 'lote' => $n]);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . ($lote['ficheiro'] ?? ('RTM_Sales_SAP_Upload_lote_' . $n . '.xlsx')) . '"');
        readfile($f);
        exit;
    }
    $lotes = sap_ler('lotes.json', []);
    $exportados = [];
    foreach ($lotes as $l) foreach ($l['documentos'] as $doc) if (!isset($exportados[$doc])) $exportados[$doc] = ['lote' => $l['numero'], 'em' => $l['em']];
    $resumo = array_map(function ($l) { unset($l['documentos']); return $l; }, array_reverse($lotes));
    resp(['config' => sap_ler('config.json', $CONFIG_OMISSAO), 'lotes' => array_slice($resumo, 0, 200), 'exportados' => $exportados, 'pode_gerar' => $podeGerar]);
}

$q = json_decode((string) file_get_contents('php://input'), true) ?: [];
if (!hash_equals((string) ($_SESSION['csrf'] ?? ''), (string) ($q['csrf'] ?? ''))) resp(['ok' => false, 'erro' => 'Pedido inválido. Actualize a página e tente de novo.'], 403);
if (!$podeGerar) {
    naval_registar('alteracao_recusada', ['accao' => 'sap_' . ($q['accao'] ?? ''), 'motivo' => 'perfil']);
    resp(['ok' => false, 'erro' => 'O seu perfil não permite gerar o ficheiro para o SAP.'], 403);
}
$eu = naval_utilizador();

switch ($q['accao'] ?? '') {
    case 'config':
        $c = (array) ($q['config'] ?? []);
        $nova = ['prefixo' => t80($c['prefixo'] ?? 'FHR PIV', 40), 'moeda' => strtoupper(t80($c['moeda'] ?? 'AOA', 5)), 'unidade' => strtoupper(t80($c['unidade'] ?? 'CAR', 10)), 'rotas' => []];
        foreach ((array) ($c['rotas'] ?? []) as $rota => $m) {
            $rota = t80($rota, 20);
            if ($rota === '') continue;
            $x = ['local' => strtoupper(t80($m['local'] ?? '', 20)), 'loja' => t80($m['loja'] ?? '', 60), 'empregado' => t80($m['empregado'] ?? '', 120)];
            $x = array_filter($x, function ($v) { return $v !== ''; });
            if ($x) $nova['rotas'][$rota] = $x;
        }
        sap_gravar('config.json', $nova);
        naval_registar('sap_config', ['rotas' => count($nova['rotas'])]);
        resp(['ok' => true, 'config' => $nova]);

    case 'lote':
        $docs = array_values(array_unique(array_filter(array_map(function ($d) { return t80($d, 60); }, (array) ($q['documentos'] ?? [])))));
        if (!$docs) resp(['ok' => false, 'erro' => 'O ficheiro não tem documentos.'], 400);
        $xlsx = base64_decode((string) ($q['xlsx'] ?? ''), true);
        if (!$xlsx || substr($xlsx, 0, 2) !== 'PK' || strlen($xlsx) > 10 * 1024 * 1024) resp(['ok' => false, 'erro' => 'O Excel não chegou ao servidor. Tente de novo.'], 400);
        $lotes = sap_ler('lotes.json', []);
        $n = $lotes ? max(array_column($lotes, 'numero')) + 1 : 1;
        $de = (string) ($q['de'] ?? ''); $ate = (string) ($q['ate'] ?? '');
        $ficheiro = 'RTM_Sales_SAP_Upload_' . preg_replace('/[^0-9-]/', '', $de) . ($ate !== $de ? '_a_' . preg_replace('/[^0-9-]/', '', $ate) : '') . '_lote' . $n . '.xlsx';
        file_put_contents(sap_pasta() . '/lote-' . $n . '.xlsx', $xlsx, LOCK_EX);
        $lote = [
            'numero' => $n, 'em' => date('Y-m-d H:i:s'), 'por' => $eu['login'], 'por_nome' => $eu['nome'] ?? $eu['login'],
            'de' => $de, 'ate' => $ate, 'rotas' => array_values(array_map('strval', (array) ($q['rotas'] ?? []))),
            'n_documentos' => count($docs), 'linhas' => (int) ($q['linhas'] ?? 0), 'total' => round((float) ($q['total'] ?? 0), 2),
            'repetidos' => (int) ($q['repetidos'] ?? 0), 'ficheiro' => $ficheiro, 'documentos' => $docs,
        ];
        $lotes[] = $lote;
        sap_gravar('lotes.json', $lotes);
        naval_registar('sap_lote', ['lote' => $n, 'documentos' => count($docs), 'total' => $lote['total'], 'periodo' => $de . ' a ' . $ate]);
        unset($lote['documentos']);
        resp(['ok' => true, 'lote' => $lote]);
}
resp(['ok' => false, 'erro' => 'Acção desconhecida.'], 400);
