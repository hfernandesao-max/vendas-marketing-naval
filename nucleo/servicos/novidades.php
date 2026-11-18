<?php
/**
 * Resumo das actualizações do portal para o super administrador (janela na primeira entrada depois de cada
 * actualização). A origem é o registo de alterações do portal (CHANGELOG.md, na raiz; o Apache não o serve).
 *
 * Funciona com qualquer forma de actualização (manual ou automática): o servidor guarda as secções do registo
 * que já conhecia (pasta privada novidades/conhecidas.json); secções novas no CHANGELOG.md formam uma
 * «actualização» (novidades/actualizacoes.json), com a versão instalada pelo actualizador quando existe. Na primeira
 * vez, a base é o registo de alterações da versão do servidor juntada ao GitHub (nucleo/recursos/novidades-base.json).
 * Cada super administrador vê as actualizações que ainda não fechou (novidades/vistas.json).
 */
defined('NAVAL_NUCLEO') || exit;

const NAVAL_NOV_MAX = 10;   // actualizações guardadas e mostradas

function naval_nov_ficheiro_changelog(): string
{
    return naval_raiz() . '/CHANGELOG.md';
}

/**
 * Secções do registo de alterações: [{id, data, titulo, itens}]. «## <data>» abre um dia, «### <título>» uma
 * secção, «- » um ponto (as linhas seguintes com espaços continuam o mesmo ponto). O id depende só do dia e do
 * título: corrigir o texto de uma secção já publicada não a volta a mostrar como nova.
 */
function naval_nov_seccoes(string $md): array
{
    $out = [];
    $data = '';
    /** @var array<string, mixed>|null $actual */
    $actual = null;
    /** @var array{0: string, 1: int, 2: string}|null $ultimo */
    $ultimo = null;   // ponto do aviso em curso: [resumo|passos, posição, língua]
    $ids = [];
    foreach (preg_split('/\r\n|\n|\r/', $md) ?: [] as $l) {
        if (preg_match('/^##\s+(.+?)\s*$/', $l, $m) && strpos($l, '###') !== 0) {
            naval_nov_fechar($actual, $out);
            $data = $m[1];
            continue;
        }
        if (preg_match('/^###\s+(.+?)\s*$/', $l, $m)) {
            naval_nov_fechar($actual, $out);
            if ($data === '') continue;
            $id = substr(hash('sha256', $data . '|' . $m[1]), 0, 16);
            for ($n = 2; isset($ids[$id]); $n++) $id = substr(hash('sha256', $data . '|' . $m[1] . '|' . $n), 0, 16);
            $ids[$id] = true;
            $actual = ['id' => $id, 'data' => $data, 'titulo' => $m[1], 'itens' => [], 'paginas' => [], 'tipo' => ''];
            $ultimo = null;
            continue;
        }
        if ($actual === null) continue;
        // aviso aos utilizadores (linhas «> »): «> - » o que muda, «> 1. » como proceder, «> [en] » / «> [ti] » a
        // tradução do ponto anterior; as outras linhas «> » continuam o ponto anterior, «>» sozinho fecha-o
        if (preg_match('/^>\s?(.*)$/', $l, $m)) {
            $x = trim($m[1]);
            if (!isset($actual['aviso'])) $actual['aviso'] = ['titulo' => ['pt' => $actual['titulo']], 'resumo' => [], 'passos' => []];
            if ($x === '') {
                $ultimo = null;
            } elseif (preg_match('/^\[(en|ti)\]\s*(.*)$/', $x, $t) && $ultimo) {
                $actual['aviso'][$ultimo[0]][$ultimo[1]][$t[1]] = $t[2];
                $ultimo[2] = $t[1];
            } elseif (preg_match('/^titulo(?:\s+\[(en|ti)\])?:\s*(.+)$/', $x, $t)) {
                $actual['aviso']['titulo'][$t[1] !== '' ? $t[1] : 'pt'] = $t[2];
                $ultimo = null;
            } elseif (preg_match('/^(?:[-*]|(\d+)\.)\s+(.*)$/', $x, $t)) {
                $k = $t[1] !== '' ? 'passos' : 'resumo';
                $actual['aviso'][$k][] = ['pt' => $t[2]];
                $ultimo = [$k, count($actual['aviso'][$k]) - 1, 'pt'];
            } elseif ($ultimo) {
                $actual['aviso'][$ultimo[0]][$ultimo[1]][$ultimo[2]] .= ' ' . $x;
            }
            continue;
        }
        // público do aviso: <!-- publico: todos --> (por omissão, quem vê alguma das páginas da secção)
        if (preg_match('/^<!--\s*publico:\s*todos\s*-->$/', trim($l))) {
            $actual['publico'] = 'todos';
            continue;
        }
        // páginas a que a secção diz respeito (selo «Novo» no menu durante 24 h): <!-- paginas: pagamentos, vendas -->
        if (preg_match('/^<!--\s*paginas:\s*(.+?)\s*-->$/', trim($l), $m)) {
            $actual['paginas'] = array_values(array_filter(array_map('trim', explode(',', $m[1]))));
            continue;
        }
        // classificação da alteração (nucleo/servicos/versoes.php): <!-- tipo: funcionalidade -->
        if (preg_match('/^<!--\s*tipo:\s*([a-z]+)\s*-->$/', trim($l), $m)) {
            $actual['tipo'] = $m[1];
            continue;
        }
        if (preg_match('/^[-*]\s+(.*)$/', $l, $m)) {
            $actual['itens'][] = trim($m[1]);
        } elseif (preg_match('/^\s+(\S.*)$/', $l, $m) && $actual['itens']) {
            $actual['itens'][count($actual['itens']) - 1] .= ' ' . trim($m[1]);
        }
    }
    naval_nov_fechar($actual, $out);
    return $out;
}

