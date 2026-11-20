<?php
/**
 * Sinal de mudança (tempo real da app e do portal; nucleo/servicos/mudancas.php). Qualquer pessoa com sessão.
 *
 * GET v1/mudancas   {ok, marca, pinguim, accoes: {po_por_aprovar?}, hora, intervalo}
 *                   a «marca» muda quando há documentos novos ou alterados hoje no Pinguim BO ou registos do portal
 *                   alterados (PO, conciliação, caixa, ordens); o cliente só relê os dados quando ela muda.
 */
defined('NAVAL_API') || exit;
naval_servico('rotas');
naval_servico('mudancas');
header('Cache-Control: no-store');
naval_json(naval_mudancas());
