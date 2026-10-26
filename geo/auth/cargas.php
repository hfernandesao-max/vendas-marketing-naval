<?php
/**
 * Plano de cargas diário (antes: Excel «REPORT DRIVER MARKETING TRANSPORTES» enviado por email).
 *
 * Fica em dados-privados/plano-cargas/ (fora da pasta pública e do GitHub):
 *   AAAA-MM-DD.json        plano do dia (rascunho e versão publicada)
 *   AAAA-MM-DD-vN.xlsx     Excel enviado em cada publicação
 *   listas.json            motoristas, zonas, armazéns, viaturas, transportadoras, artigos e destinatários
 */
require_once __DIR__ . '/acessos.php';

function naval_plano_pasta(): string
{
    $d = naval_pasta_privada() . '/plano-cargas';
    if (!is_dir($d)) @mkdir($d, 0770, true);
    return $d;
}

function naval_data_valida($d): bool
{
    return is_string($d) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
}

/** Texto para comparar nomes: maiúsculas, sem acentos, só letras e números. */
function naval_chave_texto(string $s): string
{
    $s = strtr($s, ['á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ú' => 'u', 'ç' => 'c',
        'Á' => 'A', 'À' => 'A', 'Â' => 'A', 'Ã' => 'A', 'É' => 'E', 'Ê' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ú' => 'U', 'Ç' => 'C']);
    return trim(preg_replace('/[^A-Z0-9]+/', ' ', strtoupper($s)));
}

function naval_texto($v, int $max = 80): string
{
    $s = trim(preg_replace('/\s+/u', ' ', (string) $v));
    return preg_replace('/^(.{0,' . $max . '}).*$/us', '$1', $s);
}

const NAVAL_ARTIGOS_PADRAO = [['id' => 'fraldas', 'nome' => 'Fraldas'], ['id' => 'sabao', 'nome' => 'Sabão'], ['id' => 'sabonete', 'nome' => 'Sabonete']];

/** Listas do plano. Na primeira utilização, preenchidas a partir do histórico do plano de cargas (cargas.json). */
function naval_plano_listas(): array
{
    $f = naval_plano_pasta() . '/listas.json';
    $l = is_file($f) ? json_decode((string) file_get_contents($f), true) : null;
    if (!is_array($l)) {
        $l = ['armazens' => ['HIGITEC'], 'artigos' => NAVAL_ARTIGOS_PADRAO, 'motoristas' => [], 'zonas' => [], 'viaturas' => [], 'transportadoras' => [], 'destinatarios' => []];
        $hist = json_decode((string) @file_get_contents(dirname(__DIR__, 2) . '/reports/data/cargas.json'), true) ?: [];
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
    file_put_contents(naval_plano_pasta() . '/listas.json', json_encode($l, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
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
    $f = naval_plano_ficheiro($data);
    if (!is_file($f)) return null;
    $p = json_decode((string) file_get_contents($f), true);
    return is_array($p) ? $p : null;
}

function naval_plano_gravar(array $p): void
{
    file_put_contents(naval_plano_ficheiro($p['data']), json_encode($p, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
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
