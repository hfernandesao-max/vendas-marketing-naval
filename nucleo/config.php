<?php
/**
 * Configuração da plataforma: o único sítio onde se lê o ficheiro .env.
 *
 * O .env fica na raiz do site (fora do GitHub). A variável de ambiente NAVAL_ENV indica outro
 * ficheiro (testes automáticos). Chaves usadas: ver docs/ARQUITECTURA.md («Configuração»).
 */
defined('NAVAL_NUCLEO') || exit;

/** Pasta raiz do site (onde estão geo/, reports/ e nucleo/). */
function naval_raiz(): string
{
    return dirname(__DIR__);
}

function naval_ficheiro_env(): string
{
    return getenv('NAVAL_ENV') ?: naval_raiz() . '/.env';
}

/** Todas as chaves do .env (lidas uma vez por pedido). */
function naval_env(): array
{
    static $env = null;
    if ($env !== null) return $env;
    $env = [];
    $f = naval_ficheiro_env();
    if (!is_file($f)) return $env;
    foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $l) {
        $l = trim($l);
        if ($l === '' || $l[0] === '#' || strpos($l, '=') === false) continue;
        [$k, $v] = explode('=', $l, 2);
        $env[trim($k)] = trim(trim($v), "\"'");
    }
    return $env;
}

/** Valor de uma chave do .env; vazio ou ausente devolve a omissão. */
function naval_config(string $chave, string $omissao = ''): string
{
    $v = naval_env()[$chave] ?? '';
    return $v !== '' ? $v : $omissao;
}

/** Pasta privada (fora da pasta pública): contas, registos, planos, lotes do SAP. */
function naval_pasta_privada(): string
{
    return rtrim(naval_config('DADOS_PRIVADOS', dirname(naval_raiz()) . '/dados-privados'), '/\\');
}

/** Subpasta da pasta privada, criada se ainda não existir. */
function naval_pasta(string $sub): string
{
    $d = naval_pasta_privada() . '/' . $sub;
    if (!is_dir($d)) @mkdir($d, 0770, true);
    return $d;
}
