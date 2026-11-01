<?php
/**
 * Executa scripts SQL Server (lotes separados por linhas «GO»), como o SSMS ou o sqlcmd, por PDO.
 *   php tests/sqlserver/executar.php "<dsn>" <utilizador> <palavra-passe> ficheiro.sql [--sem-carga] [ficheiro2.sql …]
 *   php tests/sqlserver/executar.php "<dsn>" <utilizador> <palavra-passe> --sql "CREATE DATABASE X"
 * --sem-carga: pára no bloco «Carga inicial» do script oficial (a carga de teste vem noutro ficheiro).
 * Só para os testes da CI; nunca no servidor de produção.
 */
if (PHP_SAPI !== 'cli') exit;
[$_, $dsn, $user, $pass] = $argv + [null, '', '', ''];
$args = array_slice($argv, 4);
$pdo = new PDO($dsn, $user === '-' ? null : $user, $pass === '-' ? null : $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
if (($args[0] ?? '') === '--sql') {
    $pdo->exec($args[1]);
    echo "ok: {$args[1]}\n";
    exit(0);
}
$semCarga = in_array('--sem-carga', $args, true);
foreach (array_diff($args, ['--sem-carga']) as $f) {
    $sql = (string) file_get_contents($f);
    if ($semCarga) {
        $p = strpos($sql, '/* ===================== Carga inicial');
        if ($p !== false) $sql = substr($sql, 0, $p);
    }
    $lotes = preg_split('/^\s*GO\s*;?\s*$/mi', $sql) ?: [];
    $n = 0;
    foreach ($lotes as $lote) {
        if (trim(preg_replace('/^\s*--.*$/m', '', $lote)) === '') continue;
        $st = $pdo->query($lote);
        // consome todos os resultados (SELECT de verificação no fim do script)
        do {
            if ($st->columnCount() > 0) foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $l) echo '   ' . json_encode($l, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
            try {
                $mais = $st->nextRowset();
            } catch (PDOException $e) {
                $mais = false;
            }
        } while ($mais);
        $n++;
    }
    echo "ok: " . basename($f) . " ($n lotes)\n";
}
