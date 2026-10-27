<?php
/**
 * Ponto de entrada público da API central (v1). Toda a lógica está no back-end privado (nucleo/api/).
 * Exemplo: GET api/index.php/v1/vendas?start_date=20261001&end_date=20261027  (Authorization: Bearer <token>)
 */
require_once dirname(__DIR__) . '/nucleo/arranque.php';
require_once dirname(__DIR__) . '/nucleo/api/router.php';
naval_api_executar();
