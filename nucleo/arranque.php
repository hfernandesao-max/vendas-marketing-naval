<?php
/**
 * Arranque do back-end: carrega o núcleo comum (configuração, HTTP, armazenamento, segurança).
 * Os serviços de negócio (nucleo/servicos/) são carregados por quem os usa.
 *
 *   reports/data/*.php   →  require nucleo/api.php      (serviços JSON com sessão)
 *   páginas HTML         →  require geo/auth/auth_check.php
 *   ferramentas da linha de comandos → require nucleo/arranque.php
 */
if (!defined('NAVAL_NUCLEO')) define('NAVAL_NUCLEO', true);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/texto.php';
require_once __DIR__ . '/armazem.php';
require_once __DIR__ . '/http.php';
require_once __DIR__ . '/seguranca/acessos.php';
require_once __DIR__ . '/seguranca/perfis.php';
require_once __DIR__ . '/seguranca/contas.php';
require_once __DIR__ . '/seguranca/registos.php';
require_once __DIR__ . '/seguranca/sessao.php';
require_once __DIR__ . '/seguranca/paginas.php';

// Datas e horas da plataforma na hora de Luanda (salvo outro fuso em FUSO_HORARIO no .env)
date_default_timezone_set(naval_config('FUSO_HORARIO', 'Africa/Luanda'));

/** Carrega um serviço de negócio de nucleo/servicos/ (pinguim, vendas, cargas, sap, ...). */
function naval_servico(string $nome): void
{
    require_once __DIR__ . '/servicos/' . basename($nome) . '.php';
}
