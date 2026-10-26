<?php
/** Compatibilidade: este serviço passou para nucleo/servicos/email.php (ver docs/ARQUITECTURA.md). */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {
    http_response_code(403);
    exit('Acesso proibido.');
}
require_once dirname(__DIR__, 2) . '/nucleo/arranque.php';
naval_servico('email');
