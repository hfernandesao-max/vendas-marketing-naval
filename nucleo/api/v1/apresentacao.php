<?php
/**
 * Apresentação do portal (Gestão › Apresentação do portal): dados reais que a mantêm actualizada sozinha.
 *
 * GET v1/apresentacao → negócio (vendas por mês, mês até hoje face ao mesmo período do mês anterior, clientes
 * activos, documentos, rotas, valor médio por compra) no âmbito de quem pede e só para quem vê vendas; automação
 * (o que o portal fez sozinho nos últimos 30 dias e a carteira calculada esta noite); horas da rotina automática
 * (do .env); versão instalada; novidades (CHANGELOG.md) e roteiro (nucleo/recursos/roteiro.json).
 * Recurso «apresentacao» (V). Só agregados: sem clientes, documentos, NIF nem nomes.
 */
defined('NAVAL_API') || exit;
naval_exigir(['apresentacao']);
naval_servico('novidades');
naval_servico('actualizacao');
naval_servico('analise');
naval_servico('vendas');
naval_servico('apresentacao');
@set_time_limit(300);
$h = function (string $k, string $omissao): string {
    $v = naval_config($k, $omissao);
    return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v) ? $v : $omissao;
};
$hoje = date('Y-m-d');
$rotas = naval_rotas();
$veValores = naval_ve_vendas();
$chave = $hoje . '|' . floor(time() / 600) . '|' . json_encode($rotas) . '|' . ($veValores ? 1 : 0) . '|' . (naval_pode('agt') ? 1 : 0);
$r = naval_cache('apresentacao|' . $chave, 600, function () use ($hoje, $rotas, $veValores) {
    $negocio = null;
    $erro = '';
    if ($veValores) {
        try {
            $de = naval_apx_mes($hoje, -(NAVAL_APX_MESES - 1));
            $negocio = naval_apx_negocio(naval_analise_ambito(naval_analise_agregados($de, $hoje), $rotas, true), $hoje);
        } catch (Throwable $e) {
            error_log('[apresentacao] ' . $e->getMessage());
            $erro = 'Não foi possível ler as vendas do Pinguim BO neste momento.';
        }
    }
    return ['negocio' => $negocio, 'negocio_erro' => $erro, 'automacao' => naval_apx_automacao($hoje, $rotas, $veValores),
        'governanca' => naval_apx_governanca(naval_pode('agt')), 'sistemas' => naval_apx_sistemas()];
}, ($_GET['fresco'] ?? '') === '1');
$noite = naval_act_horas_noite();
$seccoes = naval_nov_seccoes((string) @file_get_contents(naval_nov_ficheiro_changelog()));
$roteiro = json_decode((string) @file_get_contents(naval_raiz() . '/nucleo/recursos/roteiro.json'), true) ?: [];
unset($roteiro['_nota']);
$estado = naval_act_estado();
naval_json(['ok' => true,
    'hora' => date('Y-m-d H:i'),
    'versao' => (string) ($estado['versao'] ?? ''),
    'perfis' => count(naval_perfis()),
    've_valores' => $veValores,
    'ambito' => $rotas === null ? 'todas' : count($rotas),
    'horas' => [
        'aprovar' => $noite['aprovar'], 'instalar' => $noite['instalar'], 'calculo' => '04:00',
        'resumo' => $h('RESUMO_HORA', '07:00'), 'plano' => $h('PLANO_HORA_PROPOSTA', '18:00'),
        'carrinha' => $h('CARRINHA_HORA_AVISO', '19:30'), 'fecho' => $h('FECHO_HORA', '20:00'),
    ],
    'novidades' => array_map(function ($s) { return ['data' => $s['data'], 'titulo' => $s['titulo'], 'itens' => array_slice($s['itens'], 0, 3)]; }, array_slice($seccoes, 0, 12)),
    'novidades_total' => count($seccoes),
    'roteiro' => $roteiro] + $r);
