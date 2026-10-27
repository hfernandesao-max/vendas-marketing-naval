<?php
/**
 * Plano de cargas diário (antes: Excel «REPORT DRIVER MARKETING TRANSPORTES» enviado por email).
 *
 * Fica em dados-privados/plano-cargas/ (fora da pasta pública e do GitHub):
 *   AAAA-MM-DD.json        plano do dia (rascunho e versão publicada)
 *   AAAA-MM-DD-vN.xlsx     Excel enviado em cada publicação
 *   listas.json            motoristas, zonas, armazéns, viaturas, transportadoras, artigos e destinatários
 */
defined('NAVAL_NUCLEO') || exit;
require_once __DIR__ . '/email.php';
require_once __DIR__ . '/rotas.php';

function naval_plano_pasta(): string
{
    return naval_pasta('plano-cargas');
}

const NAVAL_ARTIGOS_PADRAO = [['id' => 'fraldas', 'nome' => 'Fraldas'], ['id' => 'sabao', 'nome' => 'Sabão'], ['id' => 'sabonete', 'nome' => 'Sabonete']];

/** Listas do plano. Na primeira utilização, preenchidas a partir do histórico do plano de cargas (cargas.json). */
function naval_plano_listas(): array
{
    $l = naval_json_ler(naval_plano_pasta() . '/listas.json');
    if (!$l) {
        $l = ['armazens' => ['HIGITEC'], 'artigos' => NAVAL_ARTIGOS_PADRAO, 'motoristas' => [], 'zonas' => [], 'viaturas' => [], 'transportadoras' => [], 'destinatarios' => []];
        $hist = naval_json_ler(naval_raiz() . '/reports/data/cargas.json');
        usort($hist, function ($a, $b) { return strcmp($a['data'] ?? '', $b['data'] ?? ''); });
        foreach ($hist as $c) {
            naval_plano_aprender($l, [[
                'motorista' => $c['motorista'] ?? '', 'telefone' => '', 'matricula' => $c['matricula'] ?? '', 'viatura' => $c['viatura'] ?? '',
                'transportadora' => $c['transportadora'] ?? '', 'armazem' => '', 'zonas' => array_filter(array_map('trim', explode(',', (string) ($c['zonas'] ?? '')))),
            ]]);
        }
    }
    foreach (['armazens', 'motoristas', 'zonas', 'viaturas', 'transportadoras', 'destinatarios'] as $k) if (!isset($l[$k]) || !is_array($l[$k])) $l[$k] = [];
    if (empty($l['artigos'])) $l['artigos'] = NAVAL_ARTIGOS_PADRAO;
    return $l;
}

function naval_plano_gravar_listas(array $l): void
{
    foreach (['zonas', 'armazens', 'viaturas', 'transportadoras'] as $k) {
        $l[$k] = array_values($l[$k]);
        usort($l[$k], 'strcoll');
    }
    usort($l['motoristas'], function ($a, $b) { return strcoll($a['nome'], $b['nome']); });
    naval_json_gravar(naval_plano_pasta() . '/listas.json', $l);
}

/** «Estalagem E Moagem» → «Estalagem e Moagem» (palavras de ligação em minúsculas). */
function naval_ligacoes_minusculas(string $s): string
{
    return preg_replace_callback('/ (E|De|Do|Da|Dos|Das) /u', function ($m) { return strtolower($m[0]); }, $s);
}

/** Acrescenta às listas os motoristas, zonas, armazéns, viaturas e transportadoras usados num plano. */
function naval_plano_aprender(array &$l, array $rotas): void
{
    $junta = function (array &$lista, string $v) {
        $v = naval_texto($v);
        if ($v === '') return;
        $k = naval_chave_texto($v);
        foreach ($lista as $x) if (naval_chave_texto($x) === $k) return;
        $lista[] = $v;
    };
    foreach ($rotas as $r) {
        foreach (($r['zonas'] ?? []) as $z) $junta($l['zonas'], naval_ligacoes_minusculas((string) $z));
        $junta($l['armazens'], (string) ($r['armazem'] ?? ''));
        $junta($l['viaturas'], (string) ($r['viatura'] ?? ''));
        $junta($l['transportadoras'], (string) ($r['transportadora'] ?? ''));
        $nome = naval_texto($r['motorista'] ?? '');
        if ($nome === '') continue;
        $k = naval_chave_texto($nome);
        $novo = ['nome' => $nome, 'telefone' => naval_texto($r['telefone'] ?? '', 30), 'matricula' => naval_texto($r['matricula'] ?? '', 20),
            'viatura' => naval_texto($r['viatura'] ?? '', 40), 'transportadora' => naval_texto($r['transportadora'] ?? '', 60)];
        $achou = false;
        foreach ($l['motoristas'] as $i => $m) {
            if (naval_chave_texto($m['nome']) !== $k) continue;
            foreach (['telefone', 'matricula', 'viatura', 'transportadora'] as $c) if ($novo[$c] !== '') $l['motoristas'][$i][$c] = $novo[$c];
            $achou = true;
            break;
        }
        if (!$achou) $l['motoristas'][] = $novo;
    }
}

