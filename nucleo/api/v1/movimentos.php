<?php
/**
 * Extracto dos movimentos de stock (nucleo/servicos/movimentos.php): entradas e saídas de artigos, documento a
 * documento, por armazém e artigo. Só SELECT no Pinguim BO.
 *
 * GET v1/movimentos?de=AAAA-MM-DD&ate=AAAA-MM-DD[&armazem=código][&artigo=código ou texto][&tipo=FT|GT|…|INV]
 *     recurso «stock-movimentos» (todos os armazéns e carrinhas); período até 31 dias
 */
defined('NAVAL_API') || exit;
naval_servico('movimentos');
naval_exigir(['stock-movimentos']);
@set_time_limit(180);
$hoje = date('Y-m-d');
$de = (string) ($_GET['de'] ?? date('Y-m-d', time() - 6 * 86400));
$ate = (string) ($_GET['ate'] ?? $hoje);
$r = naval_mov_extracto($de, $ate, (string) ($_GET['armazem'] ?? ''), (string) ($_GET['artigo'] ?? ''), (string) ($_GET['tipo'] ?? ''));
naval_registar('stock_movimentos_consultado', ['de' => $r['de'], 'ate' => $r['ate'], 'armazem' => $r['armazem'], 'artigo' => $r['artigo'], 'linhas' => $r['resumo']['movimentos']]);
naval_json(['ok' => true] + $r);
