<?php
/**
 * «À sua espera» da pessoa com sessão: o que aguarda a acção dela agora (aprovações, contagens, PO, ficheiros para o
 * SAP por confirmar…), já filtrado pelo nível de acesso, pelas rotas e pela separação de funções. Só leitura.
 *
 * GET v1/pendentes   → {ok, itens: [{tipo, dia, rota?, numero?, atraso, url, …}], nomes: {login: nome}, total}
 */
defined('NAVAL_API') || exit;
naval_servico('pendentes');
$u = (array) naval_utilizador();
naval_json(['ok' => true] + naval_pendentes_conta((string) ($u['login'] ?? '')));
