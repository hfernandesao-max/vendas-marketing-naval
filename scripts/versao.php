<?php
/**
 * Número da próxima versão (publicação no GitHub): v<AAAA.MM.DD de Luanda>.<maior número existente + 1>, sem falhas
 * (ver nucleo/servicos/versoes.php). As etiquetas existentes vêm do repositório (git tag) e, se indicado, de uma lista
 * extra (uma por linha, ex.: as do GitHub).
 *   php scripts/versao.php [ficheiro-com-etiquetas]
 */
if (PHP_SAPI !== 'cli') exit;
$raiz = dirname(__DIR__);
define('NAVAL_NUCLEO', true);
require $raiz . '/nucleo/servicos/versoes.php';
exec('git -C ' . escapeshellarg($raiz) . " tag -l 'v*'", $etiquetas, $r);
if ($r !== 0) {
    fwrite(STDERR, "Não foi possível ler as etiquetas do repositório.\n");
    exit(1);
}
if (isset($argv[1]) && is_file($argv[1])) $etiquetas = array_merge($etiquetas, file($argv[1], FILE_IGNORE_NEW_LINES) ?: []);
$dia = (new DateTime('now', new DateTimeZone('Africa/Luanda')))->format('Y.m.d');
$v = naval_versao_proxima($etiquetas, $dia);
if (in_array($v, array_map('trim', $etiquetas), true)) {
    fwrite(STDERR, "A versão $v já existe.\n");
    exit(1);
}
echo $v, "\n";
