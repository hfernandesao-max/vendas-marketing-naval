<?php
// Módulo Equipamentos (fase 1) numa base de dados SQLite de teste: registo, eventos, associações, documentos,
// assinatura e âmbito por perfil. Corre em processos próprios (um por conta), ver equipamentos/cenario.php.
if (!extension_loaded('pdo_sqlite')) {
    teste('equipamentos: extensão pdo_sqlite em falta (cenário não corrido)', function () {
        verdade(true);
    });
    return;
}
$ambAtv = sys_get_temp_dir() . '/naval-atv-' . getmypid();
$cenario = __DIR__ . '/equipamentos/cenario.php';
foreach (['preparar', 'log', 'pro', 'sup'] as $atvFase) {
    $atvSaida = [];
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($cenario) . ' ' . escapeshellarg($ambAtv) . ' ' . $atvFase . ' 2>&1', $atvSaida, $atvCodigo);  // nosemgrep: exec-use -- fase de uma lista fixa; restantes argumentos com escapeshellarg (só testes)
    $atvN = 0;
    foreach ($atvSaida as $atvLinha) {
        if (preg_match('/^(ok|FALHA) (.+?)(?: :: (.*))?$/u', $atvLinha, $m)) {
            $atvN++;
            $atvNome = $m[2];
            $atvMsg = $m[3] ?? '';
            teste('equipamentos [' . $atvFase . '] ' . $atvNome, function () use ($m, $atvMsg) {
                if ($m[1] !== 'ok') throw new RuntimeException($atvMsg);
            });
        }
    }
    if (!$atvN || $atvCodigo !== 0) {
        $txt = implode(' | ', array_slice($atvSaida, -5));
        teste('equipamentos [' . $atvFase . '] processo', function () use ($txt, $atvCodigo) {
            throw new RuntimeException('código ' . $atvCodigo . ': ' . $txt);
        });
    }
}
// limpeza
$apagarAtv = function (string $d) use (&$apagarAtv) {
    if (!is_dir($d)) return;
    foreach (array_diff(scandir($d) ?: [], ['.', '..']) as $x) is_dir("$d/$x") ? $apagarAtv("$d/$x") : unlink("$d/$x");
    rmdir($d);
};
$apagarAtv($ambAtv);
