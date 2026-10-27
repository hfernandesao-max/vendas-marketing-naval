<?php
/**
 * Configuração Geral da Aplicação GeoMobilidadeNaval (compatibilidade).
 *
 * Sem valores fixos no código: o endereço público vem de PORTAL_URL no .env, lido pelo núcleo
 * (nucleo/config.php é o único sítio que lê o .env).
 */
require_once dirname(__DIR__) . '/nucleo/arranque.php';

if (!defined('APP_PATH')) {
    define('APP_PATH', __DIR__);
}

if (!defined('APP_URL')) {
    define('APP_URL', rtrim(naval_config('PORTAL_URL'), '/'));
}

if (!defined('APP_DOMAIN')) {
    define('APP_DOMAIN', (string) parse_url(APP_URL, PHP_URL_HOST));
}

if (!defined('APP_PORT')) {
    define('APP_PORT', (string) parse_url(APP_URL, PHP_URL_PORT));
}
