<?php
/**
 * Plano de cargas diário (antes: Excel «REPORT DRIVER MARKETING TRANSPORTES» enviado por email).
 *
 * Fica em dados-privados/plano-cargas/ (fora da pasta pública e do GitHub):
 *   AAAA-MM-DD.json        plano do dia (rascunho e versão publicada)
 *   AAAA-MM-DD-vN.xlsx     Excel enviado em cada publicação
 *   listas.json            motoristas, zonas, armazéns, viaturas, transportadoras, artigos e destinatários
 *
 * Dados mestre (gestão de frota): «motoristas» (nome, telefone, transportadora, matrícula habitual, validade da carta
 * de condução, activo) e «frota» (matrícula, marca, modelo, transportadora, capacidade em kg, activa). Com as tabelas
 * preenchidas, só se publica um plano com motoristas activos, com a carta válida no dia, e viaturas activas da frota.
 * O documento de cada rota é o número da requisição ao armazém (preenchido sozinho) e a quantidade de cada artigo do
 * plano é o stock da carrinha no corte do dia anterior (contado na conciliação) + o que se vai carregar (requisição).
 */
defined('NAVAL_NUCLEO') || exit;
require_once __DIR__ . '/email.php';
require_once __DIR__ . '/rotas.php';

function naval_plano_pasta(): string
{
    return naval_pasta('plano-cargas');
}

const NAVAL_ARTIGOS_PADRAO = [['id' => 'fraldas', 'nome' => 'Fraldas'], ['id' => 'sabao', 'nome' => 'Sabão'], ['id' => 'sabonete', 'nome' => 'Sabonete']];

/** Listas do plano (motoristas, zonas, viaturas...): aprendidas com os planos publicados no portal, nunca com ficheiros Excel. */
function naval_plano_listas(): array
{
    $l = naval_json_ler(naval_plano_pasta() . '/listas.json');
    if (!$l) {
        $l = ['armazens' => ['HIGITEC'], 'artigos' => NAVAL_ARTIGOS_PADRAO, 'motoristas' => [], 'zonas' => [], 'viaturas' => [], 'transportadoras' => [], 'destinatarios' => []];
    }
    foreach (['armazens', 'motoristas', 'zonas', 'viaturas', 'transportadoras', 'destinatarios'] as $k) if (!isset($l[$k]) || !is_array($l[$k])) $l[$k] = [];
    if (empty($l['artigos'])) $l['artigos'] = NAVAL_ARTIGOS_PADRAO;
    if (!isset($l['capacidades']) || !is_array($l['capacidades'])) $l['capacidades'] = [];
    if (!isset($l['grupos_artigos']) || !is_array($l['grupos_artigos'])) $l['grupos_artigos'] = [];
    foreach ($l['motoristas'] as &$m) $m += ['telefone' => '', 'matricula' => '', 'viatura' => '', 'transportadora' => '', 'carta_validade' => '', 'activo' => true];
    unset($m);
    // frota (viaturas por matrícula): na primeira leitura, a partir das capacidades e das matrículas dos motoristas
    if (!isset($l['frota']) || !is_array($l['frota'])) {
        $f = [];
        foreach ($l['capacidades'] as $mat => $kg) $f[(string) $mat] = ['matricula' => (string) $mat, 'marca' => '', 'modelo' => '', 'transportadora' => '', 'kg' => (float) $kg, 'activa' => true];
        foreach ($l['motoristas'] as $m) {
            $mat = naval_frota_matricula((string) ($m['matricula'] ?? ''));
            if ($mat === '') continue;
            $f[$mat] = ($f[$mat] ?? ['matricula' => $mat, 'marca' => '', 'modelo' => '', 'transportadora' => '', 'kg' => 0.0, 'activa' => true]);
            if ($f[$mat]['modelo'] === '') $f[$mat]['modelo'] = naval_texto($m['viatura'] ?? '', 40);
            if ($f[$mat]['transportadora'] === '') $f[$mat]['transportadora'] = naval_texto($m['transportadora'] ?? '', 60);
        }
        ksort($f);
        $l['frota'] = array_values($f);
    }
    // capacidade (kg) por matrícula: a da frota manda (o limite de peso do plano usa-a)
    foreach ($l['frota'] as $v) if (!empty($v['activa']) && (float) ($v['kg'] ?? 0) > 0) $l['capacidades'][(string) $v['matricula']] = (float) $v['kg'];
    return $l;
}