/** Lista de nomes sem repetidos (ignora maiúsculas, acentos e pontuação). */
function naval_lista_unica(array $v): array
{
    $out = [];
    $vistos = [];
    foreach ($v as $x) {
        $x = naval_texto($x);
        $k = naval_chave_texto($x);
        if ($k === '' || isset($vistos[$k])) continue;
        $vistos[$k] = true;
        $out[] = $x;
    }
    return $out;
}

/** Limpa os dados de uma rota vindos do portal. */
function naval_plano_rota_limpa(array $r, array $artigos): array
{
    $zonas = [];
    foreach ((array) ($r['zonas'] ?? []) as $z) {
        $z = naval_texto($z);
        if ($z === '') continue;
        $k = naval_chave_texto($z);
        foreach ($zonas as $x) if (naval_chave_texto($x) === $k) continue 2;
        $zonas[] = $z;
        if (count($zonas) >= 40) break;
    }
    $q = [];
    foreach ($artigos as $a) {
        $v = $r['quantidades'][$a['id']] ?? 0;
        $q[$a['id']] = max(0, min(100000, (int) round((float) $v)));
    }
    return [
        'rota' => naval_texto($r['rota'] ?? '', 20),
        'vendedor' => naval_texto($r['vendedor'] ?? ''),
        'sai' => !empty($r['sai']),
        'armazem' => naval_texto($r['armazem'] ?? '', 60),
        'viagens' => max(0, min(20, (int) ($r['viagens'] ?? 1))),
        'zonas' => $zonas,
        'quantidades' => $q,
        'motorista' => naval_texto($r['motorista'] ?? ''),
        'telefone' => preg_replace('/[^0-9+ ]/', '', naval_texto($r['telefone'] ?? '', 30)),
        'matricula' => strtoupper(naval_texto($r['matricula'] ?? '', 20)),
        'viatura' => naval_texto($r['viatura'] ?? '', 40),
        'transportadora' => naval_texto($r['transportadora'] ?? '', 60),
        'factura' => naval_texto($r['factura'] ?? '', 40),
        'obs' => naval_texto($r['obs'] ?? '', 300),
    ];
}

/** Problemas que impedem a publicação (só as rotas que saem). */
function naval_plano_erros(array $rotas, array $artigos): array
{
    $e = [];
    $saem = array_filter($rotas, function ($r) { return $r['sai']; });
    if (!$saem) $e[] = 'Nenhuma rota sai neste dia.';
    foreach ($saem as $r) {
        $n = 'Rota ' . $r['rota'];
        if ($r['motorista'] === '') $e[] = $n . ': falta o motorista.';
        if ($r['matricula'] === '') $e[] = $n . ': falta a matrícula.';
        if (!$r['zonas']) $e[] = $n . ': indique pelo menos uma zona.';
        if (array_sum($r['quantidades']) <= 0) $e[] = $n . ': a carga está a zero.';
    }
    return $e;
}

function naval_plano_ficheiro(string $data): string
{
    return naval_plano_pasta() . '/' . $data . '.json';
}

function naval_plano_ler(string $data): ?array
{
    return naval_json_ler(naval_plano_ficheiro($data)) ?: null;
}

function naval_plano_gravar(array $p): void
{
    naval_json_gravar(naval_plano_ficheiro($p['data']), $p);
}

/** Dias com plano (mais recentes primeiro). */
function naval_plano_dias(int $max = 400): array
{
    $out = [];
    $fich = glob(naval_plano_pasta() . '/????-??-??.json') ?: [];
    rsort($fich);
    foreach (array_slice($fich, 0, $max) as $f) {
        $p = json_decode((string) file_get_contents($f), true);
        if (is_array($p)) $out[] = $p;
    }
    return $out;
}

