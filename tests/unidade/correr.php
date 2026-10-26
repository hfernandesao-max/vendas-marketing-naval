<?php
/**
 * Testes unitários do núcleo do back-end (sem dependências): php tests/unidade/correr.php
 * Cada ficheiro *.teste.php regista casos com teste('nome', function () { ... }).
 * Corre num ambiente próprio (.env e pasta privada temporários); nada toca nos dados reais.
 */
$amb = sys_get_temp_dir() . '/naval-unidade-' . getmypid();
@mkdir($amb . '/privado', 0770, true);
file_put_contents($amb . '/.env', implode("\n", [
    '# comentário', 'DADOS_PRIVADOS=' . $amb . '/privado', 'EMAIL_MODO=ficheiro', 'VAZIO=', 'ASPAS="com aspas"', 'IGUAL=a=b', 'FUSO_HORARIO=Africa/Luanda',
]) . "\n");
putenv('NAVAL_ENV=' . $amb . '/.env');
require dirname(__DIR__, 2) . '/nucleo/arranque.php';

$GLOBALS['casos'] = [];
function teste(string $nome, callable $f): void
{
    $GLOBALS['casos'][] = [$nome, $f];
}

/** @param mixed $esperado @param mixed $obtido */
function igual($esperado, $obtido, string $msg = ''): void
{
    if ($esperado !== $obtido) {
        throw new RuntimeException(($msg ? $msg . ': ' : '') . 'esperado ' . var_export($esperado, true) . ', obtido ' . var_export($obtido, true));
    }
}

function verdade(bool $v, string $msg = 'condição falsa'): void
{
    if (!$v) throw new RuntimeException($msg);
}

/** O bloco tem de lançar NavalErro com este código HTTP. */
function lanca(int $estado, callable $f): NavalErro
{
    try {
        $f();
    } catch (NavalErro $e) {
        igual($estado, $e->estado(), 'código HTTP');
        return $e;
    }
    throw new RuntimeException('esperava NavalErro ' . $estado);
}

foreach (glob(__DIR__ . '/*.teste.php') ?: [] as $f) require $f;

$falhas = 0;
foreach ($GLOBALS['casos'] as [$nome, $f]) {
    try {
        $f();
        echo "  ok    $nome\n";
    } catch (Throwable $e) {
        $falhas++;
        echo "  FALHA $nome\n        " . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ")\n";
    }
}
// Limpeza do ambiente temporário
$apagar = function (string $d) use (&$apagar) {
    foreach (array_diff(scandir($d) ?: [], ['.', '..']) as $x) is_dir("$d/$x") ? $apagar("$d/$x") : unlink("$d/$x");
    rmdir($d);
};
$apagar($amb);
echo "\n" . count($GLOBALS['casos']) . ' testes, ' . $falhas . " falhas\n";
exit($falhas ? 1 : 0);