/** Matrícula normalizada (maiúsculas, separada por hífenes) ou '' se não for válida. Ex.: «ld 23 45 ab» → LD-23-45-AB. */
function naval_frota_matricula(string $m): string
{
    $m = strtoupper(trim((string) preg_replace('/[\s.\/_]+/', '-', trim($m))));
    $m = trim((string) preg_replace('/-+/', '-', (string) preg_replace('/[^A-Z0-9-]/', '', $m)), '-');
    return preg_match('/^[A-Z0-9]{1,4}(-[A-Z0-9]{1,4}){1,4}$/', $m) ? $m : '';
}

/** Problemas de dados mestre de uma rota que sai: motorista activo com a carta válida no dia; viatura activa na frota. */
function naval_frota_erros(array $r, array $listas, string $data): array
{
    $e = [];
    $n = 'Rota ' . $r['rota'];
    if ($listas['motoristas'] && $r['motorista'] !== '') {
        $m = null;
        foreach ($listas['motoristas'] as $x) if (naval_chave_texto((string) $x['nome']) === naval_chave_texto($r['motorista'])) { $m = $x; break; }
        if (!$m) $e[] = $n . ': o motorista ' . $r['motorista'] . ' não está na tabela de motoristas.';
        elseif (empty($m['activo'])) $e[] = $n . ': o motorista ' . $m['nome'] . ' está inactivo.';
        elseif (($m['carta_validade'] ?? '') !== '' && $m['carta_validade'] < $data) $e[] = $n . ': a carta de condução de ' . $m['nome'] . ' caducou em ' . naval_data_pt($m['carta_validade']) . '.';
    }
    if ($listas['frota'] && $r['matricula'] !== '') {
        $v = null;
        foreach ($listas['frota'] as $x) if ($x['matricula'] === naval_frota_matricula($r['matricula'])) { $v = $x; break; }
        if (!$v) $e[] = $n . ': a matrícula ' . $r['matricula'] . ' não está na tabela de viaturas.';
        elseif (empty($v['activa'])) $e[] = $n . ': a viatura ' . $v['matricula'] . ' está inactiva.';
    }
    return $e;
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
    $l['_mestre'] = !empty($l['motoristas']);
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
        // dados mestre: com a tabela de motoristas preenchida, o plano não cria nem altera motoristas
        if ($nome === '' || !empty($l['_mestre'])) continue;
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
        if (!$achou) $l['motoristas'][] = $novo + ['carta_validade' => '', 'activo' => true];
    }
    unset($l['_mestre']);
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
        'armazem' => naval_texto($r['armazem'] ?? '', 120),
        'viagens' => max(0, min(20, (int) ($r['viagens'] ?? 1))),
        'zonas' => $zonas,
        'quantidades' => $q,
        'motorista' => naval_texto($r['motorista'] ?? ''),
        'telefone' => preg_replace('/[^0-9+ ]/', '', naval_texto($r['telefone'] ?? '', 30)),
        'matricula' => naval_frota_matricula((string) ($r['matricula'] ?? '')) ?: strtoupper(naval_texto($r['matricula'] ?? '', 20)),
        'viatura' => naval_texto($r['viatura'] ?? '', 40),
        'transportadora' => naval_texto($r['transportadora'] ?? '', 60),
        'factura' => naval_texto($r['factura'] ?? '', 120),
        'obs' => naval_texto($r['obs'] ?? '', 300),
    ];
}

