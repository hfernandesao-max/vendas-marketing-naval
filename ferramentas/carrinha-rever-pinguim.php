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
 *
 * 3. Por explicar: para cada conciliação ainda não aprovada, as diferenças que as regras de regularização não explicam,
 *    com todos os documentos do Pinguim BO desse artigo nessa carrinha desde o dia da contagem (vendas, guias de carga,
 *    também as anuladas, saídas de armazém, devoluções e inventários). Um só relatório com tudo o que precisa de decisão.
 *    --rota=1011 limita a uma rota.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . '/nucleo/arranque.php';
naval_servico('carrinha');

$o = getopt('', ['desde::', 'ate::', 'aplicar', 'rota::']);
$soRota = isset($o['rota']) ? (string) $o['rota'] : null;
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
echo "\nRegularizadas no Pinguim BO depois da contagem (acertos SA, DA, INV e vendas facturadas depois): " . count($r['regularizadas']) . "\n";
foreach ($r['regularizadas'] as $x) echo '  ' . $x['data'] . ' rota ' . $x['rota'] . ': ' . implode(', ', $x['artigos']) . "\n";
echo "\nIguais ao Pinguim BO: " . $r['iguais'] . ' · dias lidos: ' . $r['dias'] . "\n";
foreach ($r['falhas'] as $f) echo '  FALHA  ' . $f['data'] . (isset($f['rota']) ? ' rota ' . $f['rota'] : '') . ': ' . $f['erro'] . "\n";

// 3. o que fica por explicar, com o detalhe do Pinguim BO
echo "\n3. Por explicar (conciliações ainda não aprovadas, com as regras de agora)\n";
$nPor = 0;
$kz = function ($v) { return number_format((float) $v, 2, ',', ' ') . ' Kz'; };
$num = function ($v) { return $v === null ? '—' : (string) (0 + $v); };
for ($d = $desde; $d <= $ate; $d = date('Y-m-d', strtotime($d . ' 12:00') + 86400)) {
    foreach (glob(naval_pasta('carrinha') . '/' . $d . '/[0-9]*.json') ?: [] as $f) {
        $rota = basename($f, '.json');
        if ($soRota !== null && $rota !== $soRota) continue;
        $reg = naval_carr_ler($d, $rota);
        if (($reg['estado'] ?? '') === 'aprovada' || !is_array($reg['contagem'] ?? null)) continue;
        $pe = naval_carr_por_explicar($reg, $d, $rota);
        if ($pe === null) {
            echo '  FALHA  ' . $d . ' rota ' . $rota . ": o Pinguim BO não respondeu\n";
            continue;
        }
        if (!$pe) continue;
        $nPor++;
        $total = 0.0;
        foreach ($pe as $x) $total += $x['diferenca'] * $x['preco'];
        echo "\n  " . $d . ' rota ' . $rota . ' · ' . ($reg['estado'] ?? '') . ' · contada por ' . ($reg['contado_por'] ?? '—') . ' em ' . ($reg['contado_em'] ?? '—')
            . ' · base ' . naval_carr_referencia_contagem($reg, $d) . ' · ' . $kz($total) . "\n";
        $det = naval_carr_detalhe_pinguim($rota, array_column($pe, 'codigo'), $d . ' 00:00:00') ?? [];
        foreach ($pe as $x) {
            $mot = $x['motivo'];
            if (is_array($mot)) $mot = (string) ($mot['motivo'] ?? json_encode($mot, JSON_UNESCAPED_UNICODE));
            echo '    ' . $x['codigo'] . ' ' . $x['artigo'] . ': contado ' . $num($x['contado']) . ', esperado ' . $num($x['esperado']) . ', diferença ' . $num($x['diferenca'])
                . ' (' . $kz($x['diferenca'] * $x['preco']) . ')'
                . ($x['regularizado'] ? ' · já regularizado ' . $num($x['regularizado']['q']) . ' por ' . $x['regularizado']['doc'] : '')
                . ($x['diagnostico'] ? ' · diagnóstico: ' . ($x['diagnostico']['texto'] ?? $x['diagnostico']['tipo']) : '')
                . ($mot ? ' · motivo indicado: ' . $mot : '') . "\n";
            foreach ($det[$x['codigo']] ?? [] as $m) {
                echo '        ' . $m['em'] . '  ' . str_pad($m['tipo'], 4) . ' ' . str_pad($m['doc'], 24) . ' ' . sprintf('%+g', $m['q']) . ($m['anulado'] ? '  ANULADO' : '') . "\n";
            }
            if (!isset($det[$x['codigo']])) echo "        (sem documentos no Pinguim BO desde o dia da contagem)\n";
        }
    }
}
echo "\nConciliações com diferenças por explicar: " . $nPor . "\n";
exit($r['falhas'] || $falhasProva ? 1 : 0);
