<?php
/**
 * Verificação dos pedidos de integração (Testes › Normas de código): cada secção nova do CHANGELOG.md tem a
 * classificação da alteração e, se foram alterados ficheiros sensíveis (base de dados, SAP, segurança e acessos,
 * actualizador, Apache), pelo menos uma secção está classificada como «impacto» (ver nucleo/servicos/versoes.php).
 *   php scripts/verificar-versao.php <referência-base>     (ex.: HEAD^1 no commit de integração do pedido)
 */
if (PHP_SAPI !== 'cli') exit;
$raiz = dirname(__DIR__);
define('NAVAL_NUCLEO', true);
require $raiz . '/nucleo/servicos/novidades.php';
require $raiz . '/nucleo/servicos/versoes.php';
$base = (string) ($argv[1] ?? '');
if ($base === '') {
    fwrite(STDERR, "Uso: php scripts/verificar-versao.php <referência-base>\n");
    exit(2);
}
$git = 'git -C ' . escapeshellarg($raiz) . ' ';
// nosemgrep: exec-use -- script do CI (só linha de comandos): argumentos com escapeshellarg
exec($git . 'show ' . escapeshellarg($base . ':CHANGELOG.md') . ' 2>/dev/null', $antes, $r);
$conhecidas = $r === 0 ? array_flip(array_column(naval_nov_seccoes(implode("\n", $antes)), 'id')) : [];
$novas = array_values(array_filter(naval_nov_seccoes((string) file_get_contents($raiz . '/CHANGELOG.md')), function ($s) use ($conhecidas) {
    return !isset($conhecidas[$s['id']]);
}));
// nosemgrep: exec-use -- script do CI (só linha de comandos): argumentos com escapeshellarg
exec($git . 'diff --name-only ' . escapeshellarg($base) . ' HEAD', $ficheiros, $r);
if ($r !== 0) {
    fwrite(STDERR, "Não foi possível comparar com $base.\n");
    exit(2);
}
$erros = naval_versao_verificar($novas, $ficheiros);
foreach ($novas as $s) echo '  ', $s['titulo'], ' → ', NAVAL_VERSAO_TIPOS[$s['tipo']] ?? '(sem classificação)', "\n";
foreach (naval_versao_impacto($ficheiros) as $f => $a) echo '  ficheiro sensível: ', $f, ' (', $a, ")\n";
if ($erros) {
    foreach ($erros as $e) fwrite(STDERR, 'ERRO: ' . $e . "\n");
    exit(1);
}
echo "Classificação das alterações: em conformidade.\n";
