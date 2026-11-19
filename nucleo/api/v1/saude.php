<?php
/**
 * GET v1/saude — verificação de saúde para a monitorização e, no futuro, para o balanceador de carga (vários servidores
 * do portal): 200 se o servidor responde e consegue gravar nos dados privados (sessões, cache, registos); 503 se não.
 * Público e sem informação interna (nem versão, nem caminhos, nem contas).
 */
defined('NAVAL_API') || exit;
header('Cache-Control: no-store');
$ok = true;
foreach (['sessoes', 'cache', 'registos'] as $sub) {
    $d = naval_pasta_privada() . '/' . $sub;
    if (!is_dir($d)) @mkdir($d, 0770, true);
    $escrevivel = is_dir($d) && is_writable($d);
    if (!$escrevivel && is_dir($d)) {
        $prova = $d . '/.saude-' . bin2hex(random_bytes(8));
        $ficheiro = @fopen($prova, 'x');
        if ($ficheiro !== false) {
            fclose($ficheiro);
            $escrevivel = @unlink($prova);
        }
    }
    if (!$escrevivel) $ok = false;
}
naval_json(['ok' => $ok, 'hora' => date('c')], $ok ? 200 : 503);
