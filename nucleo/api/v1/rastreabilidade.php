<?php
/**
 * Rastreabilidade de um artigo (nucleo/servicos/rastreabilidade.php): percurso no período e detalhe dos documentos.
 * Só SELECT no Pinguim BO.
 *
 * GET v1/rastreabilidade?artigo=código&de=AAAA-MM-DD&ate=AAAA-MM-DD   percurso (nós, ligações com os documentos, stock
 *                                                                    inicial e final de cada local), até 366 dias
 * GET v1/rastreabilidade?doc=id                                      detalhe de um documento (cabeçalho e linhas)
 * Recurso «stock-movimentos». O nome do cliente das vendas só para quem vê os clientes.
 */
defined('NAVAL_API') || exit;
naval_servico('rastreabilidade');
naval_exigir(['stock-movimentos']);
@set_time_limit(180);
if (isset($_GET['doc'])) {
    $d = naval_rast_documento((string) $_GET['doc'], naval_pode('clientes'));
    naval_registar('rastreabilidade_documento', ['doc' => $d['doc']]);
    naval_json(['ok' => true, 'documento' => $d]);
}
$r = naval_rast_artigo((string) ($_GET['artigo'] ?? ''), (string) ($_GET['de'] ?? date('Y-m-d', time() - 29 * 86400)), (string) ($_GET['ate'] ?? date('Y-m-d')));
// sem permissão de clientes: as vendas mostram-se sem o nome do cliente
if (!naval_pode('clientes')) {
    foreach ($r['ligacoes'] as &$l) foreach ($l['docs'] as &$d) if ($l['para'] === 'CLI' || $l['de'] === 'CLI') $d['entidade'] = '';
    unset($l, $d);
}
naval_registar('rastreabilidade_artigo', ['artigo' => $r['artigo'], 'de' => $r['de'], 'ate' => $r['ate']]);
naval_json(['ok' => true] + $r);
