<?php
/**
 * Documentos válidos e anulados (nucleo/servicos/documentos-estado.php): uma linha por artigo de cada documento, com o
 * estado e o motivo da anulação. Só SELECT no Pinguim BO.
 *
 * GET v1/documentos-estado?de=AAAA-MM-DD&ate=AAAA-MM-DD&grupo=vendas|cargas|todos
 *     recurso «documentos-estado»; quem trabalha por rota (SUP, PRO) só recebe as suas; o nome do cliente das vendas
 *     só para quem vê os clientes; período até 366 dias
 */
defined('NAVAL_API') || exit;
naval_servico('documentos-estado');
naval_exigir(['documentos-estado']);
@set_time_limit(180);
$hoje = date('Y-m-d');
$de = (string) ($_GET['de'] ?? date('Y-m-d', time() - 6 * 86400));
$ate = (string) ($_GET['ate'] ?? $hoje);
$grupo = (string) ($_GET['grupo'] ?? 'vendas');
$linhas = naval_filtrar_rotas(naval_docest_ler($de, $ate, $grupo, naval_pode('clientes')));
naval_registar('documentos_estado_consultado', ['de' => $de, 'ate' => $ate, 'grupo' => $grupo, 'linhas' => count($linhas)]);
naval_json(['ok' => true, 'de' => $de, 'ate' => $ate, 'grupo' => $grupo, 'linhas' => $linhas] + naval_docest_resumo($linhas));
