<?php
/**
 * Verificação da base de dados do módulo Equipamentos no servidor, depois de executar
 * ferramentas/sql/equipamentos-sqlserver.sql e preencher ATV_DB_* no .env:
 *
 *     php ferramentas/verificar-equipamentos.php
 *
 * Confirma a ligação, as tabelas, as vistas e as regras (triggers) e faz uma escrita de ensaio dentro de uma
 * transacção que é sempre desfeita no fim: nada fica gravado. Não mostra palavras-passe nem valores do .env.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . '/nucleo/arranque.php';
naval_servico('equipamentos');

$falhas = 0;
$linha = function (bool $ok, string $txt) use (&$falhas) {
    if (!$ok) $falhas++;
    echo ($ok ? '  ok     ' : '  FALHA  ') . $txt . "\n";
};

echo "Equipamentos: verificação da base de dados\n";
if (!naval_atv_configurada()) {
    echo "  FALHA  .env sem ATV_DB_NAME nem ATV_DB_DSN\n";
    exit(1);
}
try {
    $pdo = naval_atv_bd();
    $linha(true, 'ligação (' . naval_atv_driver() . ')');
} catch (Throwable $e) {
    echo '  FALHA  ligação: ' . $e->getMessage() . "\n";
    exit(1);
}

foreach (['ATV_TIPO', 'ATV_COLABORADOR', 'ATV_ACTIVO', 'ATV_ASSOCIACAO', 'ATV_DOCUMENTO', 'ATV_DOCUMENTO_ACTIVO', 'ATV_ASSINATURA', 'ATV_EVENTO', 'ATV_MIGRACAO', 'VW_ATV_FICHA', 'VW_ATV_SEM_TERMO'] as $t) {
    try {
        $n = naval_atv_q("SELECT COUNT(*) AS N FROM $t")[0]['N'];
        $linha(true, "$t ($n registos)");
    } catch (Throwable $e) {
        $linha(false, "$t: " . $e->getMessage());
    }
}

// Escrita de ensaio: sempre desfeita
$pdo->beginTransaction();
try {
    $id = (int) naval_atv_inserir('ATV_COLABORADOR', ['Nome' => 'Verificação (desfeita)', 'Activo' => 0, 'CriadoEm' => naval_atv_agora()], 'IdColaborador');
    $linha($id > 0, 'inserir e obter o número gerado (colaborador ' . $id . ')');
    $a = naval_atv_q('SELECT MIN(IdActivo) AS A FROM ATV_ACTIVO')[0]['A'] ?? null;
    if ($a) {
        naval_atv_inserir('ATV_EVENTO', ['IdActivo' => $a, 'TipoEvento' => 'INVENTARIO', 'DataHora' => naval_atv_agora(), 'Utilizador' => 'verificação']);
        $doc = (int) naval_atv_inserir('ATV_DOCUMENTO', ['NumDocumento' => 'VER-0000-000001', 'TipoDocumento' => 'CONVENCAO_PROVA', 'Estado' => 'A_ASSINAR',
            'IdColaborador' => $id, 'DataEmissao' => naval_atv_agora(), 'EmitidoPor' => 'verificação', 'HashOriginal' => str_repeat('0', 64), 'FicheiroPdf' => 'x.pdf'], 'IdDocumento');
        $linha($doc > 0, 'documento com triggers: número gerado (' . $doc . ')');
        // por último: no SQL Server o erro de um trigger desfaz a transacção inteira
        $recusou = false;
        try {
            naval_atv_x("UPDATE ATV_EVENTO SET Observacoes = 'x' WHERE IdActivo = :a", [':a' => $a]);
        } catch (Throwable $e) {
            $recusou = true;
        }
        $linha($recusou, 'histórico inalterável (a base de dados recusa alterar eventos)');
    } else {
        $linha(false, 'sem equipamentos (a carga inicial do script não foi executada?)');
    }
} catch (Throwable $e) {
    $linha(false, 'escrita de ensaio: ' . $e->getMessage());
}
try {
    $pdo->rollBack();
} catch (Throwable $e) {
    // já desfeita pelo servidor (erro de trigger)
}
$restos = (int) naval_atv_q("SELECT COUNT(*) AS N FROM ATV_COLABORADOR WHERE Nome = 'Verificação (desfeita)'")[0]['N']
    + (int) naval_atv_q("SELECT COUNT(*) AS N FROM ATV_DOCUMENTO WHERE NumDocumento = 'VER-0000-000001'")[0]['N'];
$linha($restos === 0, 'escrita de ensaio desfeita (nada ficou gravado)');

$pasta = naval_pasta('equipamentos/documentos');
$linha(is_dir($pasta) && is_writable($pasta), 'pasta privada dos documentos com escrita');
$linha(function_exists('mb_convert_encoding') || function_exists('iconv'), 'conversão de texto para os PDF (mbstring ou iconv)');

echo $falhas ? "\n$falhas verificação(ões) falharam.\n" : "\nTudo pronto.\n";
exit($falhas ? 1 : 0);