/**
 * Fecha a secção em curso (só fica se tiver pontos).
 *
 * @param-out null $actual
 */
function naval_nov_fechar(?array &$actual, array &$out): void
{
    if ($actual !== null && $actual['itens']) $out[] = $actual;
    $actual = null;
}

/** Identificadores das secções que o servidor já conhecia (na primeira vez, os da versão de base). */
function naval_nov_conhecidas(): array
{
    $f = naval_pasta('novidades') . '/conhecidas.json';
    if (is_file($f)) return naval_json_ler($f);
    return naval_json_ler(naval_raiz() . '/nucleo/recursos/novidades-base.json');
}

/**
 * Regista como actualização as secções novas do registo de alterações (uma vez por actualização instalada).
 * Devolve as actualizações guardadas (a mais recente primeiro).
 */
function naval_nov_detectar(): array
{
    return naval_exclusivo('novidades', function () {
        $dir = naval_pasta('novidades');
        $lista = naval_json_ler($dir . '/actualizacoes.json');
        $md = @file_get_contents(naval_nov_ficheiro_changelog());
        if (!is_string($md) || $md === '') return $lista;
        $seccoes = naval_nov_seccoes($md);
        $conhecidas = array_flip(naval_nov_conhecidas());
        $novas = array_values(array_filter($seccoes, function ($s) use ($conhecidas) { return !isset($conhecidas[$s['id']]); }));
        if (!$novas) {
            // marca a leitura (naval_nov_recentes só volta a ler quando o registo mudar)
            if (is_file($dir . '/actualizacoes.json')) @touch($dir . '/actualizacoes.json');
            else naval_json_gravar($dir . '/actualizacoes.json', $lista);
            return $lista;
        }
        $estado = naval_json_ler(naval_pasta('actualizacoes') . '/estado.json');
        array_unshift($lista, [
            'id' => date('YmdHis') . '-' . substr(hash('sha256', (string) json_encode(array_column($novas, 'id'))), 0, 8),
            'detectada_em' => date('Y-m-d H:i:s'),
            'versao' => (string) ($estado['versao'] ?? ''),
            'seccoes' => $novas,
        ]);
        $lista = array_slice($lista, 0, NAVAL_NOV_MAX);
        naval_json_gravar($dir . '/actualizacoes.json', $lista);
        naval_json_gravar($dir . '/conhecidas.json', array_values(array_unique(array_merge(array_keys($conhecidas), array_column($seccoes, 'id')))));
        naval_registar('novidades_detectadas', ['seccoes' => count($novas), 'versao' => (string) ($estado['versao'] ?? '')], 'portal');
        return $lista;
    });
}

