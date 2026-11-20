<?php
/**
 * Rever as conciliações das carrinhas contra o Pinguim BO de agora (no servidor, na pasta do portal):
 *
 *     php ferramentas/carrinha-rever-pinguim.php                      ensaio desde 01-01-2026 (só mostra)
 *     php ferramentas/carrinha-rever-pinguim.php --aplicar            aplica
 *     php ferramentas/carrinha-rever-pinguim.php --desde=2026-11-01 --ate=2026-11-19 [--aplicar]
 *
 * 1. Prova de cada dia fechado: por carrinha e artigo, inicial + carregado − devolvido − vendido ± inventário tem de
 *    dar o stock final do Pinguim BO. Grava a prova do dia (_prova.json; bloqueia a aprovação das rotas que não batem).
 *
 * Para cada dia e rota com a base congelada (folha de contagem impressa ou contagem gravada), compara com o Pinguim BO
 * de agora: guias anuladas, documentos corrigidos ou lançados depois. Abertas: a base é actualizada, a contagem fica e a
 * validação e a aprovação do coordenador voltam a ser pedidas. Aprovadas: nada muda; ficam assinaladas («revisão do
 * Pinguim BO») com as diferenças. O mesmo corre sozinho de hora a hora (3 dias) e de madrugada (45 dias).
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . '/nucleo/arranque.php';
naval_servico('carrinha');

$o = getopt('', ['desde::', 'ate::', 'aplicar']);
$desde = (string) ($o['desde'] ?? '2026-01-01');
$ate = (string) ($o['ate'] ?? date('Y-m-d'));
$aplicar = array_key_exists('aplicar', $o);
echo 'Conciliação das carrinhas: revisão contra o Pinguim BO de ' . $desde . ' a ' . $ate . ($aplicar ? '' : ' (ENSAIO: nada é gravado; --aplicar para aplicar)') . "\n\n";
// 1. prova de cada dia (dias fechados; sem documentos nem stock nas carrinhas não há nada a provar)
$dias = 0;
$vistas = 0;
$falhasProva = [];
for ($d = $desde; $d <= $ate && $d < date('Y-m-d'); $d = date('Y-m-d', strtotime($d . ' 12:00') + 86400)) {
    $p = naval_carr_prova_dia($d);
    if ($p === null) {
        fwrite(STDERR, '  FALHA  prova de ' . $d . ': o Pinguim BO não respondeu' . "\n");
        continue;
    }
    if (!$p['vistas']) continue;
    $dias++;
    $vistas += $p['vistas'];
    foreach ($p['falhas'] as $f) $falhasProva[] = $f + ['data' => $d];
}
echo '1. Prova do stock das carrinhas: ' . $dias . ' dias com movimento, ' . $vistas . ' linhas (carrinha × artigo × dia), '
    . count($falhasProva) . " não batem com o Pinguim BO\n";
foreach ($falhasProva as $f) {
    echo '      ' . $f['data'] . ' carrinha ' . $f['rota'] . ' ' . $f['codigo'] . ' ' . $f['artigo'] . ': inicial ' . (0 + $f['inicial']) . ' + carregado ' . (0 + $f['carregado'])
        . ' − devolvido ' . (0 + $f['devolvido']) . ' − vendido ' . (0 + $f['vendido']) . ' ± inventário ' . (0 + $f['inventario']) . ' = ' . (0 + $f['esperado']) . '; Pinguim BO ' . (0 + $f['final']) . "\n";
}
echo "\n2. Conciliações com a base congelada\n";
try {
    $r = naval_carr_rever_pinguim($desde, $ate, ['login' => 'sistema'], !$aplicar);
} catch (Throwable $e) {
    fwrite(STDERR, 'FALHA  ' . $e->getMessage() . "\n");
    exit(1);
}
$mostrar = function (array $x) {
    echo '  ' . $x['data'] . ' rota ' . $x['rota'] . "\n";
    foreach ($x['diferencas'] as $d) {
        $m = [];
        foreach ($d['mudou'] as $k => $v) $m[] = $k . ' ' . (0 + $v[0]) . ' → ' . (0 + $v[1]);
        echo '      ' . $d['codigo'] . ' ' . $d['artigo'] . ': ' . implode(', ', $m) . "\n";
    }
};
echo ($aplicar ? 'Actualizadas' : 'A actualizar') . ' (abertas): ' . count($r['actualizadas']) . "\n";
array_map($mostrar, $r['actualizadas']);
echo "\nAprovadas com diferenças (não se alteram; ficam assinaladas" . ($aplicar ? '' : ' ao aplicar') . '): ' . count($r['aprovadas_com_diferencas']) . "\n";
array_map($mostrar, $r['aprovadas_com_diferencas']);
echo "\nIguais ao Pinguim BO: " . $r['iguais'] . ' · dias lidos: ' . $r['dias'] . "\n";
foreach ($r['falhas'] as $f) echo '  FALHA  ' . $f['data'] . (isset($f['rota']) ? ' rota ' . $f['rota'] : '') . ': ' . $f['erro'] . "\n";
exit($r['falhas'] || $falhasProva ? 1 : 0);
