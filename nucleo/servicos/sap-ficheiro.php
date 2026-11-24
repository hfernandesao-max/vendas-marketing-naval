<?php
/**
 * Ficheiro de vendas para o SAP montado no servidor (envio automático): as mesmas regras de reports/js/paginas/sap.js
 * (linhasSap e gerarExcelSap), linha a linha, para o ficheiro das 16:00 sair igual ao que a página gera. O teste
 * tests/unidade/sap-ficheiro.teste.php confirma a folha célula a célula com o mesmo caso do teste do portal.
 *
 * Funções puras (sem base de dados): naval_sap_linhas e naval_sap_xlsx.
 */
defined('NAVAL_NUCLEO') || exit;

const NAVAL_SAP_CAB = ['INVOICENO', 'DOCUMENT_DATE', 'LOJA', 'PRODUCT', 'QUANTITY', 'UNIT', 'DOCUMENT_TYPE', 'STORAGELOCATION', 'NETSALES', 'TAXAMOUNT',
    'GROSSSALES', 'CURRENCY', 'Invoice Total', 'CASH AMOUNT', 'CARD AMOUNT', 'CUSTOMER', 'SALES EMPLOYEE'];
// formato de apresentação por coluna: 1 = data dd/mm/aaaa; 2 = #,##0.00
const NAVAL_SAP_FMT = ['B' => 1, 'I' => 2, 'J' => 2, 'K' => 2, 'M' => 2, 'N' => 2, 'O' => 2];

/** Arredonda a 2 casas como o Math.round do JavaScript (metade sempre para cima, também nos negativos). */
function naval_sap_r2($v): float
{
    return floor((float) $v * 100 + 0.5) / 100;
}

/** Categoria do meio de cobrança (reports/js/meios.js: catMeio). */
function naval_sap_cat_meio(array $p): string
{
    $c = strtoupper((string) ($p['codigo'] ?? ''));
    $m = mb_strtoupper((string) ($p['meio'] ?? ''), 'UTF-8');
    if ($c === 'CMP') return 'compensacao';
    if ($c === '?') return 'sem_meio';
    if ($c === 'NU' || preg_match('/NUMER|DINHEIRO|CASH/u', $m)) return 'dinheiro';
    if ($c === 'MC' || preg_match('/MULTI|TPA|CART|CARD/u', $m)) return 'multicaixa';
    if ($c === 'TB' || preg_match('/TRANSF/u', $m)) return 'transferencia';
    return 'outro';
}

/** Pagamentos do documento para o SAP: os originais da substituição ou os do Pinguim BO (sap.js: pagosSap / pagosDe). */
function naval_sap_pagos(array $v): array
{
    if (isset($v['pagamentos_origem']) && is_array($v['pagamentos_origem'])) return $v['pagamentos_origem'];
    if (isset($v['pagamentos']) && is_array($v['pagamentos'])) return $v['pagamentos'];
    if (empty($v['liquidado'])) return [];
    $meio = (string) ($v['meio_pagamento'] ?? '');
    return [['codigo' => $meio !== '' ? '' : '?', 'meio' => $meio !== '' ? $meio : 'Sem meio indicado', 'valor' => (float) ($v['total'] ?? 0)]];
}

function naval_sap_meio_doc(array $v, string $cat): float
{
    $s = 0.0;
    foreach (naval_sap_pagos($v) as $p) if (naval_sap_cat_meio((array) $p) === $cat) $s += (float) ($p['valor'] ?? 0);
    return $s;
}

/** Tipo do documento (calculos.js: tipoDoc). */
function naval_sap_tipo_doc(array $v): string
{
    $t = (string) ($v['tipo_codigo'] ?? '');
    return $t !== '' ? $t : (($v['tipo'] ?? '') === 'Factura' ? 'FT' : 'FR');
}

/** Loja, depósito (se indicado na rota) e empregado de vendas de uma rota (sap.js: mapaSap). */
function naval_sap_mapa(string $rota, array $cfg, array $promotores): array
{
    $m = (array) ($cfg['rotas'][$rota] ?? []);
    $nome = (string) ($promotores[$rota] ?? $rota);
    $pre = (string) ($cfg['prefixo'] ?? 'FHR PIV');
    return ['loja' => ($m['loja'] ?? '') !== '' ? (string) $m['loja'] : $rota . ' - ' . $pre, 'local' => (string) ($m['local'] ?? ''),
        'empregado' => ($m['empregado'] ?? '') !== '' ? (string) $m['empregado'] : $pre . ' - ROTA ' . $rota . ' - ' . $nome];
}