/** Linhas no formato de cargas.json (página Stock por carrinha e escoamento), a partir das versões publicadas. */
function naval_plano_para_cargas(): array
{
    $out = [];
    foreach (naval_plano_dias() as $p) {
        $pub = $p['publicado']['rotas'] ?? null;
        if (!$pub) continue;
        foreach ($pub as $r) {
            if (empty($r['sai'])) continue;
            $q = $r['quantidades'] ?? [];
            $linha = [
                'chave' => $p['data'] . '|' . $r['rota'], 'data' => $p['data'], 'rota' => $r['rota'], 'promotor' => $r['vendedor'],
                'motorista' => $r['motorista'], 'matricula' => $r['matricula'], 'viatura' => $r['viatura'], 'transportadora' => $r['transportadora'],
                'zonas' => implode(', ', $r['zonas']), 'n_zonas' => count($r['zonas']),
            ];
            $tot = 0;
            foreach ($q as $id => $v) { $linha['carga_' . $id] = (float) $v; $tot += $v; }
            foreach (['fraldas', 'sabao', 'sabonete'] as $id) if (!isset($linha['carga_' . $id])) $linha['carga_' . $id] = 0.0;
            $linha['carga'] = (float) $tot;
            $out[] = $linha;
        }
    }
    return $out;
}

/** Corpo do email (HTML simples, legível em qualquer leitor de correio). */
function naval_plano_email_html(array $p, array $artigos, string $ligacao): string
{
    $h = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
    $pub = $p['publicado'];
    [$a, $m, $d] = explode('-', $p['data']);
    $saem = array_values(array_filter($pub['rotas'], function ($r) { return $r['sai']; }));
    $tot = array_fill_keys(array_column($artigos, 'id'), 0);
    $th = 'style="background:#1E4FB0;color:#fff;padding:7px 9px;text-align:left;font-size:12px;border:1px solid #1E4FB0"';
    $td = 'style="padding:7px 9px;border:1px solid #D5DEEF;font-size:12.5px;vertical-align:top"';
    $tdn = 'style="padding:7px 9px;border:1px solid #D5DEEF;font-size:12.5px;vertical-align:top;text-align:right"';
    $linhas = '';
    foreach ($saem as $r) {
        $q = '';
        foreach ($artigos as $ar) { $v = (int) ($r['quantidades'][$ar['id']] ?? 0); $tot[$ar['id']] += $v; $q .= '<td ' . $tdn . '>' . number_format($v, 0, ',', ' ') . '</td>'; }
        $linhas .= '<tr><td ' . $td . '><b>' . $h($r['rota']) . '</b><br>' . $h($r['vendedor']) . '</td><td ' . $td . '>' . $h($r['motorista']) . '<br><span style="color:#5B6B8C">' . $h($r['telefone']) . '</span></td>'
            . '<td ' . $td . '>' . $h($r['matricula']) . '<br><span style="color:#5B6B8C">' . $h(trim($r['viatura'] . ' · ' . $r['transportadora'], ' ·')) . '</span></td>'
            . '<td ' . $td . '>' . $h(implode(', ', $r['zonas'])) . '</td>' . $q . '<td ' . $td . '>' . $h($r['factura']) . '</td></tr>';
    }
    $cab = '';
    $rod = '';
    foreach ($artigos as $ar) { $cab .= '<th ' . $th . '>' . $h($ar['nome']) . '</th>'; $rod .= '<td ' . $tdn . '><b>' . number_format($tot[$ar['id']], 0, ',', ' ') . '</b></td>'; }
    $rect = ($pub['versao'] ?? 1) > 1 ? '<p style="margin:0 0 12px;padding:9px 12px;background:#FFF4E5;border-left:4px solid #F59E0B;font-size:13px">Rectificação: esta versão (' . (int) $pub['versao'] . ') substitui o plano enviado antes.' . ($pub['nota'] ? ' ' . $h($pub['nota']) : '') . '</p>' : '';
    $nota = ($pub['versao'] ?? 1) <= 1 && !empty($pub['nota']) ? '<p style="margin:0 0 12px;font-size:13px">' . $h($pub['nota']) . '</p>' : '';
    return '<div style="font-family:Segoe UI,Arial,sans-serif;color:#0B1735;max-width:1000px">'
        . '<p style="margin:0 0 4px;font-size:12px;color:#1E4FB0;font-weight:700;letter-spacing:.06em">GRUPO NAVAL · PLANO DE CARGAS</p>'
        . '<h2 style="margin:0 0 6px;font-size:20px">Plano de cargas de ' . $d . '-' . $m . '-' . $a . '</h2>'
        . '<p style="margin:0 0 14px;font-size:13px;color:#5B6B8C">' . count($saem) . ' rotas · publicado por ' . $h($pub['por_nome'] ?? $pub['por']) . ' em ' . $h(date('d-m-Y H:i', strtotime($pub['em']))) . '</p>'
        . $rect . $nota
        . '<table style="border-collapse:collapse;width:100%"><thead><tr><th ' . $th . '>Rota / vendedor</th><th ' . $th . '>Motorista</th><th ' . $th . '>Viatura</th><th ' . $th . '>Zonas</th>' . $cab . '<th ' . $th . '>Factura</th></tr></thead>'
        . '<tbody>' . $linhas . '<tr><td ' . $td . ' colspan="4"><b>Total</b></td>' . $rod . '<td ' . $td . '></td></tr></tbody></table>'
        . '<p style="margin:16px 0 0;font-size:13px">O Excel segue em anexo, no formato habitual.' . ($ligacao ? ' Também pode consultar o plano no portal: <a href="' . $h($ligacao) . '" style="color:#1E4FB0">abrir o plano de cargas</a>.' : '') . '</p>'
        . '<p style="margin:14px 0 0;font-size:11px;color:#8A97B3">Mensagem automática do Portal RTM · Grupo Naval. Não responda a este email.</p></div>';
}

