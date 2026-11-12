<?php
/**
 * Relatório de Vendas diário (nucleo/servicos/relatorio-vendas.php). Recurso «relatorio-vendas»: V ver, E exportar e gerar.
 *
 * GET  v1/relatorio-vendas?dia=AAAA-MM-DD               controlos de prontidão e versões já geradas
 * GET  v1/relatorio-vendas?dia=AAAA-MM-DD&versao=N      relatório congelado (para o ecrã, o PDF e o Excel)
 * POST {accao: gerar, dia, motivo?, csrf}               gera (só com todas as fontes completas)
 */
defined('NAVAL_API') || exit;
naval_servico('relatorio-vendas');
naval_exigir(['relatorio-vendas']);
@set_time_limit(180);
$pode = naval_pode('relatorio-vendas', 'E');

if (!naval_e_post()) {
    $dia = (string) ($_GET['dia'] ?? date('Y-m-d', time() - 86400));
    if (!naval_data_valida($dia) || $dia > date('Y-m-d')) throw new NavalErro('Data inválida.', 400);
    if (isset($_GET['versao'])) {
        $r = naval_rv_ler($dia, (int) $_GET['versao']);
        if (!$r) throw new NavalErro('Relatório não encontrado.', 404);
        naval_json(['ok' => true, 'relatorio' => $r]);
    }
    $ctl = naval_rv_controlos($dia, naval_rv_fontes($dia));
    naval_json(['ok' => true, 'pode_gerar' => $pode, 'dia' => $dia, 'controlos' => $ctl['controlos'], 'pronto' => $ctl['pronto'], 'versoes' => naval_rv_versoes($dia)]);
}

$p = naval_pedido();
naval_exigir_csrf($p);
if (!$pode) throw new NavalErro('O seu perfil não permite gerar o Relatório de Vendas.', 403);
if ((string) ($p['accao'] ?? '') !== 'gerar') throw new NavalErro('Acção desconhecida.', 400);
$dia = (string) ($p['dia'] ?? '');
if (!naval_data_valida($dia) || $dia > date('Y-m-d')) throw new NavalErro('Data inválida.', 400);
$eu = (array) naval_utilizador();
naval_json(['ok' => true, 'relatorio' => naval_rv_gerar($dia, (string) ($p['motivo'] ?? ''), (string) ($eu['login'] ?? ''))]);
