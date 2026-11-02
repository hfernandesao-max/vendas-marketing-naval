<?php
/**
 * Migrações do actualizador num SQL Server real (só para a CI), na base de dados de ATV_TESTE_DSN:
 * lotes «GO», DDL dentro de transacção, texto com acentos pelo ODBC, registo em ATV_MIGRACAO e migração falhada desfeita.
 *   ATV_TESTE_DSN=... [ATV_TESTE_USER=... ATV_TESTE_PASS=...] php tests/sqlserver/migracoes.php
 */
if (PHP_SAPI !== 'cli') exit;
$amb = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'naval-mig-' . getmypid();
@mkdir($amb . '/privado', 0770, true);
@mkdir($amb . '/mig', 0770, true);
file_put_contents($amb . '/.env', 'DADOS_PRIVADOS=' . $amb . '/privado' . "\nJWT_SEGREDO=" . bin2hex(random_bytes(32)) . "\n");
putenv('NAVAL_ENV=' . $amb . '/.env');
require dirname(__DIR__, 2) . '/nucleo/arranque.php';
naval_servico('bd');
naval_servico('actualizacao');

$u = (string) getenv('ATV_TESTE_USER');
$pdo = new PDO((string) getenv('ATV_TESTE_DSN'), $u !== '' ? $u : null, $u !== '' ? (string) getenv('ATV_TESTE_PASS') : null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$falhas = 0;
$confirma = function (bool $v, string $m) use (&$falhas) {
    echo ($v ? '  ok    ' : '  FALHA ') . $m . "\n";
    if (!$v) $falhas++;
};

$confirma(naval_act_versao_bd($pdo) === 1, 'versão 1 depois do script oficial');
file_put_contents($amb . '/mig/0002-coluna-teste.sql', "-- teste\nALTER TABLE dbo.ATV_COLABORADOR ADD TesteMig NVARCHAR(40) NULL;\nGO\n"
    . "UPDATE dbo.ATV_COLABORADOR SET TesteMig = N'Formação' WHERE 1 = 0;\nGO\nCREATE TABLE dbo.ATV_TESTE_MIG (Id INT NOT NULL, Texto NVARCHAR(40) NOT NULL);\nGO\n"
    . "INSERT INTO dbo.ATV_TESTE_MIG VALUES (1, N'Armazém');\n");
$confirma(naval_act_migrar($amb . '/mig', true, $pdo) === [2], 'ensaio mostra a migração 2 pendente');
$confirma(naval_act_migrar($amb . '/mig', false, $pdo) === [2], 'migração 2 aplicada');
$confirma(naval_act_versao_bd($pdo) === 2, 'ATV_MIGRACAO na versão 2');
$t = (string) $pdo->query('SELECT Texto FROM dbo.ATV_TESTE_MIG')->fetchColumn();
$confirma(naval_bd_utf8($t) === 'Armazém', 'acentos guardados correctamente (' . naval_bd_utf8($t) . ')');

file_put_contents($amb . '/mig/0003-falha.sql', "CREATE TABLE dbo.ATV_TESTE_OUTRA (Id INT);\nGO\nINSERT INTO dbo.TABELA_QUE_NAO_EXISTE VALUES (1);\n");
try {
    naval_act_migrar($amb . '/mig', false, $pdo);
    $confirma(false, 'a migração 3 devia falhar');
} catch (NavalActualizacaoErro $e) {
    $confirma(strpos($e->getMessage(), '0003-falha.sql falhou') !== false, 'migração 3 falhou com mensagem clara');
}
$confirma(naval_act_versao_bd($pdo) === 2, 'versão continua 2');
$confirma((int) $pdo->query("SELECT COUNT(*) FROM sys.tables WHERE name = 'ATV_TESTE_OUTRA'")->fetchColumn() === 0, 'tabela da migração falhada desfeita');

echo "\n" . ($falhas ? "$falhas falhas\n" : "Migrações a funcionar.\n");
exit($falhas ? 1 : 0);