/** Depósito SAP de uma linha e como foi determinado: rota (manual) | origem (stock) | omissao | '' (sap.js: localLinha). */
function naval_sap_local(string $rota, string $codigo, array $cfg, array $origens): array
{
    $manual = (string) ($cfg['rotas'][$rota]['local'] ?? '');
    if ($manual !== '') return ['local' => $manual, 'como' => 'rota'];
    $r = $origens['rotas'][$rota] ?? null;
    $auto = '';
    if (is_array($r)) $auto = (string) (($codigo !== '' ? ($r['artigos'][$codigo] ?? '') : '') ?: ($r['local'] ?? ''));
    if ($auto === '' && $codigo !== '') $auto = (string) ($origens['artigos'][$codigo] ?? '');
    if ($auto !== '') return ['local' => $auto, 'como' => 'origem'];
    $om = (string) ($cfg['local_omissao'] ?? '');
    return $om !== '' ? ['local' => $om, 'como' => 'omissao'] : ['local' => '', 'como' => ''];
}

/**
 * Linhas do ficheiro para o SAP (sap.js: linhasSap). $vendas e $artigos como os devolve v1/vendas (fonte fiscal);
 * $exportados: documentos já em lotes válidos. Devolve {docs, linhas, erros: [{documento, texto}], avisos, repetidos,
 * sem_local: [rota], omissao: [rota]}. Um documento com erro fica de fora das linhas (o envio automático segue com
 * os restantes e lista o que ficou por corrigir).
 */