// ── Casos de uso da página «Plano de cargas» (chamados pela API: nucleo/api/v1/plano.php) ──

/** Quem pode preencher, publicar e gerir as listas (Logística, nível G, e o super administrador). */
function naval_plano_gere(): bool
{
    return naval_pode('plano-cargas', 'G') || naval_perfil() === 'SADM';
}

/** Ligação directa ao plano no portal (PORTAL_URL no .env). */
function naval_plano_ligacao(): string
{
    $u = naval_config('PORTAL_URL');
    return $u !== '' ? rtrim($u, '/') . '/reports/#plano-cargas' : '';
}

function naval_plano_ficheiro_xlsx(string $data, int $versao): string
{
    return naval_plano_pasta() . '/' . $data . '-v' . $versao . '.xlsx';
}

/** Vista do plano para quem o pede: rotas filtradas (V·r); sem rascunho nem envios para quem não gere. */
function naval_plano_vista(?array $p, bool $gere): ?array
{
    if (!$p) return null;
    if (!$gere) {
        if (empty($p['publicado'])) return ['data' => $p['data'], 'estado' => 'rascunho', 'rotas' => [], 'publicado' => null, 'envios' => []];
        $p['rotas'] = $p['publicado']['rotas'];
    }
    $p['rotas'] = naval_filtrar_rotas($p['rotas']);
    if (!empty($p['publicado'])) $p['publicado']['rotas'] = naval_filtrar_rotas($p['publicado']['rotas']);
    if (!$gere) unset($p['envios']);
    return $p;
}

/** Dias com plano (resumo para o histórico). */
function naval_plano_historico(bool $gere): array
{
    $out = [];
    foreach (naval_plano_dias(120) as $p) {
        $pub = $p['publicado'] ?? null;
        if (!$gere && !$pub) continue;
        $rs = naval_filtrar_rotas($pub ? $pub['rotas'] : $p['rotas']);
        $saem = array_filter($rs, function ($r) { return $r['sai']; });
        $carga = 0;
        foreach ($saem as $r) $carga += array_sum($r['quantidades']);
        $env = $p['envios'] ?? [];
        $ult = $env ? end($env) : null;
        $out[] = ['data' => $p['data'], 'estado' => $pub ? ($gere && ($p['rev'] ?? 0) > ($pub['rev'] ?? 0) ? 'alterado' : 'publicado') : 'rascunho',
            'versao' => $pub['versao'] ?? 0, 'rotas' => count($saem), 'carga' => $carga, 'publicado_em' => $pub['em'] ?? null,
            'publicado_por' => $pub['por_nome'] ?? null, 'email' => $gere && $ult ? ($ult['ok'] ? 'enviado' : 'falhou') : null];
    }
    return $out;
}

/** Plano mais recente antes de $data (para «Copiar do dia anterior»). */
function naval_plano_anterior(string $data, bool $gere): ?string
{
    foreach (naval_plano_dias(60) as $x) if ($x['data'] < $data && ($gere || !empty($x['publicado']))) return $x['data'];
    return null;
}

