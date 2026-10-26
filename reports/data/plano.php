<?php
/**
 * Plano de cargas (página «Plano de cargas» do portal).
 *
 * GET  ?data=AAAA-MM-DD     plano do dia, listas e rotas
 * GET  ?historico=1         dias com plano
 * GET  ?xlsx=AAAA-MM-DD     Excel da última publicação desse dia
 * POST {accao: guardar | publicar | reenviar | listas | teste_email, csrf, ...}
 *
 * Ver: nível V (ou V·r, só as suas rotas). Preencher, publicar e gerir as listas: nível G (Logística).
 */
ini_set('display_errors', '0');
header('Cache-Control: no-store');
require_once __DIR__ . '/../../geo/auth/auth_check.php';
require_once __DIR__ . '/../../geo/auth/cargas.php';
require_once __DIR__ . '/../../geo/auth/email.php';
naval_exigir(['plano-cargas']);

function resp(array $d, int $codigo = 200): void
{
    http_response_code($codigo);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}

$gere = naval_pode('plano-cargas', 'G') || naval_perfil() === 'SADM';
$rotasOk = naval_rotas();
$filtrar = function (array $rotas) use ($rotasOk) {
    if ($rotasOk === null) return $rotas;
    return array_values(array_filter($rotas, function ($r) use ($rotasOk) { return in_array((string) $r['rota'], $rotasOk, true); }));
};
$rotasPortal = json_decode((string) @file_get_contents(__DIR__ . '/rotas.json'), true) ?: [];

// Vista do plano para quem o pede (rotas filtradas para V·r; sem rascunho para quem não gere)
function vista_plano(?array $p, bool $gere, callable $filtrar): ?array
{
    if (!$p) return null;
    if (!$gere) {
        if (empty($p['publicado'])) return ['data' => $p['data'], 'estado' => 'rascunho', 'rotas' => [], 'publicado' => null, 'envios' => []];
        $p['rotas'] = $p['publicado']['rotas'];
    }
    $p['rotas'] = $filtrar($p['rotas']);
    if (!empty($p['publicado'])) $p['publicado']['rotas'] = $filtrar($p['publicado']['rotas']);
    if (!$gere) unset($p['envios']);
    return $p;
}

function ligacao_portal(): string
{
    $u = naval_env()['PORTAL_URL'] ?? '';
    return $u ? rtrim($u, '/') . '/reports/#plano-cargas' : '';
}

function publicar_email(array $p, array $listas): array
{
    $pub = $p['publicado'];
    $dest = $listas['destinatarios'];
    [$a, $m, $d] = explode('-', $p['data']);
    $assunto = 'Plano de cargas · ' . $d . '-' . $m . '-' . $a . ($pub['versao'] > 1 ? ' · RECTIFICADO (versão ' . $pub['versao'] . ')' : '');
    $xlsx = naval_plano_pasta() . '/' . $p['data'] . '-v' . $pub['versao'] . '.xlsx';
    $anexos = is_file($xlsx) ? [['nome' => 'PLANO_DE_CARGAS_' . $d . '.' . $m . '.' . $a . ($pub['versao'] > 1 ? '_v' . $pub['versao'] : '') . '.xlsx',
        'tipo' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'conteudo' => file_get_contents($xlsx)]] : [];
    $r = naval_enviar_email($dest, $assunto, naval_plano_email_html($p, $listas['artigos'], ligacao_portal()), $anexos);
    return ['em' => date('Y-m-d H:i:s'), 'por' => naval_utilizador()['login'], 'versao' => $pub['versao'], 'para' => array_column($dest, 'email'), 'ok' => $r['ok'], 'erro' => $r['erro']];
}