/** Problemas que impedem a publicação (só as rotas que saem). */
function naval_plano_erros(array $rotas, array $artigos, ?array $listas = null, string $data = ''): array
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
        if ($listas !== null) $e = array_merge($e, naval_frota_erros($r, $listas, $data !== '' ? $data : date('Y-m-d')));
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

/**
 * Registo histórico de cargas → registo do portal (uma vez por conteúdo, idempotente). Os dias do registo histórico
 * (reports/data/cargas.json, anterior ao plano no portal) que ainda não têm plano no portal entram como planos
 * publicados marcados «importado» (proveniência registada: ISO 8000), e as listas aprendem os motoristas, viaturas,
 * transportadoras e zonas. Daí em diante o portal só lê o seu próprio registo: o pré-preenchimento de um dia novo
 * usa o último registo de cada rota. Nunca altera um dia que já tenha plano. Devolve o número de dias importados.
 */
function naval_plano_importar_historico(?string $ficheiro = null): int
{
    $ficheiro = $ficheiro ?? naval_dados_reports() . '/cargas.json';
    if (!is_file($ficheiro)) return 0;
    $marca = naval_plano_pasta() . '/historico-importado.json';
    $hash = (string) hash_file('sha256', $ficheiro);
    if ((naval_json_ler($marca)['sha256'] ?? '') === $hash) return 0;
    return naval_exclusivo('plano-historico', function () use ($ficheiro, $marca, $hash) {
        $listas = naval_plano_listas();
        $porDia = [];
        foreach (naval_json_ler($ficheiro) as $c) {
            $d = (string) ($c['data'] ?? '');
            $rt = trim((string) ($c['rota'] ?? ''));
            if (!naval_data_valida($d) || $rt === '') continue;
            $q = [];
            foreach ($listas['artigos'] as $a) $q[$a['id']] = (float) ($c['carga_' . $a['id']] ?? 0);
            $porDia[$d][$rt] = naval_plano_rota_limpa([
                'rota' => $rt, 'vendedor' => (string) ($c['promotor'] ?? ''), 'sai' => true, 'armazem' => $listas['armazens'][0] ?? '', 'viagens' => 1,
                'zonas' => array_map('trim', explode(',', (string) ($c['zonas'] ?? ''))), 'quantidades' => $q,
                'motorista' => (string) ($c['motorista'] ?? ''), 'matricula' => (string) ($c['matricula'] ?? ''),
                'viatura' => (string) ($c['viatura'] ?? ''), 'transportadora' => (string) ($c['transportadora'] ?? ''),
            ], $listas['artigos']);
        }
        ksort($porDia);
        $n = 0;
        foreach ($porDia as $d => $rotas) {
            if (naval_plano_ler($d)) continue;   // nunca por cima de um dia que já tem plano no portal
            $rotas = array_values($rotas);
            naval_plano_gravar(['data' => $d, 'rev' => 1, 'criado' => date('Y-m-d H:i:s'), 'criado_por' => 'historico', 'rotas' => $rotas, 'envios' => [],
                'importado' => true, 'publicado' => ['versao' => 0, 'rev' => 1, 'em' => $d . ' 00:00:00', 'por' => 'historico',
                'por_nome' => 'Registo histórico de cargas', 'nota' => 'Importado do registo histórico de cargas', 'rotas' => $rotas, 'importado' => true]]);
            naval_plano_aprender($listas, $rotas);
            $n++;
        }
        if ($n) naval_plano_gravar_listas($listas);
        naval_json_gravar($marca, ['sha256' => $hash, 'em' => date('Y-m-d H:i:s'), 'dias' => $n]);
        if ($n) naval_registar('plano_historico_importado', ['dias' => $n]);
        return $n;
    });
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
        . '<table style="border-collapse:collapse;width:100%"><thead><tr><th ' . $th . '>Rota / vendedor</th><th ' . $th . '>Motorista</th><th ' . $th . '>Viatura</th><th ' . $th . '>Zonas</th>' . $cab . '<th ' . $th . '>Requisição</th></tr></thead>'
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

/**
 * Pré-preenchimento automático: para cada rota, o último registo oficial antes de $data em que a rota saiu.
 * Fonte: só as versões publicadas do plano no portal (nunca ficheiros Excel). O telefone, a matrícula, a viatura e a
 * transportadora vêm da ficha do motorista (listas), mais actual do que o registo antigo.
 * Devolve {rota: {..campos.., origem: AAAA-MM-DD}}. O número de factura e as observações nunca se copiam.
 */
function naval_plano_sugestoes(string $data, array $listas): array
{
    $out = [];
    foreach (naval_plano_dias(120) as $p) {
        if (($p['data'] ?? '') >= $data || empty($p['publicado']['rotas'])) continue;
        foreach ($p['publicado']['rotas'] as $r) {
            $rt = (string) ($r['rota'] ?? '');
            if ($rt === '' || isset($out[$rt]) || empty($r['sai'])) continue;
            $out[$rt] = [
                'armazem' => $r['armazem'] ?? '', 'viagens' => (int) ($r['viagens'] ?? 1), 'zonas' => array_values((array) ($r['zonas'] ?? [])),
                'quantidades' => (array) ($r['quantidades'] ?? []), 'motorista' => $r['motorista'] ?? '', 'telefone' => $r['telefone'] ?? '',
                'matricula' => $r['matricula'] ?? '', 'viatura' => $r['viatura'] ?? '', 'transportadora' => $r['transportadora'] ?? '',
                'origem' => $p['data'],
            ];
        }
    }
    foreach ($out as &$s) {
        foreach ($listas['motoristas'] as $m) {
            if (naval_chave_texto((string) ($m['nome'] ?? '')) !== naval_chave_texto((string) $s['motorista'])) continue;
            $s['motorista'] = $m['nome'];
            foreach (['telefone', 'matricula', 'viatura', 'transportadora'] as $k) if (!empty($m[$k])) $s[$k] = $m[$k];
            break;
        }
        if ($s['armazem'] === '' && !empty($listas['armazens'])) $s['armazem'] = $listas['armazens'][0];
    }
    unset($s);
    return naval_filtrar_rotas(array_map(function ($rt, $s) { return ['rota' => (string) $rt] + $s; }, array_keys($out), $out));
}

/**
 * Quantidades das sugestões pela previsão (nucleo/servicos/previsao.php): média ponderada das últimas cargas da rota ×
 * tendência das vendas da rota. As quantidades da última carga ficam em «quantidades_ultima» e a explicação em «previsao».
 * Sem histórico suficiente, a sugestão fica como estava (cópia da última carga). PREVISAO_PLANO=0 no .env desliga.
 */
function naval_plano_com_previsao(string $data, array $sugestoes): array
{
    if (!$sugestoes || naval_config('PREVISAO_PLANO', '1') === '0') return $sugestoes;
    naval_servico('previsao');
    $tend = [];
    $ult = min(date('Y-m-d', strtotime($data . ' 12:00') - 86400), date('Y-m-d', time() - 86400));
    try {
        $ag = naval_analise_agregados(date('Y-m-d', strtotime($ult . ' 12:00') - 55 * 86400), $ult);
        foreach (naval_analise_rotas($ag) as $r) $tend[$r] = naval_previsao_tendencia(naval_analise_serie($ag, $r), $ult);
    } catch (Throwable $e) {
        error_log('[plano previsão] ' . $e->getMessage());   // sem vendas: só a média das cargas
    }
    $prev = naval_previsao_cargas(naval_plano_dias(120), $data, $tend);
    foreach ($sugestoes as &$s) {
        $p = $prev[$s['rota']] ?? null;
        if (!$p) continue;
        $s['quantidades_ultima'] = $s['quantidades'];
        $s['quantidades'] = $p['quantidades'] + array_map(function () { return 0; }, (array) $s['quantidades']);
        $s['previsao'] = ['cargas' => $p['cargas'], 'tendencia' => $p['tendencia']];
    }
    unset($s);
    return $sugestoes;
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
    $erros = naval_plano_erros($rotas, $listas['artigos'], $listas, (string) $p['data']);
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
        // tabela de motoristas (dados mestre): nome único, telefone, transportadora, matrícula habitual, validade da carta
        $mot = [];
        foreach ((array) $n['motoristas'] as $m) {
            $nome = naval_texto($m['nome'] ?? '');
            if ($nome === '') continue;
            $k = naval_chave_texto($nome);
            if (isset($mot[$k])) throw new NavalErro('O motorista ' . $nome . ' está repetido.');
            $val = trim((string) ($m['carta_validade'] ?? ''));
            if ($val !== '' && !naval_data_valida($val)) throw new NavalErro('Validade da carta inválida para ' . $nome . '.');
            $mat = trim((string) ($m['matricula'] ?? ''));
            if ($mat !== '' && naval_frota_matricula($mat) === '') throw new NavalErro('Matrícula inválida para ' . $nome . ': ' . $mat . '.');
            $mot[$k] = ['nome' => $nome, 'telefone' => preg_replace('/[^0-9+ ]/', '', naval_texto($m['telefone'] ?? '', 30)),
                'matricula' => $mat === '' ? '' : naval_frota_matricula($mat), 'viatura' => naval_texto($m['viatura'] ?? '', 40),
                'transportadora' => naval_texto($m['transportadora'] ?? '', 60), 'carta_validade' => $val, 'activo' => !array_key_exists('activo', (array) $m) || !empty($m['activo'])];
        }
        $l['motoristas'] = array_values($mot);
    }
    if (isset($n['frota'])) {
        // tabela de viaturas (dados mestre): matrícula única, marca, modelo, transportadora e capacidade (kg)
        $f = [];
        foreach ((array) $n['frota'] as $v) {
            $bruta = trim((string) ($v['matricula'] ?? ''));
            if ($bruta === '') continue;
            $mat = naval_frota_matricula($bruta);
            if ($mat === '') throw new NavalErro('Matrícula inválida: ' . $bruta . '.');
            if (isset($f[$mat])) throw new NavalErro('A matrícula ' . $mat . ' está repetida.');
            $kg = (float) str_replace(',', '.', (string) ($v['kg'] ?? 0));
            if ($kg < 0 || $kg > 40000) throw new NavalErro('Capacidade inválida para a viatura ' . $mat . '.');
            $f[$mat] = ['matricula' => $mat, 'marca' => naval_texto($v['marca'] ?? '', 40), 'modelo' => naval_texto($v['modelo'] ?? '', 40),
                'transportadora' => naval_texto($v['transportadora'] ?? '', 60), 'kg' => round($kg, 1), 'activa' => !array_key_exists('activa', (array) $v) || !empty($v['activa'])];
        }
        ksort($f);
        $l['frota'] = array_values($f);
        $l['capacidades'] = [];
        foreach ($f as $mat => $v) if ($v['kg'] > 0) $l['capacidades'][$mat] = $v['kg'];
    }
    if (isset($n['grupos_artigos'])) {
        // correspondência dos artigos do Pinguim BO (código) com os artigos do plano (Fraldas, Sabão, …)
        $ids = array_column($l['artigos'], 'id');
        $g = [];
        foreach ((array) $n['grupos_artigos'] as $cod => $id) {
            $cod = naval_texto((string) $cod, 30);
            if ($cod === '' || $id === '' || $id === null) continue;
            if (!in_array((string) $id, $ids, true)) throw new NavalErro('Artigo do plano desconhecido: ' . $id . '.');
            $g[$cod] = (string) $id;
        }
        ksort($g);
        $l['grupos_artigos'] = $g;
    }
    if (isset($n['destinatarios'])) {
        $l['destinatarios'] = [];
        foreach ((array) $n['destinatarios'] as $d) {
            $e = trim((string) ($d['email'] ?? ''));
            if ($e === '') continue;
            if (!naval_email_valido($e)) throw new NavalErro('Correio electrónico inválido: ' . $e);
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
            // peso de uma unidade de carga do artigo (kg por caixa/fardo), para o limite de peso da viatura
            $peso = round(max(0.0, min(5000.0, (float) str_replace(',', '.', (string) ($a['peso'] ?? 0)))), 3);
            $art[$id] = ['id' => $id, 'nome' => $nome] + ($peso > 0 ? ['peso' => $peso] : []);
        }
        if (!$art) throw new NavalErro('Tem de haver pelo menos um artigo.');
        $l['artigos'] = array_values($art);
    }
    if (isset($n['capacidades']) && !isset($n['frota'])) {
        // carga máxima (kg) de cada viatura, pela matrícula (compatibilidade: a tabela de viaturas substitui esta lista)
        $l['capacidades'] = [];
        foreach ((array) $n['capacidades'] as $mat => $kg) {
            $mat = strtoupper(naval_texto($mat, 20));
            $kg = (float) str_replace(',', '.', (string) $kg);
            if ($mat === '' || $kg <= 0) continue;
            if ($kg > 40000) throw new NavalErro('Capacidade inválida para a viatura ' . $mat . '.');
            $l['capacidades'][$mat] = round($kg, 1);
        }
        ksort($l['capacidades']);
    }
    naval_plano_gravar_listas($l);
    naval_registar('plano_listas', ['destinatarios' => count($l['destinatarios']), 'motoristas' => count($l['motoristas']), 'zonas' => count($l['zonas']),
        'viaturas' => count($l['frota'] ?? [])]);
    return $l;
}

