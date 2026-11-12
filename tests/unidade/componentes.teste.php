<?php
// Bibliotecas de terceiros (OpenChain ISO/IEC 5230): cada ficheiro de reports/vendor está no inventário, com licença
// permitida para um produto comercial e o SHA-256 certo; a lista de componentes (SBOM) gera-se sem erros.
teste('componentes: inventário completo, licenças permitidas, SHA-256 certo e SBOM CycloneDX válido', function () {
    $raiz = dirname(__DIR__, 2);
    $inv = json_decode((string) file_get_contents($raiz . '/reports/vendor/componentes.json'), true);
    $listados = array_column($inv['componentes'], null, 'ficheiro');
    foreach (glob($raiz . '/reports/vendor/*') as $f) {
        $n = basename($f);
        if ($n === 'componentes.json') continue;
        verdade(isset($listados[$n]), 'biblioteca sem inventário: ' . $n);
        igual($listados[$n]['sha256'], hash_file('sha256', $f), 'SHA-256 de ' . $n);
        verdade(in_array($listados[$n]['licenca'], $inv['licencas_permitidas'], true), 'licença de ' . $n);
    }
    $saida = sys_get_temp_dir() . '/sbom-' . getmypid() . '.json';
    // nosemgrep: exec-use -- testes automáticos: argumentos fixos ou com escapeshellarg
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($raiz . '/scripts/sbom.php') . ' ' . escapeshellarg($saida) . ' v-teste abc 2>&1', $o, $r);
    igual(0, $r, implode("\n", $o));
    $s = json_decode((string) file_get_contents($saida), true);
    @unlink($saida);
    igual('CycloneDX', $s['bomFormat']);
    igual(count($inv['componentes']), count(array_filter($s['components'], function ($c) { return $c['type'] === 'library'; })));
});
