<?php
/**
 * Mapa de clientes ao vivo (nucleo/servicos/visitas-app.php): um ponto por cliente com o estado do dia.
 *
 * GET v1/clientes-ao-vivo[?dia=AAAA-MM-DD][&versao=…]   recurso «geo-ao-vivo» (Vr: só as suas rotas)
 *   → {ok, dia, atualizado_em, versao, pontos: [...], totais, rotas, estados, erro}  ou  {ok, inalterado: true}
 *
 * Hoje: as fontes lêem-se no máximo de 2 em 2 minutos (um só leitor, seja quem for que peça); a página pede de 30
 * em 30 s com a versão e só recebe dados quando mudaram. Sem valores para quem não vê vendas.
 */
defined('NAVAL_API') || exit;
naval_servico('visitas-app');
naval_servico('vendas');
naval_exigir(['geo-ao-vivo']);
$dia = (string) ($_GET['dia'] ?? date('Y-m-d'));
if (!naval_data_valida($dia) || $dia > date('Y-m-d')) throw new NavalErro('Dia inválido.', 400);
$m = naval_vapp_ambito(naval_vapp_mapa($dia), naval_rotas(), naval_ve_vendas());
if (($_GET['versao'] ?? '') !== '' && (string) $_GET['versao'] === (string) ($m['versao'] ?? '')) {
    naval_json(['ok' => true, 'inalterado' => true, 'atualizado_em' => $m['atualizado_em'] ?? null]);
}
$rotas = array_values(array_unique(array_filter(array_map(function ($p) { return (string) $p['r']; }, (array) ($m['pontos'] ?? [])))));
sort($rotas);
$tecnico = naval_perfil() === 'SADM' || naval_pode('geo-ao-vivo', 'G');
naval_json(['ok' => true, 'dia' => $dia, 'atualizado_em' => $m['atualizado_em'] ?? null, 'versao' => $m['versao'] ?? '', 'pontos' => $m['pontos'] ?? [],
    'totais' => $m['totais'] ?? null, 'rotas' => $rotas, 'estados' => NAVAL_VAPP_NOMES, 'erro' => $m['erro'] ?? null, 'configurado' => naval_vapp_configurado(),
    'raio_m' => (int) naval_config('VISITAS_RAIO_PRESENCIAL_M', (string) NAVAL_VAPP_RAIO_M), 've_valores' => naval_ve_vendas()]
    + ($tecnico ? ['ms' => $m['ms'] ?? null] : []));
