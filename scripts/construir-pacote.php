<?php
/**
 * Constrói o pacote de uma versão do portal (usado pela publicação no GitHub e pelos testes):
 *   php scripts/construir-pacote.php <pasta-de-saída> <versão> [commit]
 * Cria portal-rtm-<versão>.zip (manifesto.json + ficheiros/…) e portal-rtm-<versão>.zip.sha256.
 * Os ficheiros são os do repositório (git ls-files) que vão para o servidor: sem testes, documentação,
 * configuração de desenvolvimento, .env nem dados (regras em nucleo/servicos/actualizacao.php).
 */
if (PHP_SAPI !== 'cli') exit;
[, $saida, $versao] = $argv + [null, '', ''];
$commit = (string) ($argv[3] ?? '');
if ($saida === '' || !preg_match('/^v?[\w.\-]{1,40}$/', $versao)) {
    fwrite(STDERR, "Uso: php scripts/construir-pacote.php <pasta-de-saída> <versão> [commit]\n");
    exit(1);
}
$raiz = dirname(__DIR__);
define('NAVAL_NUCLEO', true);
require $raiz . '/nucleo/config.php';
require $raiz . '/nucleo/servicos/actualizacao.php';

$lista = [];
// nosemgrep: exec-use -- linha de comandos (CI): argumentos com escapeshellarg
exec('git -C ' . escapeshellarg($raiz) . ' ls-files -z', $saidaGit, $r);
if ($r !== 0) {
    fwrite(STDERR, "git ls-files falhou\n");
    exit(1);
}
foreach (explode("\0", implode("\n", $saidaGit)) as $c) {
    $c = trim($c);
    if ($c !== '' && naval_act_incluir($c) && is_file($raiz . '/' . $c)) $lista[$c] = (string) hash_file('sha256', $raiz . '/' . $c);
}
ksort($lista, SORT_STRING);
$manifesto = ['formato' => 1, 'versao' => $versao, 'commit' => $commit, 'data' => gmdate('Y-m-d\TH:i:s\Z'), 'ficheiros' => $lista];

if (!is_dir($saida)) mkdir($saida, 0775, true);
$zip = rtrim($saida, '/\\') . '/portal-rtm-' . $versao . '.zip';
@unlink($zip);
$z = new ZipArchive();
if ($z->open($zip, ZipArchive::CREATE) !== true) {
    fwrite(STDERR, "Não foi possível criar $zip\n");
    exit(1);
}
$z->addFromString('manifesto.json', (string) json_encode($manifesto, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
foreach (array_keys($lista) as $c) $z->addFile($raiz . '/' . $c, 'ficheiros/' . $c);
$z->close();
file_put_contents($zip . '.sha256', hash_file('sha256', $zip) . '  ' . basename($zip) . "\n");
echo basename($zip) . ': ' . count($lista) . " ficheiros\n";
