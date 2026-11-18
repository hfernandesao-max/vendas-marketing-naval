<?php
/**
 * Actualiza os dados estáticos do portal a partir do Pinguim BO (só leitura).
 *
 *   php ferramentas/sincronizar-pinguim.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/nucleo/arranque.php';
naval_servico('bd');
naval_servico('vendas');

try {
    if (naval_ensaio()) throw new RuntimeException('A sincronização está desactivada na pré-produção.');
    if (!naval_bd_configurada()) throw new RuntimeException('A base de dados do Pinguim BO não está configurada.');

    $rotas = [];
    foreach (naval_bd_consultar("
        SELECT S.CODE AS ROTA, COALESCE(NULLIF(E.DESCRIPTION, ''), S.DESCRIPTION) AS DESCRICAO
        FROM STORE S
        LEFT JOIN ENTITY E ON E.CODE = S.CODE AND E.DELETED = 0
        WHERE S.DELETED = 0 AND S.CODE IN ('1001','1002','1003','1004','1005','1006','1007','1008','1009','1010','1011')
        ORDER BY S.CODE") as $r) {
        $rota = trim((string) $r['ROTA']);
        $partes = array_map('trim', explode(' - ', trim((string) $r['DESCRICAO'])));
        $promotor = count($partes) >= 3 ? (string) end($partes) : $rota;
        $rotas[] = ['chave' => $rota, 'rota' => $rota, 'promotor' => $promotor !== '' ? $promotor : $rota];
    }
    if (!$rotas) throw new RuntimeException('O Pinguim BO não devolveu as rotas 1001–1011.');
    $promotores = [];
    foreach ($rotas as $r) $promotores[$r['rota']] = $r['promotor'];

    $validos = naval_vendas_condicao_validos();
    $inicio = naval_bd_consultar("
        SELECT CONVERT(VARCHAR(8), MIN(DH.DOC_DATE), 112) AS INICIO
        FROM DOCHEADER DH
        INNER JOIN DOCCONFIG DC ON DC.ID = DH.DOCCONFIG_ID
        WHERE $validos AND DC.CODE IN ('FR','FT','NC')")[0]['INICIO'] ?? '';
    if ($inicio === '') $inicio = date('Ymd');

    $dados = naval_vendas_sql($inicio, date('Ymd'), $promotores, false);
    if ($dados === null) throw new RuntimeException('Não foi possível ler as vendas do Pinguim BO/OLAP.');

    $codigosRota = "'" . implode("','", array_keys($promotores)) . "'";
    $clientes = [];
    $clientesVistos = [];
    foreach (naval_bd_consultar("
        SELECT EN.CODE AS CODIGO, EN.DESCRIPTION AS NOME, EN.TAX_NUMBER AS NIF, R.ROTA
        FROM CLIENT C
        INNER JOIN ENTITY EN ON EN.ID = C.ENTITY_ID
        OUTER APPLY (
            SELECT TOP 1 S.CODE AS ROTA
            FROM DOCHEADER DH
            INNER JOIN STORE S ON S.ID = DH.STORE_ID
            WHERE DH.ENTITY_ID = EN.ID AND DH.DELETED = 0 AND S.CODE IN ($codigosRota)
            GROUP BY S.CODE
            ORDER BY COUNT(*) DESC, S.CODE
        ) R
        WHERE C.DELETED = 0 AND EN.DELETED = 0
        ORDER BY EN.CODE, EN.DESCRIPTION") as $c) {
        $rota = trim((string) ($c['ROTA'] ?? ''));
        $codigo = trim((string) $c['CODIGO']);
        $nif = trim((string) ($c['NIF'] ?? ''));
        $chave = $rota . '|' . $codigo;
        if (isset($clientesVistos[$chave])) continue;
        $clientesVistos[$chave] = true;
        $clientes[] = [
            'chave' => $chave,
            'rota' => $rota,
            'promotor' => $promotores[$rota] ?? '',
            'codigo' => $codigo,
            'nome' => trim((string) $c['NOME']),
            'nif' => $nif,
            'tipo' => $nif === '' || $nif === '999999999' ? 'Consumidor Final' : 'Cliente',
            'dia' => '',
            'estado' => 'Ativo',
            'tem_gps' => false,
        ];
    }

    $dir = naval_dados_reports();
    naval_json_gravar($dir . '/rotas.json', $rotas);
    naval_json_gravar($dir . '/vendas.json', $dados['vendas']);
    naval_json_gravar($dir . '/clientes.json', $clientes);
    echo json_encode([
        'ok' => true,
        'rotas' => count($rotas),
        'vendas' => count($dados['vendas']),
        'clientes' => count($clientes),
        'actualizado_em' => date('Y-m-d H:i:s'),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Falha na sincronização do Pinguim BO: ' . $e->getMessage() . "\n");
    exit(1);
}
