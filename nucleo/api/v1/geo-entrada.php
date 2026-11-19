<?php
/**
 * Entrada de potenciais clientes de fontes externas (n8n, Make, Clay, Apify, Scrapfly…): webhook servidor a servidor.
 *
 * POST v1/geo/entrada
 *   Cabeçalho: Authorization: Bearer <GEO_ENTRADA_CHAVE>   (sem a chave no .env, a entrada está desligada: 404)
 *   Corpo (JSON, até 1 MB e 500 estabelecimentos):
 *     {"fonte": "apify-diretorio", "estabelecimentos": [{"id_externo": "...", "nome": "...", "lat": -8.83, "lng": 13.24,
 *      "categoria": "supermarket", "telefone": "+244 923 000 000", "website": "...", "morada": "..."}]}
 *   Resposta: {ok, recebidos, aceites, novos, rejeitados: [{i, motivo}]}
 *
 * Cada estabelecimento passa as mesmas regras das outras fontes (área, portfólio, qualidade) e entra no cálculo da
 * prospecção: deduplicação, ICP Score, território da rota e radar do dia. O novo cálculo corre 10 minutos depois do
 * último envio (junta envios seguidos). Ver docs/DESCOBERTA.md.
 */
defined('NAVAL_API') || exit;   // rota pública (sem sessão): autenticada pela chave própria
naval_servico('prospeccao');
$chave = (string) naval_config('GEO_ENTRADA_CHAVE');
if (strlen($chave) < 32) throw new NavalErro('Não encontrado.', 404);   // desligada (ou chave fraca)
$h = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
$dada = preg_match('/^Bearer\s+(\S+)$/i', $h, $m) ? $m[1] : '';
if ($dada === '' || !hash_equals($chave, $dada)) {
    error_log('[geo entrada] chave recusada de ' . naval_ip_cliente());
    throw new NavalErro('Chave inválida.', 401);
}
$p = naval_pedido();
$itens = $p['estabelecimentos'] ?? null;
if (!is_array($itens) || !$itens) throw new NavalErro('Falta a lista «estabelecimentos».', 400);
$r = naval_desc_receber($itens, (string) ($p['fonte'] ?? 'externa'));
naval_registar('geo_entrada', ['fonte' => (string) ($p['fonte'] ?? ''), 'aceites' => $r['aceites'], 'novos' => $r['novos']], 'webhook');
naval_json(['ok' => true] + $r);
