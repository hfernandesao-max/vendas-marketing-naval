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

/**
 * Ambiente de ensaio (versão nova lado a lado, dentro do portal: «instalação azul e verde»). O actualizador instala a
 * versão nova em <portal>/ensaio/ com o ficheiro .ambiente-ensaio. Aí, o portal usa o .env da produção (os segredos não
 * se copiam) com estas diferenças: dados privados próprios (<dados-privados>/ensaio, uma cópia feita na instalação),
 * endereço com /ensaio, sem actualizações automáticas, correio só para os administradores de sistema e entrada só para
 * o super administrador e o administrador da plataforma. A produção nunca lê nada do ensaio.
 */
function naval_ensaio(): bool
{
    static $e = null;
    if ($e === null) $e = is_file(naval_raiz() . '/.ambiente-ensaio');
    return $e;
}

/** Raiz da produção: no ensaio, a pasta acima de <portal>/ensaio. */
function naval_raiz_producao(): string
{
    return naval_ensaio() ? dirname(naval_raiz()) : naval_raiz();
}

/** Dados públicos do portal (reports/data/*.json): no ensaio, os da produção (só leitura). */
function naval_dados_reports(): string
{
    return naval_raiz_producao() . '/reports/data';
}

/** Versão instalada no ensaio (ficheiro .ambiente-ensaio, escrito pelo actualizador). */
function naval_ensaio_versao(): string
{
    if (!naval_ensaio()) return '';
    $j = json_decode((string) @file_get_contents(naval_raiz() . '/.ambiente-ensaio'), true);
    return is_array($j) ? (string) ($j['versao'] ?? '') : '';
}

/** Faixa fixa «ENSAIO» em todas as páginas do ensaio (nunca se confunde com a produção); vazio na produção. */
function naval_faixa_ensaio(): string
{
    if (!naval_ensaio()) return '';
    $v = htmlspecialchars(naval_ensaio_versao(), ENT_QUOTES, 'UTF-8');
    return '<div id="faixa-ensaio" role="status" style="position:fixed;left:0;right:0;bottom:0;z-index:2147483000;background:#273777;color:#fff;'
        . 'font:600 12px/1.4 system-ui,sans-serif;text-align:center;padding:4px 12px;letter-spacing:.04em;pointer-events:none">'
        . 'AMBIENTE DE ENSAIO' . ($v !== '' ? ' · versão ' . $v : '') . ' · não é a produção</div>';
}

function naval_ficheiro_env(): string
{
    return getenv('NAVAL_ENV') ?: naval_raiz_producao() . '/.env';
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
    if (naval_ensaio()) {
        $priv = rtrim($env['DADOS_PRIVADOS'] ?? '', '/\\') ?: dirname(naval_raiz_producao()) . '/dados-privados';
        $env['DADOS_PRIVADOS'] = $priv . '/ensaio';
        if (($env['PORTAL_URL'] ?? '') !== '') $env['PORTAL_URL'] = rtrim($env['PORTAL_URL'], '/') . '/ensaio';
        $env['ACTUALIZACAO_AUTOMATICA'] = '0';
        $env['NAVAL_AMBIENTE'] = 'ensaio';
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

/**
 * Grava (ou substitui) chaves no .env pelas ferramentas da linha de comandos, mantendo o resto do ficheiro e as
 * quebras de linha, depois de uma cópia de segurança (.env.antes-<etiqueta>-<data>). Devolve o nome da cópia.
 */
function naval_env_gravar(array $chaves, string $etiqueta): string
{
    $ficheiro = naval_ficheiro_env();
    $antes = is_file($ficheiro) ? (string) file_get_contents($ficheiro) : '';
    $copia = $ficheiro . '.antes-' . $etiqueta . '-' . date('Ymd-His');
    if ($antes !== '' && file_put_contents($copia, $antes, LOCK_EX) === false) throw new RuntimeException('Não foi possível fazer a cópia de segurança do .env.');
    $linhas = $antes === '' ? [] : (preg_split('/\r\n|\n|\r/', rtrim($antes, "\r\n")) ?: []);
    $fim = strpos($antes, "\r\n") !== false ? "\r\n" : "\n";
    foreach ($chaves as $k => $v) {
        $v = (string) $v;
        if (preg_match('/[\r\n]/', $v)) throw new RuntimeException('Valor inválido para ' . $k . '.');
        $feito = false;
        foreach ($linhas as $i => $l) {
            if (preg_match('/^\s*#?\s*' . preg_quote((string) $k, '/') . '\s*=/', $l)) {
                if (!$feito) $linhas[$i] = $k . '=' . $v;
                else unset($linhas[$i]);
                $feito = true;
            }
        }
        if (!$feito) $linhas[] = $k . '=' . $v;
    }
    if (file_put_contents($ficheiro, implode($fim, $linhas) . $fim, LOCK_EX) === false) throw new RuntimeException('Não foi possível gravar o .env.');
    return basename($copia);
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
