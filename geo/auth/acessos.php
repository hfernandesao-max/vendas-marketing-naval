<?php
/**
 * Compatibilidade: a biblioteca de acessos passou para o núcleo do back-end (nucleo/seguranca/).
 * Mantido para scripts antigos que ainda incluam este ficheiro. Ver docs/ARQUITECTURA.md.
 */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {
    http_response_code(403);
    exit('Acesso proibido.');
}
require_once dirname(__DIR__, 2) . '/nucleo/arranque.php';
