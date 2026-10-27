<?php
/**
 * Recepção dos relatórios de violação da Content-Security-Policy (report-uri / report-to), para saber,
 * antes de aplicar a política completa (CSP_APLICAR=1), que recursos a página carrega fora da lista.
 *
 * Não exige sessão (os navegadores enviam os relatórios sem garantias de cookies). Por isso: só POST,
 * corpo até 16 KB, só os campos da norma, endereços sem parâmetros (podem ter dados), no máximo
 * 300 relatórios por dia, gravados na pasta privada (registos/csp-AAAA-MM.jsonl). Responde sempre 204.
 */
require_once __DIR__ . '/../../nucleo/arranque.php';

const NAVAL_CSP_MAX_DIA = 300;

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit;
}
$corpo = (string) file_get_contents('php://input', false, null, 0, 16384);
$j = json_decode($corpo, true);
$relatorios = [];
if (is_array($j) && isset($j['csp-report']) && is_array($j['csp-report'])) {
    $relatorios[] = $j['csp-report'];                                   // report-uri (formato antigo)
} elseif (is_array($j)) {
    foreach ($j as $r) if (is_array($r) && ($r['type'] ?? '') === 'csp-violation' && is_array($r['body'] ?? null)) $relatorios[] = $r['body'];   // report-to
}
$limpo = function ($u) {
    $u = (string) $u;
    $p = parse_url($u);
    if (!is_array($p) || empty($p['host'])) return substr($u, 0, 40);   // 'inline', 'eval', 'self'…
    return ($p['scheme'] ?? '') . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '') . substr((string) ($p['path'] ?? ''), 0, 120);
};
if ($relatorios) {
    naval_exclusivo('csp', function () use ($relatorios, $limpo) {
        $cont = naval_pasta('registos') . '/csp-contagem.json';
        $c = naval_json_ler($cont);
        $hoje = date('Y-m-d');
        $n = ($c['dia'] ?? '') === $hoje ? (int) ($c['n'] ?? 0) : 0;
        foreach ($relatorios as $r) {
            if ($n >= NAVAL_CSP_MAX_DIA) break;
            $n++;
            naval_jsonl_acrescentar(naval_pasta('registos') . '/csp-' . date('Y-m') . '.jsonl', [
                'data' => date('Y-m-d H:i:s'),
                'pagina' => $limpo($r['document-uri'] ?? $r['documentURL'] ?? ''),
                'directiva' => substr((string) ($r['effective-directive'] ?? $r['effectiveDirective'] ?? $r['violated-directive'] ?? ''), 0, 40),
                'bloqueado' => $limpo($r['blocked-uri'] ?? $r['blockedURL'] ?? ''),
                'modo' => substr((string) ($r['disposition'] ?? ''), 0, 10),
            ]);
        }
        naval_json_gravar($cont, ['dia' => $hoje, 'n' => $n], false);
    });
}
http_response_code(204);