/**
 * Aplica as rotas enviadas pelo portal ao plano do dia (nova revisão). Lança NavalErro 409 se outra
 * pessoa o alterou entretanto (a revisão enviada não é a actual).
 */
function naval_plano_actualizar(string $data, int $rev, array $rotasPedido, array $eu, array $artigos): array
{
    if (!naval_data_valida($data)) throw new NavalErro('Data inválida.');
    $p = naval_plano_ler($data) ?: ['data' => $data, 'rev' => 0, 'criado' => date('Y-m-d H:i:s'), 'criado_por' => $eu['login'], 'rotas' => [], 'publicado' => null, 'envios' => []];
    if ($rev !== (int) $p['rev']) {
        throw new NavalErro('O plano foi alterado por outra pessoa (' . ($p['alterado_por'] ?? '') . ') entretanto. Actualize a página.', 409, ['conflito' => true]);
    }
    $rotas = [];
    $vistas = [];
    foreach ($rotasPedido as $r) {
        $r = naval_plano_rota_limpa((array) $r, $artigos);
        if ($r['rota'] === '' || isset($vistas[$r['rota']])) continue;
        $vistas[$r['rota']] = true;
        $rotas[] = $r;
    }
    $p['rotas'] = $rotas;
    $p['rev'] = (int) $p['rev'] + 1;
    $p['alterado'] = date('Y-m-d H:i:s');
    $p['alterado_por'] = $eu['login'];
    return $p;
}

/**
 * Destinatários escolhidos para um envio: só endereços da lista de destinatários (controlada pela
 * Logística); $para vazio = todos. Lança NavalErro se a escolha ficar vazia.
 */
function naval_plano_destinatarios(array $listas, array $para): array
{
    $todos = $listas['destinatarios'];
    if (!$todos) throw new NavalErro('A lista de destinatários está vazia (Listas → Destinatários).');
    if (!$para) return $todos;
    $quero = array_map('strtolower', array_map('trim', array_map('strval', $para)));
    $dest = array_values(array_filter($todos, function ($d) use ($quero) { return in_array(strtolower($d['email']), $quero, true); }));
    if (!$dest) throw new NavalErro('Escolha pelo menos um destinatário da lista.');
    return $dest;
}

/**
 * Publica o plano: valida, guarda a versão e o Excel e aprende as listas; envia o email só se $enviar
 * (aos destinatários escolhidos em $para, ou a todos). Devolve ['plano', 'listas', 'email' (ou null)].
 */
function naval_plano_publicar(array $p, string $xlsx, string $nota, array $eu, array $listas, bool $enviar = true, array $para = []): array
{
    $rotas = $p['rotas'];
    $erros = naval_plano_erros($rotas, $listas['artigos']);
    if ($enviar && !$listas['destinatarios']) $erros[] = 'A lista de destinatários está vazia (Listas → Destinatários).';
    if ($erros) throw new NavalErro('Corrija antes de publicar:', 400, ['erros' => $erros]);
    if ($enviar) naval_plano_destinatarios($listas, $para);   // escolha válida antes de gravar a versão
    if ($xlsx === '' || substr($xlsx, 0, 2) !== 'PK' || strlen($xlsx) > 3 * 1024 * 1024) throw new NavalErro('O Excel do plano não chegou ao servidor. Tente de novo.');
    $versao = (int) ($p['publicado']['versao'] ?? 0) + 1;
    naval_ficheiro_gravar(naval_plano_ficheiro_xlsx($p['data'], $versao), $xlsx);
    $p['publicado'] = ['versao' => $versao, 'rev' => $p['rev'], 'em' => date('Y-m-d H:i:s'), 'por' => $eu['login'], 'por_nome' => $eu['nome'] ?? $eu['login'],
        'nota' => naval_texto($nota, 300), 'rotas' => $rotas];
    $saem = array_filter($rotas, function ($r) { return $r['sai']; });
    naval_plano_aprender($listas, $saem);
    naval_plano_gravar_listas($listas);
    $envio = $enviar ? naval_plano_enviar($p, $listas, $eu, $para) : null;
    if ($envio) $p['envios'][] = $envio;
    naval_plano_gravar($p);
    naval_registar('plano_publicado', ['data' => $p['data'], 'versao' => $versao, 'rotas' => count($saem),
        'email' => !$envio ? 'não enviado' : ($envio['ok'] ? 'enviado a ' . count($envio['para']) : 'falhou')]);
    return ['plano' => $p, 'listas' => naval_plano_listas(), 'email' => $envio];
}