function naval_sap_linhas(array $vendas, array $artigos, array $cfg, array $origens, array $exportados, array $promotores, string $de, string $ate): array
{
    $porDoc = [];
    foreach ($artigos as $a) $porDoc[(string) $a['documento']][] = $a;
    $V = array_values(array_filter($vendas, function ($v) use ($de, $ate) { $d = (string) ($v['data'] ?? ''); return $d >= $de && $d <= $ate; }));
    usort($V, function ($a, $b) {
        $x = ($a['data'] ?? '') . ($a['hora'] ?? '') . ($a['documento'] ?? '');
        $y = ($b['data'] ?? '') . ($b['hora'] ?? '') . ($b['documento'] ?? '');
        return $x < $y ? -1 : 1;
    });
    $docs = $linhas = $erros = $avisos = [];
    $semLocal = $porOmissao = [];
    $repetidos = 0;
    $kz = function ($v) { return number_format((float) $v, 0, ',', ' ') . ' Kz'; };
    $bruto = function ($a) { return isset($a['gross_total']) ? naval_sap_r2($a['gross_total']) : naval_sap_r2(naval_sap_r2($a['valor_sem_iva'] ?? 0) + naval_sap_r2($a['iva'] ?? 0)); };
    foreach ($V as $v) {
        $doc = (string) $v['documento'];
        if (isset($exportados[$doc])) {
            $repetidos++;
            continue;
        }
        $ls = $porDoc[$doc] ?? [];
        $rota = (string) ($v['rota'] ?? '');
        $m = naval_sap_mapa($rota, $cfg, $promotores);
        $docTxt = naval_sap_tipo_doc($v) . ' ' . $doc;
        $cash = naval_sap_r2(naval_sap_meio_doc($v, 'dinheiro'));
        $card = naval_sap_r2(naval_sap_meio_doc($v, 'multicaixa'));
        $outros = array_values(array_filter(naval_sap_pagos($v), function ($p) { return !in_array(naval_sap_cat_meio((array) $p), ['dinheiro', 'multicaixa', 'compensacao'], true); }));
        if (!$ls) {
            $erros[] = ['documento' => $doc, 'rota' => $rota, 'texto' => $docTxt . ': o documento não tem linhas de artigo.'];
            continue;
        }
        $invoice = 0.0;
        foreach ($ls as $a) $invoice += $bruto($a);
        $invoice = naval_sap_r2($invoice);
        foreach ($ls as $a) {
            $dif = naval_sap_r2($bruto($a) - naval_sap_r2($a['valor_sem_iva'] ?? 0) - naval_sap_r2($a['iva'] ?? 0));
            if (abs($dif) > 1) $avisos[] = $docTxt . ', artigo ' . ($a['codigo'] ?? '') . ': NETSALES + TAXAMOUNT (' . $kz(naval_sap_r2($a['valor_sem_iva'] ?? 0) + naval_sap_r2($a['iva'] ?? 0)) . ') difere do GROSS_TOTAL (' . $kz($bruto($a)) . ').';
        }
        if (abs($invoice - naval_sap_r2($v['total'] ?? 0)) > 1) $avisos[] = $docTxt . ': a soma das linhas (' . $kz($invoice) . ') não bate com o total do documento (' . $kz($v['total'] ?? 0) . ').';
        if ($outros) {
            $s = 0.0;
            foreach ($outros as $p) $s += (float) ($p['valor'] ?? 0);
            $avisos[] = $docTxt . ': ' . $kz($s) . ' pagos por ' . implode(', ', array_map(function ($p) { return (string) ($p['meio'] ?? ''); }, $outros)) . '; o modelo só tem numerário e Multicaixa.';
        }
        $ld = [];
        $erroDoc = null;
        foreach ($ls as $a) {
            $codigo = (string) ($a['codigo'] ?? '');
            $lc = naval_sap_local($rota, $codigo, $cfg, $origens);
            if ($lc['local'] === '') {
                $semLocal[$rota] = true;
                $erroDoc = $erroDoc ?? $docTxt . ': sem depósito SAP para a rota ' . $rota . '.';
            }
            if ($lc['como'] === 'omissao') $porOmissao[$rota] = true;
            if ($codigo === '') $erroDoc = $erroDoc ?? $docTxt . ': linha «' . ($a['artigo'] ?? '') . '» sem código de artigo.';
            $ld[] = [
                'doc' => $docTxt, 'documento' => $doc, 'data' => (string) $v['data'], 'loja' => $m['loja'], 'codigo' => $codigo, 'artigo' => (string) ($a['artigo'] ?? ''),
                'quantidade' => (float) ($a['quantidade'] ?? 0), 'uom' => (string) (($a['unidade'] ?? '') !== '' ? $a['unidade'] : ($cfg['unidade'] ?? 'CAR')),
                'tipo' => (string) ($v['tipo'] ?? ''), 'local' => $lc['local'], 'como' => $lc['como'],
                'net' => naval_sap_r2($a['valor_sem_iva'] ?? 0), 'tax' => naval_sap_r2($a['iva'] ?? 0), 'gross' => $bruto($a),
                'moeda' => (string) ($cfg['moeda'] ?? 'AOA'), 'inv' => $invoice, 'cash' => $cash ?: null, 'card' => $card ?: null,
                'cliente' => (string) ($v['cliente'] ?? ''), 'empregado' => $m['empregado'], 'rota' => $rota,
            ];
        }
        if ($erroDoc !== null) {
            $erros[] = ['documento' => $doc, 'rota' => $rota, 'texto' => $erroDoc];
            continue;
        }
        foreach ($ld as $l) $linhas[] = $l;
        $docs[] = $v;
    }
    ksort($semLocal);
    ksort($porOmissao);
    return ['docs' => $docs, 'linhas' => $linhas, 'erros' => $erros, 'avisos' => $avisos, 'repetidos' => $repetidos,
        'sem_local' => array_map('strval', array_keys($semLocal)), 'omissao' => array_map('strval', array_keys($porOmissao))];
}

/** AAAA-MM-DD → número de série de data do Excel (dias desde 30-12-1899). */
function naval_sap_data_excel(string $d): int
{
    return (int) round((gmmktime(0, 0, 0, (int) substr($d, 5, 2), (int) substr($d, 8, 2), (int) substr($d, 0, 4)) - gmmktime(0, 0, 0, 12, 30, 1899)) / 86400);
}

/** Número como o JavaScript o escreve (String(n)): inteiros sem casas, os outros com o mínimo de dígitos. */
function naval_sap_numero(float $n): string
{
    if (floor($n) === $n && abs($n) < 1e15) return sprintf('%.0f', $n);
    return (string) json_encode($n);
}

function naval_sap_xml(string $s): string
{
    $s = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s);
    return str_replace(['&', '<', '>', '"'], ['&amp;', '&lt;', '&gt;', '&quot;'], $s);
}

