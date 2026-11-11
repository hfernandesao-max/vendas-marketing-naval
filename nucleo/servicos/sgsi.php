<?php
/**
 * Sistema de Gestão da Segurança da Informação (ISO/IEC 27001:2022): a informação documentada obrigatória, guardada
 * no código e com versão (nucleo/conformidade/sgsi.json e manual-sgsi.html):
 *
 *   ambito       âmbito do SGSI (cláusula 4.3)
 *   manual       política de segurança e procedimentos (5.2 e anexo A), página bilingue
 *   objectivos   objectivos de segurança com medição automática (6.2 e 9.1)
 *   soa          declaração de aplicabilidade: os 93 controlos da ISO/IEC 27002:2022 (6.1.3 d)
 *   riscos       registo de riscos e plano de tratamento (6.1.2, 6.1.3, 8.2, 8.3)
 *   ropa         registo das actividades de tratamento de dados pessoais (ISO/IEC 27701 e Lei n.º 22/11)
 *   auditoria    programa de auditoria interna e revisão pela direcção (9.2 e 9.3)
 *
 * Cada documento tem um resumo SHA-256 do seu conteúdo. O super administrador aprova-o; a aprovação guarda o resumo,
 * quem e quando. Se o conteúdo mudar numa versão nova, o documento volta a «por aprovar» até nova aprovação.
 * Não conformidades e acções correctivas (10.2): tipo «nao_conformidade» do módulo Suporte.
 */

const NAVAL_SGSI_DOCUMENTOS = [
    'ambito' => 'Âmbito do SGSI',
    'manual' => 'Manual do SGSI: política e procedimentos',
    'objectivos' => 'Objectivos de segurança',
    'soa' => 'Declaração de aplicabilidade',
    'riscos' => 'Registo de riscos e plano de tratamento',
    'ropa' => 'Registo das actividades de tratamento',
    'auditoria' => 'Programa de auditoria e revisão pela direcção',
];
/** Nível de risco aceitável (probabilidade × impacto, de 1 a 25). */
const NAVAL_SGSI_RISCO_ACEITAVEL = 6;

function naval_sgsi_dados(): array
{
    static $d = null;
    if ($d === null) {
        $d = json_decode((string) @file_get_contents(__DIR__ . '/../conformidade/sgsi.json'), true);
        if (!is_array($d)) throw new NavalErro('Falta o ficheiro do SGSI.', 500);
    }
    return $d;
}

function naval_sgsi_manual_ficheiro(): string
{
    return __DIR__ . '/../conformidade/manual-sgsi.html';
}