/**
 * Novidades das últimas $horas por página, para o selo «Novo» (a mais recente de cada página): [{pagina, titulo,
 * desde, desde_ts}]. A hora é a da instalação da actualização no servidor (detectada_em). $lista: para os testes.
 */
function naval_nov_recentes(int $agora, int $horas = 24, ?array $lista = null): array
{
    if ($lista === null) {
        // rápido (corre em cada sessão): só volta a ler o registo de alterações quando mudou depois da última leitura
        $f = naval_pasta('novidades') . '/actualizacoes.json';
        $md = @filemtime(naval_nov_ficheiro_changelog()) ?: 0;
        $lista = is_file($f) && @filemtime($f) >= $md ? naval_json_ler($f) : naval_nov_detectar();
    }
    $out = [];
    foreach ($lista as $a) {
        $ts = strtotime((string) ($a['detectada_em'] ?? ''));
        if (!$ts || $ts > $agora || $agora - $ts >= $horas * 3600) continue;
        foreach ((array) ($a['seccoes'] ?? []) as $s) {
            foreach ((array) ($s['paginas'] ?? []) as $p) {
                if (!isset($out[$p]) || $out[$p]['desde_ts'] < $ts) $out[$p] = ['pagina' => (string) $p, 'titulo' => (string) $s['titulo'], 'desde' => date('Y-m-d H:i', $ts), 'desde_ts' => $ts];
            }
        }
    }
    return array_values($out);
}

/** Actualizações que esta conta ainda não fechou. */
function naval_nov_pendentes(string $login): array
{
    $lista = naval_nov_detectar();
    $vistas = naval_json_ler(naval_pasta('novidades') . '/vistas.json');
    $minhas = array_flip((array) ($vistas[$login] ?? []));
    return array_values(array_filter($lista, function ($a) use ($minhas) { return !isset($minhas[$a['id']]); }));
}

/** Marca actualizações como vistas por esta conta (só identificadores existentes). */
function naval_nov_marcar(string $login, array $ids): int
{
    return naval_exclusivo('novidades', function () use ($login, $ids) {
        $dir = naval_pasta('novidades');
        $existentes = array_column(naval_json_ler($dir . '/actualizacoes.json'), 'id');
        $ids = array_values(array_intersect(array_map('strval', $ids), $existentes));
        $vistas = naval_json_ler($dir . '/vistas.json');
        $vistas[$login] = array_values(array_intersect(array_unique(array_merge((array) ($vistas[$login] ?? []), $ids)), $existentes));
        naval_json_gravar($dir . '/vistas.json', $vistas);
        return count($ids);
    });
}


// ── Aviso a todos os utilizadores depois de cada actualização (ISO/IEC 20000-1 8.5.1 e 8.5.3; ISO/IEC 27001 A.6.3,
// A.8.32): o que muda para a pessoa e como proceder, só sobre as páginas a que tem acesso, na sua língua; fica a prova
// de quem tomou conhecimento («Li e compreendi»); «Mais tarde» volta a mostrar na próxima entrada. ──

/** A secção diz respeito a esta conta? Público «todos», sem páginas, ou alguma página que vê (nível da matriz). */
function naval_nov_para(array $s, callable $ve): bool
{
    if (empty($s['aviso']) || (empty($s['aviso']['resumo']) && empty($s['aviso']['passos']))) return false;
    if (($s['publico'] ?? '') === 'todos' || empty($s['paginas'])) return true;
    foreach ((array) $s['paginas'] as $p) if ($ve((string) $p)) return true;
    return false;
}

