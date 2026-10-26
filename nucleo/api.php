<?php
/**
 * Entrada comum dos serviços JSON (reports/data/*.php): núcleo, erros em JSON e sessão obrigatória.
 * Sem sessão válida responde 401 em JSON (o portal mostra então a página de entrada).
 */
require_once __DIR__ . '/arranque.php';

naval_erros_em_json(basename($_SERVER['SCRIPT_NAME'] ?? 'api'));
naval_iniciar_sessao();
if (!naval_autenticado()) {
    naval_json(['ok' => false, 'error' => 'Autenticação necessária.', 'erro' => 'Autenticação necessária.'], 401);
}