// ---------------- Leitura ----------------
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if (isset($_GET['xlsx'])) {
        $data = (string) $_GET['xlsx'];
        $p = naval_data_valida($data) ? naval_plano_ler($data) : null;
        $v = (int) ($p['publicado']['versao'] ?? 0);
        $f = naval_plano_pasta() . '/' . $data . '-v' . $v . '.xlsx';
        if (!$v || !is_file($f) || !naval_pode_exportar('plano-cargas') || naval_rotas() !== null) {
            resp(['erro' => 'Excel não disponível.'], 404);
        }
        naval_registar('exportacao', ['pagina' => 'plano-cargas', 'data' => $data, 'versao' => $v]);
        [$a, $m, $d] = explode('-', $data);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="PLANO_DE_CARGAS_' . $d . '.' . $m . '.' . $a . '.xlsx"');
        readfile($f);
        exit;
    }
    if (isset($_GET['historico'])) {
        $out = [];
        foreach (naval_plano_dias(120) as $p) {
            $pub = $p['publicado'] ?? null;
            $rs = $filtrar($pub ? $pub['rotas'] : ($gere ? $p['rotas'] : []));
            if (!$gere && !$pub) continue;
            $saem = array_filter($rs, function ($r) { return $r['sai']; });
            $carga = 0;
            foreach ($saem as $r) $carga += array_sum($r['quantidades']);
            $env = $p['envios'] ?? [];
            $ult = $env ? end($env) : null;
            $out[] = ['data' => $p['data'], 'estado' => $pub ? ($gere && ($p['rev'] ?? 0) > ($pub['rev'] ?? 0) ? 'alterado' : 'publicado') : 'rascunho',
                'versao' => $pub['versao'] ?? 0, 'rotas' => count($saem), 'carga' => $carga, 'publicado_em' => $pub['em'] ?? null,
                'publicado_por' => $pub['por_nome'] ?? null, 'email' => $gere && $ult ? ($ult['ok'] ? 'enviado' : 'falhou') : null];
        }
        resp(['dias' => $out]);
    }
    $data = (string) ($_GET['data'] ?? date('Y-m-d'));
    if (!naval_data_valida($data)) resp(['erro' => 'Data inválida.'], 400);
    $p = naval_plano_ler($data);
    // Plano anterior mais recente (para «Copiar do dia anterior»)
    $anterior = null;
    foreach (naval_plano_dias(60) as $x) if ($x['data'] < $data && ($gere || !empty($x['publicado']))) { $anterior = $x['data']; break; }
    $listas = naval_plano_listas();
    if (!$gere) $listas['destinatarios'] = [];
    resp([
        'data' => $data,
        'plano' => vista_plano($p, $gere, $filtrar),
        'anterior' => $anterior,
        'rotas' => $filtrar(array_map(function ($r) { return ['rota' => (string) $r['rota'], 'promotor' => $r['promotor'] ?? '']; }, $rotasPortal)),
        'listas' => $listas,
        'gere' => $gere,
        'exportar' => naval_pode_exportar('plano-cargas') && $rotasOk === null,
        'email_configurado' => naval_email_configurado(),
        'ligacao' => ligacao_portal(),
    ]);
}

// ---------------- Alterações ----------------
$q = json_decode((string) file_get_contents('php://input'), true) ?: [];
$accao = (string) ($q['accao'] ?? '');
if (!hash_equals((string) ($_SESSION['csrf'] ?? ''), (string) ($q['csrf'] ?? ''))) resp(['ok' => false, 'erro' => 'Pedido inválido. Actualize a página e tente de novo.'], 403);
if (!$gere) {
    naval_registar('alteracao_recusada', ['accao' => 'plano_' . $accao, 'motivo' => 'perfil']);
    resp(['ok' => false, 'erro' => 'O seu perfil não permite alterar o plano de cargas.'], 403);
}
$eu = naval_utilizador();
$listas = naval_plano_listas();

