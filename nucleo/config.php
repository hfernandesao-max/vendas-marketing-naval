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
    $d = rtrim(naval_config('DADOS_PRIVADOS', dirname(naval_raiz()) . '/dados-privados'), '/\\');
    naval_proteger_pasta($d);
    return $d;
}

/**
 * Defesa em profundidade (ASVS 12.5.1): a pasta privada deve ficar fora da pasta pública; se, por engano,
 * ficar dentro dela, este .htaccess impede o Apache de a servir (contas, segredos de dois passos, registos).
 */
function naval_proteger_pasta(string $d): void
{
    static $feito = [];
    if (isset($feito[$d])) return;
    $feito[$d] = true;
    if (is_dir($d) && !is_file($d . '/.htaccess')) {
        @file_put_contents($d . '/.htaccess', "# Pasta privada da plataforma: nunca servida pelo Apache\nRequire all denied\n");
    }
}

/** A pasta privada está dentro da pasta pública do Apache? (aviso no diagnóstico do super administrador) */
function naval_pasta_privada_publica(): bool
{
    $raiz = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? '')) ?: realpath(naval_raiz());
    $priv = realpath(naval_pasta_privada());
    if (!$raiz || !$priv) return false;
    $n = function ($p) { return rtrim(strtolower(str_replace('\\', '/', $p)), '/') . '/'; };
    return strpos($n($priv), $n($raiz)) === 0;
}

/** Subpasta da pasta privada, criada se ainda não existir. */
function naval_pasta(string $sub): string
{
    $d = naval_pasta_privada() . '/' . $sub;
    if (!is_dir($d)) @mkdir($d, 0770, true);
    return $d;
}

/** Chaves obrigatórias que faltam no .env (só os nomes, nunca os valores). */
function naval_config_em_falta(array $chaves): array
{
    return array_values(array_filter($chaves, function ($k) {
        return naval_config((string) $k) === '';
    }));
}

/**
 * Exige chaves do .env para uma funcionalidade; sem elas, erro 503 com os nomes das chaves em falta
 * (sem valores predefinidos no código e sem revelar caminhos do servidor).
 */
function naval_exigir_config(array $chaves, string $para): void
{
    $falta = naval_config_em_falta($chaves);
    if ($falta) {
        throw new NavalErro('Configuração incompleta no servidor para ' . $para . ' (.env: ' . implode(', ', $falta) . ').', 503);
    }
}

/** Modo de diagnóstico (APP_DEBUG=1 no .env): só em desenvolvimento; em produção os erros não mostram detalhes. */
function naval_debug(): bool
{
    return in_array(strtolower(naval_config('APP_DEBUG', '0')), ['1', 'true', 'sim', 'yes', 'on'], true);
}

/** Pedido feito por HTTPS (directamente ou atrás de um proxy de confiança indicado em PROXY_HTTPS=1). */
function naval_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') return true;
    if ((string) ($_SERVER['SERVER_PORT'] ?? '') === '443') return true;
    return naval_config('PROXY_HTTPS', '0') === '1' && strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}