// ── Carga de cada rota: stock da carrinha no corte do dia anterior + requisição ─────────────────────────────────────

/** Último dia de trabalho antes de $data (o dia em que se contou a carrinha e se carregou para $data). */
function naval_plano_dia_anterior(string $data): string
{
    naval_servico('calendario');
    $t = strtotime($data . ' 12:00');
    for ($i = 1; $i <= 7; $i++) {
        $d = date('Y-m-d', $t - $i * 86400);
        if (!naval_cal_folga($d)) return $d;
    }
    return date('Y-m-d', $t - 86400);
}

/** Artigo do plano (id) de um artigo do Pinguim BO: pela correspondência gravada ou pelo nome (ex.: «FRALDAS …» → fraldas). */
function naval_plano_grupo_artigo(string $codigo, string $nome, array $listas): string
{
    if (isset($listas['grupos_artigos'][$codigo])) return (string) $listas['grupos_artigos'][$codigo];
    $chave = ' ' . naval_chave_texto($nome) . ' ';
    foreach ($listas['artigos'] as $a) {
        $raiz = substr(naval_chave_texto((string) $a['nome']), 0, 5);
        if (strlen($raiz) >= 4 && preg_match('/ ' . preg_quote($raiz, '/') . '/', $chave)) return (string) $a['id'];
    }
    return '';
}