/** Avisos por confirmar para a conta em sessão: actualizações com as secções que lhe dizem respeito. */
function naval_nov_avisos(string $login): array
{
    $conf = naval_json_ler(naval_pasta('novidades') . '/confirmacoes.json');
    $out = [];
    foreach (naval_nov_detectar() as $a) {
        if (isset($conf[$a['id']][$login])) continue;
        $ss = array_values(array_filter((array) ($a['seccoes'] ?? []), function ($s) {
            return naval_nov_para($s, function ($p) { return naval_pode($p, 'Vr'); });
        }));
        if (!$ss) continue;
        $out[] = ['id' => $a['id'], 'versao' => (string) ($a['versao'] ?? ''), 'detectada_em' => (string) ($a['detectada_em'] ?? ''),
            'seccoes' => array_map(function ($s) { return ['id' => $s['id'], 'titulo' => $s['titulo'], 'tipo' => $s['tipo'], 'aviso' => $s['aviso']]; }, $ss)];
    }
    return $out;
}

/** «Li e compreendi»: regista quando esta conta tomou conhecimento (prova para a auditoria). */
function naval_nov_confirmar(string $login, array $ids): int
{
    return naval_exclusivo('novidades', function () use ($login, $ids) {
        $dir = naval_pasta('novidades');
        $existentes = array_column(naval_json_ler($dir . '/actualizacoes.json'), 'id');
        $ids = array_values(array_intersect(array_map('strval', $ids), $existentes));
        $conf = naval_json_ler($dir . '/confirmacoes.json');
        foreach ($ids as $id) if (!isset($conf[$id][$login])) $conf[$id][$login] = date('Y-m-d H:i:s');
        // só as actualizações guardadas
        $conf = array_intersect_key($conf, array_flip($existentes));
        naval_json_gravar($dir . '/confirmacoes.json', $conf);
        if ($ids) naval_registar('novidades_confirmadas', ['actualizacoes' => $ids]);
        return count($ids);
    });
}

/**
 * Adesão aos avisos (super administrador): por actualização com aviso, quantas contas activas devem tomar
 * conhecimento e quantas já confirmaram, com os nomes de quem falta.
 */
function naval_nov_adesao(): array
{
    $conf = naval_json_ler(naval_pasta('novidades') . '/confirmacoes.json');
    $m = naval_matriz();
    $contas = array_filter(naval_utilizadores(), 'naval_conta_valida');
    $out = [];
    foreach (naval_nov_detectar() as $a) {
        $ss = array_values(array_filter((array) ($a['seccoes'] ?? []), function ($s) { return !empty($s['aviso']); }));
        if (!$ss) continue;
        $devem = $faltam = [];
        foreach ($contas as $l => $u) {
            $perfil = (string) ($u['perfil'] ?? '');
            $ve = function ($p) use ($m, $perfil) { return $perfil === 'SADM' || (NAVAL_ORDEM_NIVEIS[(string) ($m[$p][$perfil] ?? '')] ?? 0) >= NAVAL_ORDEM_NIVEIS['Vr']; };
            $diz = false;
            foreach ($ss as $s) if (naval_nov_para($s, $ve)) $diz = true;
            if (!$diz) continue;
            $devem[] = (string) $l;
            if (!isset($conf[$a['id']][$l])) $faltam[] = (string) ($u['nome'] ?? $l);
        }
        $out[] = ['id' => $a['id'], 'versao' => (string) ($a['versao'] ?? ''), 'detectada_em' => (string) ($a['detectada_em'] ?? ''),
            'titulos' => array_column($ss, 'titulo'), 'destinatarios' => count($devem), 'confirmaram' => count($devem) - count($faltam), 'faltam' => $faltam];
    }
    return $out;
}
