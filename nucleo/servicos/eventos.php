<?php
/**
 * Eventos entre módulos: cada módulo declara na sua ficha (nucleo/modulos/*.json) os eventos que publica e os que
 * escuta. Um evento publicado fica registado (registos/eventos-AAAA-MM.jsonl) e chama os ouvintes dos módulos ligados
 * que o escutam — é aqui que se ligam os automatismos novos, sem mexer em quem publica.
 *
 * Regras:
 *   - só se publica um evento declarado em «publica» de algum módulo (um nome errado é um erro de programação);
 *   - um ouvinte só corre se o módulo dele estiver ligado e declarar o evento em «escuta»;
 *   - um ouvinte nunca trava quem publica: as falhas ficam no registo de erros. Os ouvintes devem ser rápidos (marcam
 *     trabalho para o ciclo periódico em vez de o fazer no pedido de quem clicou).
 */
defined('NAVAL_NUCLEO') || exit;

/** evento => [[módulo, serviço, função]]: quem reage a cada evento. */
const NAVAL_EVENTOS_OUVINTES = [
    // fecho de uma carrinha ou da caixa: o relatório de vendas do dia pode ter ficado pronto (o ciclo gera-o já)
    'carrinha.descarga_conciliada' => [['relatorio-vendas', 'relatorio-vendas', 'naval_rv_evento_rever']],
    'caixa.aprovada' => [['relatorio-vendas', 'relatorio-vendas', 'naval_rv_evento_rever']],
];

/** Eventos declarados como publicados pelos módulos: [evento => módulo]. */
function naval_eventos_declarados(): array
{
    naval_servico('modulos');
    $out = [];
    foreach (naval_modulos() as $id => $m) {
        foreach ((array) ($m['eventos']['publica'] ?? []) as $e) $out[(string) $e] = $id;
    }
    return $out;
}

/**
 * Publica um evento. Devolve quantos ouvintes correram. $o (testes): ouvintes (substitui o mapa), estados (dos
 * módulos), registar (false não escreve o registo).
 */
function naval_evento(string $nome, array $dados = [], array $o = []): int
{
    $declarados = naval_eventos_declarados();
    if (!isset($declarados[$nome])) throw new LogicException('Evento não declarado em nenhum módulo: ' . $nome);
    if (($o['registar'] ?? true) !== false) {
        try {
            naval_jsonl_acrescentar(naval_pasta_privada() . '/registos/eventos-' . date('Y-m') . '.jsonl',
                ['em' => date('Y-m-d H:i:s'), 'evento' => $nome, 'modulo' => $declarados[$nome], 'dados' => $dados]);
        } catch (Throwable $e) {
            error_log('[eventos] registo: ' . $e->getMessage());
        }
    }
    $mapa = $o['ouvintes'] ?? NAVAL_EVENTOS_OUVINTES;
    $estados = $o['estados'] ?? naval_modulos_estados();
    $modulos = naval_modulos();
    $n = 0;
    foreach ((array) ($mapa[$nome] ?? []) as [$modulo, $servico, $funcao]) {
        if (($estados[$modulo] ?? '') !== 'ligado') continue;
        if (!in_array($nome, (array) ($modulos[$modulo]['eventos']['escuta'] ?? []), true)) continue;
        try {
            if ($servico !== '') naval_servico($servico);
            if (is_callable($funcao)) {
                $funcao($nome, $dados);
                $n++;
            }
        } catch (Throwable $e) {
            error_log('[eventos] ' . $nome . ' → ' . (is_string($funcao) ? $funcao : 'ouvinte') . ': ' . $e->getMessage());
        }
    }
    return $n;
}

/** Publica sem nunca lançar (para quem publica a meio de uma operação já gravada). */
function naval_evento_seguro(string $nome, array $dados = []): void
{
    try {
        naval_servico('eventos');
        naval_evento($nome, $dados);
    } catch (Throwable $e) {
        error_log('[eventos] ' . $nome . ': ' . $e->getMessage());
    }
}