/**
 * Por rota, para o plano de $data: as requisições (emitidas ou carregadas, do dia anterior de trabalho até $data) e, por
 * artigo do plano, o que já está na carrinha (contado na conciliação do dia anterior, no corte; sem contagem, o esperado
 * — «estimado»), o que se vai carregar (requisitado; depois de carregada, o entregue) e o total à saída.
 * {rota: {requisicoes: [{numero, estado}], grupos: {id: {na, carregar, total}}, stock: contado|estimado|sem_dados, sem_grupo: [{codigo, artigo}]}}
 */
function naval_plano_carga(string $data, array $listas, ?array $rotas = null): array
{
    naval_servico('carrinha');
    naval_servico('requisicoes');
    $ant = naval_plano_dia_anterior($data);
    $out = [];
    $vazio = function () use ($listas) {
        $g = [];
        foreach ($listas['artigos'] as $a) $g[(string) $a['id']] = ['na' => 0.0, 'carregar' => 0.0, 'total' => 0.0];
        return ['requisicoes' => [], 'rascunhos' => [], 'armazens' => [], 'grupos' => $g, 'stock' => 'sem_dados', 'sem_grupo' => []];
    };
    $junta = function (string $rota, string $codigo, string $nome, string $campo, float $q) use (&$out, $listas, $vazio) {
        if (!isset($out[$rota])) $out[$rota] = $vazio();
        $g = naval_plano_grupo_artigo($codigo, $nome, $listas);
        if ($g === '' || !isset($out[$rota]['grupos'][$g])) {
            if ($q > 0) $out[$rota]['sem_grupo'][$codigo] = ['codigo' => $codigo, 'artigo' => $nome];
            return;
        }
        $out[$rota]['grupos'][$g][$campo] = round($out[$rota]['grupos'][$g][$campo] + $q, 3);
    };
    // requisições ao armazém
    $catArm = null;
    foreach (array_unique([substr($ant, 0, 4), substr($data, 0, 4)]) as $ano) {
        foreach ((array) glob(naval_req_pasta($ano) . '/RQ-*.json') as $f) {
            $r = naval_json_ler((string) $f);
            if (!$r || $r['data'] < $ant || $r['data'] > $data) continue;
            $rota = (string) $r['rota'];
            if ($rotas !== null && !in_array($rota, $rotas, true)) continue;
            if ($r['estado'] === 'rascunho') {   // ainda por emitir: o plano avisa, mas não carrega
                if (!isset($out[$rota])) $out[$rota] = $vazio();
                $out[$rota]['rascunhos'][] = (string) $r['numero'];
                continue;
            }
            if (!in_array($r['estado'], ['emitida', 'carregada'], true)) continue;
            if (!isset($out[$rota])) $out[$rota] = $vazio();
            $out[$rota]['requisicoes'][] = ['numero' => $r['numero'], 'estado' => $r['estado']];
            foreach ($r['linhas'] as $l) {
                $q = (float) ($r['estado'] === 'carregada' ? ($l['entregue'] ?? $l['quantidade']) : $l['quantidade']);
                $junta($rota, (string) $l['codigo'], (string) ($l['artigo'] ?? ''), 'carregar', $q);
                // armazém de carga: o gravado na emissão; nas requisições antigas, o do catálogo do Pinguim BO
                $arm = (string) ($l['armazem'] ?? '');
                if ($arm === '') {
                    if ($catArm === null) {
                        try {
                            $catArm = naval_req_armazem_artigos();
                        } catch (Throwable $e) {
                            $catArm = [];
                        }
                    }
                    $arm = (string) ($catArm[(string) $l['codigo']] ?? '');
                }
                if ($arm !== '' && $q > 0 && !in_array($arm, $out[$rota]['armazens'], true)) $out[$rota]['armazens'][] = $arm;
            }
        }
    }
    // stock da carrinha no corte do dia anterior (conciliação: contado; sem contagem, o esperado)
    try {
        $v = naval_carr_vista($ant, $rotas, false);
        foreach ($v['rotas'] as $c) {
            $rota = (string) $c['rota'];
            if (!isset($out[$rota])) $out[$rota] = $vazio();
            $contada = $c['resumo']['estado'] !== 'por_contar';
            $out[$rota]['stock'] = $contada ? 'contado' : 'estimado';
            foreach ($c['linhas'] as $l) {
                $q = $contada && $l['contado'] !== null ? (float) $l['contado'] : (float) $l['esperado'];
                $junta($rota, (string) $l['codigo'], (string) $l['artigo'], 'na', max(0.0, $q));
            }
        }
    } catch (Throwable $e) {
        error_log('[plano carga] ' . $e->getMessage());   // sem Pinguim BO: só a requisição (stock «sem_dados»)
    }
    foreach ($out as &$x) {
        foreach ($x['grupos'] as &$g) $g['total'] = round($g['na'] + $g['carregar'], 3);
        unset($g);
        usort($x['requisicoes'], function ($a, $b) { return strcmp($a['numero'], $b['numero']); });
        sort($x['rascunhos']);
        sort($x['armazens']);
        $x['sem_grupo'] = array_values($x['sem_grupo']);
    }
    unset($x);
    ksort($out);
    return ['dia_anterior' => $ant, 'rotas' => $out];
}