/** Envia por email a versão publicada, com o Excel em anexo, aos destinatários escolhidos. Devolve o registo do envio. */
function naval_plano_enviar(array $p, array $listas, array $eu, array $para = []): array
{
    $pub = $p['publicado'];
    $dest = naval_plano_destinatarios($listas, $para);
    [$a, $m, $d] = explode('-', $p['data']);
    $assunto = 'Plano de cargas · ' . $d . '-' . $m . '-' . $a . ($pub['versao'] > 1 ? ' · RECTIFICADO (versão ' . $pub['versao'] . ')' : '');
    $xlsx = naval_plano_ficheiro_xlsx($p['data'], (int) $pub['versao']);
    $anexos = is_file($xlsx) ? [['nome' => 'PLANO_DE_CARGAS_' . $d . '.' . $m . '.' . $a . ($pub['versao'] > 1 ? '_v' . $pub['versao'] : '') . '.xlsx',
        'tipo' => NAVAL_TIPO_XLSX, 'conteudo' => (string) file_get_contents($xlsx)]] : [];
    $r = naval_enviar_email($dest, $assunto, naval_plano_email_html($p, $listas['artigos'], naval_plano_ligacao()), $anexos);
    return ['em' => date('Y-m-d H:i:s'), 'por' => $eu['login'], 'versao' => $pub['versao'], 'para' => array_column($dest, 'email'), 'ok' => $r['ok'], 'erro' => $r['erro']];
}

/** Envia (ou reenvia) o email da versão publicada de um dia. Devolve ['plano', 'email']. */
function naval_plano_reenviar(string $data, array $listas, array $eu, array $para = []): array
{
    $p = naval_data_valida($data) ? naval_plano_ler($data) : null;
    if (!$p || empty($p['publicado'])) throw new NavalErro('Este plano ainda não foi publicado.');
    $envio = naval_plano_enviar($p, $listas, $eu, $para);
    $p['envios'][] = $envio;
    naval_plano_gravar($p);
    naval_registar('plano_enviado', ['data' => $data, 'versao' => $p['publicado']['versao'], 'para' => $envio['para'], 'email' => $envio['ok'] ? 'enviado' : 'falhou']);
    return ['plano' => $p, 'email' => $envio];
}

/** Actualiza as listas (só as que vierem no pedido). Devolve as listas gravadas. */
function naval_plano_actualizar_listas(array $n, array $listas): array
{
    $l = $listas;
    foreach (['armazens', 'zonas', 'viaturas', 'transportadoras'] as $k) {
        if (isset($n[$k])) $l[$k] = naval_lista_unica((array) $n[$k]);
    }
    if (isset($n['motoristas'])) {
        $l['motoristas'] = [];
        naval_plano_aprender($l, array_map(function ($m) {
            return ['motorista' => $m['nome'] ?? '', 'telefone' => $m['telefone'] ?? '', 'matricula' => $m['matricula'] ?? '', 'viatura' => $m['viatura'] ?? '', 'transportadora' => $m['transportadora'] ?? ''];
        }, (array) $n['motoristas']));
    }
    if (isset($n['destinatarios'])) {
        $l['destinatarios'] = [];
        foreach ((array) $n['destinatarios'] as $d) {
            $e = trim((string) ($d['email'] ?? ''));
            if ($e === '') continue;
            if (!naval_email_valido($e)) throw new NavalErro('Email inválido: ' . $e);
            foreach ($l['destinatarios'] as $x) if (strcasecmp($x['email'], $e) === 0) continue 2;
            $l['destinatarios'][] = ['nome' => naval_texto($d['nome'] ?? ''), 'email' => $e];
        }
    }
    if (isset($n['artigos'])) {
        $art = [];
        foreach ((array) $n['artigos'] as $a) {
            $nome = naval_texto($a['nome'] ?? '', 40);
            $id = (string) preg_replace('/[^a-z0-9]+/', '', strtolower(naval_chave_texto((string) ($a['id'] ?? $nome))));
            if ($nome === '' || $id === '' || isset($art[$id])) continue;
            $art[$id] = ['id' => $id, 'nome' => $nome];
        }
        if (!$art) throw new NavalErro('Tem de haver pelo menos um artigo.');
        $l['artigos'] = array_values($art);
    }
    naval_plano_gravar_listas($l);
    naval_registar('plano_listas', ['destinatarios' => count($l['destinatarios']), 'motoristas' => count($l['motoristas']), 'zonas' => count($l['zonas'])]);
    return $l;
}
