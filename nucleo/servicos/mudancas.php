<?php
/**
 * Sinal de mudança para o tempo real da app e do portal (padrão «conditional polling» / ETag): em vez de reler todos os
 * números de minuto a minuto, o cliente pergunta com frequência (20 s) uma impressão digital barata do que pode ter
 * mudado e só relê os dados quando ela muda. Mais leve do que ligações abertas (SSE/WebSocket) num Apache com PHP, e
 * funciona atrás de qualquer proxy.
 *
 * Entram na impressão digital:
 *   - Pinguim BO: documentos de hoje (quantos, o último ID e a última hora de registo; anulações mudam o estado), lido
 *     no máximo de 15 em 15 segundos para todos os utilizadores juntos (cache no servidor);
 *   - portal: data de alteração dos registos de hoje (PO, conciliação das carrinhas, fecho de caixa, ordens ao Pinguim BO).
 * Junta os contadores do que pede acção à pessoa (ex.: PO por aprovar para quem aprova).
 */
defined('NAVAL_NUCLEO') || exit;

const NAVAL_MUDANCAS_CACHE = 15;   // segundos entre leituras do Pinguim BO

/** Impressão digital dos documentos de hoje no Pinguim BO (cache de 15 s partilhada). '' sem ligação. */
function naval_mudancas_pinguim(?int $agora = null): string
{
    $agora = $agora ?? time();
    $f = naval_pasta('fontes') . '/mudancas-pinguim.json';
    $c = naval_json_ler($f);
    if ($c && $agora - (int) ($c['t'] ?? 0) < NAVAL_MUDANCAS_CACHE) return (string) $c['h'];
    if (!function_exists('naval_bd_configurada')) naval_servico('bd');
    if (!naval_bd_configurada()) return '';
    try {
        $hoje = date('Y-m-d', $agora);
        $x = naval_bd_consultar("SELECT COUNT(*) AS N, MAX(DH.ID) AS ULTIMO, CONVERT(VARCHAR(19), MAX(DH.CLOCK_DATE), 120) AS HORA,
                SUM(CAST(COALESCE(DH.CALC_ISCANCELED, 0) AS INT)) AS ANULADOS, SUM(COALESCE(DH.DOCSTATUS_ID, 0)) AS ESTADOS
            FROM DOCHEADER DH WHERE DH.DELETED = 0 AND DH.DOC_DATE >= :de AND DH.DOC_DATE < DATEADD(day, 1, :ate)", [':de' => $hoje, ':ate' => $hoje]);
        $h = sha1(json_encode($x[0] ?? []));
    } catch (Throwable $e) {
        error_log('[mudanças] ' . $e->getMessage());
        $h = '';
    }
    naval_json_gravar($f, ['t' => $agora, 'h' => $h], false);
    return $h;
}

/** Última alteração dos registos do portal de hoje (o maior «mtime» de cada pasta, com os ficheiros lá dentro). */
function naval_mudancas_portal(?int $agora = null): array
{
    $agora = $agora ?? time();
    $hoje = date('Y-m-d', $agora);
    $pastas = [
        'requisicoes' => naval_pasta_privada() . '/requisicoes/' . date('Y', $agora),
        'carrinha' => naval_pasta_privada() . '/carrinha/' . $hoje,
        'caixa' => naval_pasta_privada() . '/caixa',
        'ordens' => naval_pasta_privada() . '/ordens-pinguim',
    ];
    $out = [];
    foreach ($pastas as $k => $p) {
        $m = is_dir($p) ? (int) @filemtime($p) : 0;
        // os ficheiros alterados hoje (alterar um ficheiro não muda a data da pasta)
        foreach ((array) glob($p . '/*.json') as $f) $m = max($m, (int) @filemtime((string) $f));
        $out[$k] = $m;
    }
    return $out;
}

/** O que pede acção a esta pessoa (contadores leves). */
function naval_mudancas_accoes(): array
{
    $a = [];
    if (function_exists('naval_pode') && naval_pode('requisicoes-aprovacao', 'G')) {
        naval_servico('requisicoes');
        $n = 0;
        foreach (naval_req_ficheiros(date('Y')) as $f) {
            $r = naval_json_ler((string) $f);
            if ($r && $r['estado'] === 'emitida' && naval_req_precisa_aprovacao($r) && empty($r['aprovada_em']) && naval_rota_permitida((string) $r['rota'])) $n++;
        }
        $a['po_por_aprovar'] = $n;
    }
    return $a;
}

/** Resposta do sinal: impressão digital (muda quando algo muda) e as acções pendentes. */
function naval_mudancas(?int $agora = null): array
{
    $p = naval_mudancas_portal($agora);
    $acc = naval_mudancas_accoes();
    $pinguim = naval_mudancas_pinguim($agora);
    return ['ok' => true, 'marca' => substr(sha1($pinguim . '|' . json_encode($p) . '|' . json_encode($acc)), 0, 16), 'pinguim' => $pinguim !== '',
        'accoes' => (object) $acc, 'hora' => date('Y-m-d H:i:s'), 'intervalo' => 20];
}
