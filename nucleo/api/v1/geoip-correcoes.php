<?php
/**
 * Correcções da localização por IP (nucleo/seguranca/geoip.php): a rede de uma operadora registada noutro país passa a
 * mostrar o local real em todas as entradas (novas e já registadas). Só o super administrador.
 *
 * GET  v1/geoip-correcoes                                         correcções gravadas e a lista de países e cidades
 * POST v1/geoip-correcoes {accao: corrigir, ip, ambito: rede|ip, pais, cidade, regiao?, nota?, csrf}
 * POST v1/geoip-correcoes {accao: remover, chave, csrf}
 */
defined('NAVAL_API') || exit;
if (naval_perfil() !== 'SADM') {
    naval_registar('acesso_negado', ['recursos' => ['geoip-correcoes']]);
    throw new NavalErro('Só o super administrador corrige localizações.', 403);
}
if (!naval_e_post()) {
    naval_json(['ok' => true, 'correcoes' => (object) naval_geoip_correcoes(), 'paises' => NAVAL_GEOIP_PAISES, 'cidades' => array_keys(NAVAL_GEOIP_CIDADES)]);
}
$p = naval_pedido();
naval_exigir_csrf($p);
$eu = (array) naval_utilizador();
$accao = (string) ($p['accao'] ?? '');
if ($accao === 'corrigir') {
    $x = naval_geoip_gravar_correcao((string) ($p['ip'] ?? ''), (string) ($p['ambito'] ?? 'rede'), (string) ($p['pais'] ?? ''), (string) ($p['cidade'] ?? ''),
        (string) ($p['regiao'] ?? ''), (string) ($p['nota'] ?? ''), (string) ($eu['login'] ?? ''));
    naval_json(['ok' => true, 'correcao' => $x, 'correcoes' => (object) naval_geoip_correcoes()]);
}
if ($accao === 'remover') {
    naval_geoip_remover_correcao((string) ($p['chave'] ?? ''));
    naval_json(['ok' => true, 'correcoes' => (object) naval_geoip_correcoes()]);
}
throw new NavalErro('Acção desconhecida.', 400);