/** Célula da folha (sap.js: celula). */
function naval_sap_celula(string $ref, $v, int $fmt): string
{
    if ($v === null || $v === '') return '';
    if (is_int($v) || is_float($v)) return '<c r="' . $ref . '"' . ($fmt ? ' s="' . $fmt . '"' : '') . '><v>' . naval_sap_numero((float) $v) . '</v></c>';
    $s = (string) $v;
    return '<c r="' . $ref . '" t="inlineStr"><is><t' . (preg_match('/^\s|\s$/u', $s) ? ' xml:space="preserve"' : '') . '>' . naval_sap_xml($s) . '</t></is></c>';
}

/** Folha «Upload Format» (XML) com o cabeçalho e as linhas. */
function naval_sap_folha(array $linhas): string
{
    $col = str_split('ABCDEFGHIJKLMNOPQ');
    $linha = function (int $n, array $vals, bool $fmt) use ($col) {
        $s = '<row r="' . $n . '">';
        foreach ($vals as $k => $v) $s .= naval_sap_celula($col[$k] . $n, $v, $fmt ? (NAVAL_SAP_FMT[$col[$k]] ?? 0) : 0);
        return $s . '</row>';
    };
    $dados = $linha(1, NAVAL_SAP_CAB, false);
    foreach (array_values($linhas) as $i => $l) {
        $dados .= $linha($i + 2, [$l['doc'], naval_sap_data_excel($l['data']), $l['loja'], preg_match('/^\d+$/', $l['codigo']) ? (float) $l['codigo'] : $l['codigo'],
            $l['quantidade'], $l['uom'], $l['tipo'], $l['local'], $l['net'], $l['tax'], $l['gross'], $l['moeda'], $l['inv'], $l['cash'], $l['card'], $l['cliente'], $l['empregado']], true);
    }
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<dimension ref="A1:Q' . (count($linhas) + 1) . '"/><sheetData>' . $dados . '</sheetData></worksheet>';
}

/** Arquivo ZIP (DEFLATE) a partir de {nome: conteúdo}, sem depender do ZipArchive. */
function naval_zip(array $ficheiros): string
{
    $corpo = $central = '';
    $n = 0;
    [$hora, $data] = [0, (1980 - 1980) << 9 | 1 << 5 | 1];
    foreach ($ficheiros as $nome => $conteudo) {
        $nome = (string) $nome;
        $crc = crc32($conteudo);
        $comp = (string) gzdeflate($conteudo, 6);
        $cab = pack('vvvvvVVVvv', 20, 0x0800, 8, $hora, $data, $crc, strlen($comp), strlen($conteudo), strlen($nome), 0);
        $desloc = strlen($corpo);
        $corpo .= "PK\x03\x04" . $cab . $nome . $comp;
        $central .= "PK\x01\x02" . pack('vvvvvvVVVvvvvvVV', 20, 20, 0x0800, 8, $hora, $data, $crc, strlen($comp), strlen($conteudo), strlen($nome), 0, 0, 0, 0, 0, $desloc) . $nome;
        $n++;
    }
    return $corpo . $central . "PK\x05\x06" . pack('vvvvVVv', 0, 0, $n, $n, strlen($central), strlen($corpo), 0);
}

/** Livro Excel do ficheiro para o SAP (sap.js: gerarExcelSap): uma folha, só formatos de número. */
function naval_sap_xlsx(array $linhas): string
{
    $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $rel = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    return naval_zip([
        '[Content_Types].xml' => $x . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>',
        '_rels/.rels' => $x . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="' . $rel . '/officeDocument" Target="xl/workbook.xml"/></Relationships>',
        'xl/workbook.xml' => $x . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="' . $rel . '">'
            . '<sheets><sheet name="Upload Format" sheetId="1" r:id="rId1"/></sheets></workbook>',
        'xl/_rels/workbook.xml.rels' => $x . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="' . $rel . '/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="' . $rel . '/styles" Target="styles.xml"/></Relationships>',
        'xl/worksheets/sheet1.xml' => naval_sap_folha($linhas),
        'xl/styles.xml' => $x . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<numFmts count="1"><numFmt numFmtId="164" formatCode="dd/mm/yyyy"/></numFmts>'
            . '<fonts count="1"><font><sz val="11"/><name val="Calibri"/><family val="2"/></font></fonts>'
            . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="3"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '<xf numFmtId="4" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/></cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>',
    ]);
}
