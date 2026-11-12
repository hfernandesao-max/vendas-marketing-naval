<?php
/**
 * Notas de uma versão (publicação no GitHub): as secções do CHANGELOG.md que são novas em relação à versão anterior,
 * no mesmo formato do registo (## dia, ### título, - pontos). É o que a página «Actualizações» do portal mostra para
 * aprovação. Sem versão anterior ou sem secções novas, devolve a lista dos commits.
 *   php scripts/notas-versao.php [etiqueta-anterior]
 */
if (PHP_SAPI !== 'cli') exit;
$raiz = dirname(__DIR__);
define('NAVAL_NUCLEO', true);
require $raiz . '/nucleo/servicos/novidades.php';
$ant = (string) ($argv[1] ?? '');
$antes = [];
if ($ant !== '') {
    // nosemgrep: exec-use -- linha de comandos (CI): argumentos com escapeshellarg
    exec('git -C ' . escapeshellarg($raiz) . ' show ' . escapeshellarg($ant . ':CHANGELOG.md') . ' 2>/dev/null', $l, $r);
    if ($r === 0) $antes = array_flip(array_column(naval_nov_seccoes(implode("\n", $l)), 'id'));
}
$novas = array_filter(naval_nov_seccoes((string) file_get_contents($raiz . '/CHANGELOG.md')), function ($s) use ($antes) { return !isset($antes[$s['id']]); });
if ($ant !== '' && !$novas) {
    // nosemgrep: exec-use -- linha de comandos (CI): argumentos com escapeshellarg
    exec('git -C ' . escapeshellarg($raiz) . ' log --no-merges --format=%s ' . escapeshellarg($ant . '..HEAD'), $c);
    echo "Alterações:\n";
    foreach ($c as $x) echo '- ', $x, "\n";
    exit(0);
}
if ($ant === '') $novas = array_slice($novas, 0, 5);
$dia = '';
foreach ($novas as $s) {
    if ($s['data'] !== $dia) {
        echo ($dia === '' ? '' : "\n"), '## ', $s['data'], "\n";
        $dia = $s['data'];
    }
    echo "\n### ", $s['titulo'], "\n";
    foreach ($s['itens'] as $i) echo '- ', $i, "\n";
}
