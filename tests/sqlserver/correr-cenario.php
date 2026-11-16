<?php
/**
 * Corre o cenário do módulo Equipamentos (tests/unidade/equipamentos/cenario.php) contra a base de dados de
 * ATV_TESTE_DSN, conta por conta (log, pro, sup), e termina com erro se algum caso falhar. Só para a CI.
 */
if (PHP_SAPI !== 'cli') exit;
$amb = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'naval-atv-sqlserver-' . getmypid();
$cenario = dirname(__DIR__) . '/unidade/equipamentos/cenario.php';
$ok = 0;
$falhas = 0;
foreach (['preparar', 'log', 'pro', 'sup'] as $fase) {
    $saida = [];
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($cenario) . ' ' . escapeshellarg($amb) . ' ' . $fase . ' 2>&1', $saida, $codigo);  // nosemgrep: exec-use -- fase de uma lista fixa; restantes argumentos com escapeshellarg (só testes)
    foreach ($saida as $l) {
        echo "[$fase] $l\n";
        if (strpos($l, 'ok ') === 0) $ok++;
        if (strpos($l, 'FALHA') === 0) $falhas++;
    }
    if ($codigo !== 0) {
        echo "[$fase] processo terminou com o código $codigo\n";
        $falhas++;
    }
}
echo "\n$ok casos ok, $falhas falhas\n";
exit($falhas || $ok < 20 ? 1 : 0);