/** Resumo SHA-256 do conteúdo de um documento do SGSI. */
function naval_sgsi_hash(string $doc): string
{
    if (!isset(NAVAL_SGSI_DOCUMENTOS[$doc])) throw new NavalErro('Documento desconhecido.', 400);
    if ($doc === 'manual') return hash_file('sha256', naval_sgsi_manual_ficheiro()) ?: '';
    $d = naval_sgsi_dados();
    $chave = ['soa' => 'controlos'][$doc] ?? $doc;
    $conteudo = $doc === 'soa' ? ['controlos' => $d['controlos'], 'evidencias' => $d['evidencias']] : $d[$chave];
    return hash('sha256', (string) json_encode($conteudo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function naval_sgsi_aprovacoes_ficheiro(): string
{
    return naval_conf_pasta() . '/aprovacoes.json';
}

/** Aprovação de um documento pelo super administrador (o resumo aprovado é o do conteúdo actual). */
function naval_sgsi_aprovar(string $doc, array $eu, string $notas): array
{
    $hash = naval_sgsi_hash($doc);
    $reg = naval_exclusivo('sgsi-aprovacoes', function () use ($doc, $hash, $eu, $notas) {
        $todas = naval_json_ler(naval_sgsi_aprovacoes_ficheiro());
        foreach ($todas as $a) {
            if ($a['doc'] === $doc && $a['hash'] === $hash) throw new NavalErro('Esta versão do documento já está aprovada.', 409);
        }
        $a = ['doc' => $doc, 'versao' => (string) (naval_sgsi_dados()['versao'] ?? ''), 'hash' => $hash, 'por' => (string) ($eu['login'] ?? ''),
            'nome' => (string) ($eu['nome'] ?? $eu['login'] ?? ''), 'em' => date('Y-m-d H:i:s'), 'notas' => mb_substr(trim($notas), 0, 1000)];
        $todas[] = $a;
        naval_json_gravar(naval_sgsi_aprovacoes_ficheiro(), $todas);
        return $a;
    });
    naval_registar('sgsi_aprovado', ['doc' => $doc, 'hash' => $hash]);
    return $reg;
}

/** Estado de cada documento: aprovado (o resumo actual tem aprovação), alterado (houve aprovação de outra versão) ou por aprovar. */
function naval_sgsi_documentos(): array
{
    $aprov = naval_json_ler(naval_sgsi_aprovacoes_ficheiro());
    $out = [];
    foreach (NAVAL_SGSI_DOCUMENTOS as $id => $nome) {
        $hash = naval_sgsi_hash($id);
        $deste = array_values(array_filter($aprov, function ($a) use ($id) { return ($a['doc'] ?? '') === $id; }));
        $actual = null;
        foreach ($deste as $a) if ($a['hash'] === $hash) $actual = $a;
        $out[] = ['id' => $id, 'nome' => $nome, 'hash' => $hash, 'estado' => $actual ? 'aprovado' : ($deste ? 'alterado' : 'por_aprovar'),
            'aprovacao' => $actual ?: ($deste ? end($deste) : null)];
    }
    return $out;
}

function naval_sgsi_nivel(array $r, bool $residual = false): int
{
    $x = $residual ? $r['residual'] : $r;
    return (int) $x['probabilidade'] * (int) $x['impacto'];
}

/** Medição automática dos objectivos de segurança (percentagem; null se ainda não há dados). */
function naval_sgsi_medicoes(array $d): array
{
    $e = naval_conf_estado();
    $m = [];
    $m['registos'] = isset($e['registos']['ok']) ? ($e['registos']['ok'] ? 100 : 0) : null;
    $m['recuperacao'] = isset($e['copia']['recuperacao']['ok']) ? ($e['copia']['recuperacao']['ok'] ? 100 : 0) : null;
    $revs = [];
    foreach (glob(naval_conf_pasta() . '/revisao-*.json') ?: [] as $f) {
        $j = naval_json_ler($f);
        // o trimestre em curso ainda tem tempo; conta só quando confirmado ou já fechado
        if (!empty($j['confirmada']) || ($j['trimestre'] ?? '') !== naval_conf_trimestre(time())) $revs[] = !empty($j['confirmada']);
    }
    $m['revisao'] = $revs ? (int) round(100 * count(array_filter($revs)) / count($revs)) : null;
    naval_servico('suporte');
    $agora = time();
    $res = array_filter(naval_sup_todos(), function ($p) use ($agora) { return !empty($p['resolvido_em']) && strtotime($p['resolvido_em']) >= $agora - 30 * 86400; });
    $noPrazo = array_filter($res, function ($p) { return strtotime($p['resolvido_em']) <= strtotime($p['prazo']); });
    $m['sla'] = $res ? (int) round(100 * count($noPrazo) / count($res)) : null;
    $apl = array_filter($d['controlos'], function ($c) { return $c['aplicavel']; });
    $imp = array_filter($apl, function ($c) { return $c['estado'] === 'implementado'; });
    $m['controlos'] = $apl ? (int) round(100 * count($imp) / count($apl)) : null;
    $ok = array_filter($d['riscos'], function ($r) { return naval_sgsi_nivel($r, true) <= NAVAL_SGSI_RISCO_ACEITAVEL; });
    $m['riscos'] = $d['riscos'] ? (int) round(100 * count($ok) / count($d['riscos'])) : null;
    return $m;
}

/** Tudo o que a página Conformidade mostra do SGSI. */
function naval_sgsi_vista(): array
{
    $d = naval_sgsi_dados();
    $med = naval_sgsi_medicoes($d);
    $objectivos = array_map(function ($o) use ($med) {
        $v = $med[$o['medida']] ?? null;
        return $o + ['valor' => $v, 'cumpre' => $v === null ? null : $v >= $o['meta']];
    }, $d['objectivos']);
    $riscos = array_map(function ($r) {
        return $r + ['nivel' => naval_sgsi_nivel($r), 'nivel_residual' => naval_sgsi_nivel($r, true),
            'aceitavel' => naval_sgsi_nivel($r, true) <= NAVAL_SGSI_RISCO_ACEITAVEL];
    }, $d['riscos']);
    $porEstado = array_count_values(array_column($d['controlos'], 'estado'));
    naval_servico('suporte');
    $nc = array_filter(naval_sup_todos(), function ($p) { return $p['tipo'] === 'nao_conformidade'; });
    return [
        'versao' => $d['versao'], 'data' => $d['data'], 'ambito' => $d['ambito'], 'normas' => $d['normas'],
        'documentos' => naval_sgsi_documentos(),
        'objectivos' => $objectivos,
        'controlos' => $d['controlos'], 'evidencias' => $d['evidencias'], 'temas' => $d['temas'], 'responsaveis' => $d['responsaveis'],
        'resumo_controlos' => ['total' => count($d['controlos']), 'por_estado' => $porEstado],
        'riscos' => $riscos, 'risco_aceitavel' => NAVAL_SGSI_RISCO_ACEITAVEL,
        'ropa' => $d['ropa'], 'auditoria' => $d['auditoria'],
        'nao_conformidades' => [
            'abertas' => count(array_filter($nc, function ($p) { return in_array($p['estado'], ['novo', 'em_curso'], true); })),
            'total' => count($nc),
        ],
    ];
}
