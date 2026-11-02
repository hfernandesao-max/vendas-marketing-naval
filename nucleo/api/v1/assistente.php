<?php
/**
 * Assistente IA (nucleo/servicos/ia.php) e resumo diário (nucleo/servicos/resumo.php).
 *
 * GET  v1/assistente                         estado: configurado, modelo, consumo do mês e limites — recurso «assistente»
 * POST v1/assistente {pergunta, historico, idioma, csrf}   resposta {resposta, fontes, tokens}
 * GET  v1/resumo                             destinatários, último envio e pré-visualização (HTML) do resumo de ontem — recurso «resumo-diario»
 * POST v1/resumo {accao: teste, csrf}        envia o resumo de ontem só a quem pede
 */
defined('NAVAL_API') || exit;   // só pela API central (api/index.php): token JWT já validado
$GLOBALS['naval_api_sensivel'] = true;   // respostas pessoais: nunca em cache
@set_time_limit(180);
$eu = (array) naval_utilizador();
if (!$eu) throw new NavalErro('Sessão terminada.', 401);

if (($_GET['resumo'] ?? '') === '1') {
    naval_exigir(['resumo-diario']);
    naval_servico('vendas');
    naval_servico('resumo');
    if (!naval_e_post()) {
        $dia = date('Y-m-d', time() - 86400);
        $ultimo = null;
        foreach (array_reverse(glob(naval_pasta('resumo') . '/*.json') ?: []) as $fx) {
            $ultimo = naval_json_ler($fx) + ['data' => basename($fx, '.json')];
            break;
        }
        $dest = naval_resumo_destinatarios();
        $ag = naval_analise_agregados(date('Y-m-d', strtotime($dia . ' 12:00') - NAVAL_ANALISE_HISTORICO * 86400), $dia);
        $alertas = [];
        try {
            $alertas = naval_alertas($dia, naval_rotas(), true, naval_alertas_fontes())['alertas'];
        } catch (Throwable $e) {
            error_log('[resumo alertas] ' . $e->getMessage());
        }
        $dados = naval_resumo_dados($ag, $dia, naval_rotas(), $alertas, naval_promotores());
        naval_json(['ok' => true, 'activo' => naval_config('RESUMO_ACTIVO', '1') !== '0', 'hora' => naval_config('RESUMO_HORA', '07:00'),
            'email_configurado' => naval_email_configurado(), 'ultimo' => $ultimo,
            'destinatarios' => ($eu['perfil'] ?? '') === 'SADM' ? array_map(function ($d) { return ['nome' => $d['nome'], 'perfil' => $d['perfil']]; }, $dest) : count($dest),
            'html' => naval_resumo_html($dados, null, rtrim(naval_config('PORTAL_URL', ''), '/') . '/reports/#alertas')]);
    }
    $p = naval_pedido();
    naval_exigir_csrf($p);
    if (($p['accao'] ?? '') !== 'teste') throw new NavalErro('Acção desconhecida.', 400);
    $r = naval_resumo_enviar(null, (string) $eu['login']);
    if (!$r['destinatarios']) throw new NavalErro('A sua conta não tem correio electrónico válido.', 400);
    naval_json(['ok' => !$r['falhas'], 'erro' => $r['falhas'][0] ?? null, 'para' => $eu['email'] ?? '']);
}

naval_exigir(['assistente']);
naval_servico('vendas');
naval_servico('ia');
if (!naval_e_post()) {
    $u = naval_ia_uso();
    $l = naval_ia_limites();
    naval_json(['ok' => true, 'configurada' => naval_ia_configurada(), 'modelo' => naval_ia_modelo(),
        'uso' => ['tokens' => $u['entrada'] + $u['saida'], 'perguntas' => $u['perguntas'], 'minhas_hoje' => (int) ($u['por_pessoa'][$eu['login']][date('Y-m-d')] ?? 0)],
        'limites' => $l]);
}
$p = naval_pedido();
naval_exigir_csrf($p);
$idioma = in_array($p['idioma'] ?? '', ['pt', 'en', 'ti'], true) ? (string) $p['idioma'] : 'pt';
naval_json(['ok' => true] + naval_ia_perguntar((string) ($p['pergunta'] ?? ''), is_array($p['historico'] ?? null) ? $p['historico'] : [], $eu, $idioma));
