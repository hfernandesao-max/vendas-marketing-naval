<?php
/**
 * Cadastros de clientes RTM (páginas Cadastros, Carteira e Plano de visitas).
 *
 * Os dados são gerados pelo repositório privado rtm-clientes (GitHub Actions, a partir das
 * exportações «Clientes» do backoffice do RTM) no ficheiro saida/portal_rtm.json.
 * Onde se procura, por esta ordem:
 *   1. RTM_CLIENTES_JSON no .env (caminho completo);
 *   2. reports/data/cadastros.json (cópia manual);
 *   3. ../rtm-clientes/saida/portal_rtm.json ao lado da pasta do site (clone do repositório rtm-clientes).
 */
defined('NAVAL_NUCLEO') || exit;

/** Dados dos cadastros, ou null se nenhum ficheiro existir. */
function naval_cadastros_ler(): ?array
{
    $candidatos = array_filter([
        naval_config('RTM_CLIENTES_JSON'),
        naval_raiz() . '/reports/data/cadastros.json',
        dirname(naval_raiz()) . '/rtm-clientes/saida/portal_rtm.json',
    ]);
    foreach ($candidatos as $f) {
        if (is_file($f)) return naval_json_ler($f) ?: null;
    }
    return null;
}

/**
 * Âmbito do utilizador: só as suas rotas; NIF só para quem o pode ver; os cadastros só para quem
 * tem as páginas de cadastros e a carteira só para quem tem clientes, plano de visitas ou auditoria.
 */
function naval_cadastros_ambito(array $d, ?array $rotas, bool $veNif, bool $veCadastros, bool $veCarteira): array
{
    $dentro = function ($x) use ($rotas) { return $rotas === null || in_array((string) ($x['rota'] ?? ''), $rotas, true); };
    foreach (['cadastros', 'cadastros_diarios', 'carteira'] as $k) {
        if (isset($d[$k]) && is_array($d[$k])) $d[$k] = array_values(array_filter($d[$k], $dentro));
    }
    if (!$veNif) {
        foreach (['cadastros', 'carteira'] as $k) foreach ($d[$k] ?? [] as $i => $x) $d[$k][$i]['nif'] = '';
    }
    if (!$veCadastros) {
        $d['cadastros'] = [];
        $d['cadastros_diarios'] = [];
    }
    if (!$veCarteira) $d['carteira'] = [];
    return $d;
}