/**
 * Documento de carga = requisição: em cada rota com requisição emitida (ou carregada) para o dia do plano, o servidor
 * fixa o número da requisição, o armazém de carga (o dos artigos da requisição) e as quantidades de cada artigo do plano
 * = existências na carrinha (contadas no corte do dia anterior; sem contagem, as esperadas) + requisitado = total à
 * saída. O que vier do ecrã para estes campos é ignorado. Sem ligação ao Pinguim BO (existências desconhecidas), fica o
 * valor do ecrã, mas nunca abaixo do requisitado. $carga: naval_plano_carga já lida (os testes passam-na).
 */
function naval_plano_com_requisicoes(array $p, array $listas, ?array $carga = null): array
{
    naval_servico('requisicoes');
    $ant = naval_plano_dia_anterior((string) $p['data']);
    $req = [];
    $grupo = [];
    foreach (array_unique([substr($ant, 0, 4), substr((string) $p['data'], 0, 4)]) as $ano) {
        foreach ((array) glob(naval_req_pasta($ano) . '/RQ-*.json') as $f) {
            $r = naval_json_ler((string) $f);
            if (!$r || !in_array($r['estado'], ['emitida', 'carregada'], true) || $r['data'] < $ant || $r['data'] > $p['data']) continue;
            $rt = (string) $r['rota'];
            $req[$rt] = $req[$rt] ?? ['num' => [], 'q' => [], 'arm' => []];
            $req[$rt]['num'][] = (string) $r['numero'];
            foreach ((array) $r['linhas'] as $l) {
                $q = (float) ($r['estado'] === 'carregada' ? ($l['entregue'] ?? $l['quantidade']) : $l['quantidade']);
                $c = (string) $l['codigo'];
                $g = $grupo[$c] = $grupo[$c] ?? naval_plano_grupo_artigo($c, (string) ($l['artigo'] ?? ''), $listas);
                if ($g !== '') $req[$rt]['q'][$g] = ($req[$rt]['q'][$g] ?? 0) + $q;
                if (($l['armazem'] ?? '') !== '' && $q > 0) $req[$rt]['arm'][(string) $l['armazem']] = true;
            }
        }
    }
    if ($req && $carga === null) {
        try {
            $carga = naval_plano_carga((string) $p['data'], $listas, array_map('strval', array_keys($req)));
        } catch (Throwable $e) {
            error_log('[plano requisições] ' . $e->getMessage());
            $carga = ['rotas' => []];
        }
    }
    foreach ($p['rotas'] as &$r) {
        $x = $req[$r['rota']] ?? null;
        if (!$x) continue;
        sort($x['num']);
        $r['factura'] = implode(', ', $x['num']);
        $cg = $carga['rotas'][$r['rota']] ?? null;
        $conhecido = $cg && ($cg['stock'] ?? 'sem_dados') !== 'sem_dados';
        foreach ($listas['artigos'] as $a) {
            $id = (string) $a['id'];
            $pedido = (float) ($x['q'][$id] ?? 0);
            $r['quantidades'][$id] = $conhecido
                ? (int) round($pedido + (float) ($cg['grupos'][$id]['na'] ?? 0))
                : (int) max(round($pedido), (int) ($r['quantidades'][$id] ?? 0));
        }
        $r['carga_total'] = true;   // as quantidades já levam as existências: o limite de peso não as soma outra vez
        if ($x['arm']) {
            $arm = array_keys($x['arm']);
            sort($arm);
            $r['armazem'] = implode(' + ', $arm);
        }
    }
    unset($r);
    return $p;
}