switch ($accao) {
    case 'guardar':
    case 'publicar':
        $data = (string) ($q['data'] ?? '');
        if (!naval_data_valida($data)) resp(['ok' => false, 'erro' => 'Data inválida.'], 400);
        $p = naval_plano_ler($data) ?: ['data' => $data, 'rev' => 0, 'criado' => date('Y-m-d H:i:s'), 'criado_por' => $eu['login'], 'rotas' => [], 'publicado' => null, 'envios' => []];
        if ((int) ($q['rev'] ?? -1) !== (int) $p['rev']) {
            resp(['ok' => false, 'erro' => 'O plano foi alterado por outra pessoa (' . ($p['alterado_por'] ?? '') . ') entretanto. Actualize a página.', 'conflito' => true], 409);
        }
        $rotas = [];
        $vistas = [];
        foreach ((array) ($q['rotas'] ?? []) as $r) {
            $r = naval_plano_rota_limpa((array) $r, $listas['artigos']);
            if ($r['rota'] === '' || isset($vistas[$r['rota']])) continue;
            $vistas[$r['rota']] = true;
            $rotas[] = $r;
        }
        $p['rotas'] = $rotas;
        $p['rev'] = (int) $p['rev'] + 1;
        $p['alterado'] = date('Y-m-d H:i:s');
        $p['alterado_por'] = $eu['login'];

        if ($accao === 'guardar') {
            naval_plano_gravar($p);
            resp(['ok' => true, 'plano' => $p]);
        }

        // Publicar: valida, guarda a versão publicada e o Excel, aprende as listas e envia o email
        $erros = naval_plano_erros($rotas, $listas['artigos']);
        if (!$listas['destinatarios']) $erros[] = 'A lista de destinatários está vazia (Listas → Destinatários).';
        if ($erros) resp(['ok' => false, 'erro' => 'Corrija antes de publicar:', 'erros' => $erros], 400);
        $xlsx = base64_decode((string) ($q['xlsx'] ?? ''), true);
        if (!$xlsx || substr($xlsx, 0, 2) !== 'PK' || strlen($xlsx) > 3 * 1024 * 1024) resp(['ok' => false, 'erro' => 'O Excel do plano não chegou ao servidor. Tente de novo.'], 400);
        $versao = (int) ($p['publicado']['versao'] ?? 0) + 1;
        file_put_contents(naval_plano_pasta() . '/' . $data . '-v' . $versao . '.xlsx', $xlsx, LOCK_EX);
        $p['publicado'] = ['versao' => $versao, 'rev' => $p['rev'], 'em' => date('Y-m-d H:i:s'), 'por' => $eu['login'], 'por_nome' => $eu['nome'] ?? $eu['login'],
            'nota' => naval_texto($q['nota'] ?? '', 300), 'rotas' => $rotas];
        naval_plano_aprender($listas, array_filter($rotas, function ($r) { return $r['sai']; }));
        naval_plano_gravar_listas($listas);
        $envio = publicar_email($p, $listas);
        $p['envios'][] = $envio;
        naval_plano_gravar($p);
        naval_registar('plano_publicado', ['data' => $data, 'versao' => $versao, 'rotas' => count(array_filter($rotas, function ($r) { return $r['sai']; })), 'email' => $envio['ok'] ? 'enviado a ' . count($envio['para']) : 'falhou']);
        resp(['ok' => true, 'plano' => $p, 'listas' => $listas, 'email' => $envio]);

    case 'reenviar':
        $data = (string) ($q['data'] ?? '');
        $p = naval_data_valida($data) ? naval_plano_ler($data) : null;
        if (!$p || empty($p['publicado'])) resp(['ok' => false, 'erro' => 'Este plano ainda não foi publicado.'], 400);
        $envio = publicar_email($p, $listas);
        $p['envios'][] = $envio;
        naval_plano_gravar($p);
        naval_registar('plano_reenviado', ['data' => $data, 'versao' => $p['publicado']['versao'], 'email' => $envio['ok'] ? 'enviado' : 'falhou']);
        resp(['ok' => $envio['ok'], 'erro' => $envio['erro'], 'plano' => $p, 'email' => $envio]);

    case 'listas':
        $n = (array) ($q['listas'] ?? []);
        $l = $listas;
        foreach (['armazens', 'zonas', 'viaturas', 'transportadoras'] as $k) {
            if (isset($n[$k])) $l[$k] = naval_lista_unica((array) $n[$k]);
        }
        if (isset($n['motoristas'])) {
            $l['motoristas'] = [];
            naval_plano_aprender($l, array_map(function ($m) { return ['motorista' => $m['nome'] ?? '', 'telefone' => $m['telefone'] ?? '', 'matricula' => $m['matricula'] ?? '', 'viatura' => $m['viatura'] ?? '', 'transportadora' => $m['transportadora'] ?? '']; }, (array) $n['motoristas']));
        }
        if (isset($n['destinatarios'])) {
            $l['destinatarios'] = [];
            foreach ((array) $n['destinatarios'] as $d) {
                $e = trim((string) ($d['email'] ?? ''));
                if ($e === '') continue;
                if (!naval_email_valido($e)) resp(['ok' => false, 'erro' => 'Email inválido: ' . $e], 400);
                foreach ($l['destinatarios'] as $x) if (strcasecmp($x['email'], $e) === 0) continue 2;
                $l['destinatarios'][] = ['nome' => naval_texto($d['nome'] ?? ''), 'email' => $e];
            }
        }
        if (isset($n['artigos'])) {
            $art = [];
            foreach ((array) $n['artigos'] as $a) {
                $nome = naval_texto($a['nome'] ?? '', 40);
                $id = preg_replace('/[^a-z0-9]+/', '', strtolower(naval_chave_texto($a['id'] ?? $nome)));
                if ($nome === '' || $id === '' || isset($art[$id])) continue;
                $art[$id] = ['id' => $id, 'nome' => $nome];
            }
            if (!$art) resp(['ok' => false, 'erro' => 'Tem de haver pelo menos um artigo.'], 400);
            $l['artigos'] = array_values($art);
        }
        naval_plano_gravar_listas($l);
        naval_registar('plano_listas', ['destinatarios' => count($l['destinatarios']), 'motoristas' => count($l['motoristas']), 'zonas' => count($l['zonas'])]);
        resp(['ok' => true, 'listas' => $l]);

    case 'teste_email':
        $r = naval_enviar_email([['email' => $eu['email'] ?? '', 'nome' => $eu['nome'] ?? '']], 'Teste de envio · Portal RTM',
            '<p style="font-family:Segoe UI,Arial,sans-serif">Este é um email de teste do Portal RTM. Se o recebeu, o envio do plano de cargas está a funcionar.</p>');
        naval_registar('email_teste', ['ok' => $r['ok']]);
        resp(['ok' => $r['ok'], 'erro' => $r['erro'], 'para' => $eu['email'] ?? '']);
}
resp(['ok' => false, 'erro' => 'Acção desconhecida.'], 400);
