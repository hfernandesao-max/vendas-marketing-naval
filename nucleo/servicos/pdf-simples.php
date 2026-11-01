<?php
/**
 * Gerador de PDF simples, sem dependências (PDF 1.4): páginas A4 com texto (Helvetica e Helvetica-Bold, codificação
 * WinAnsi, que cobre o português), linhas e imagens JPEG. Serve os documentos do módulo Equipamentos na fase 1;
 * a versão PDF/A-3 selada (PAdES-B-LT com selo temporal) é da fase 2, num serviço próprio de assinatura.
 *
 * Uso: $p = naval_pdf_novo(); naval_pdf_texto($p, …); …; $bytes = naval_pdf_gerar($p);
 */
defined('NAVAL_NUCLEO') || exit;

const NAVAL_PDF_L = 595.28;   // largura A4 em pontos
const NAVAL_PDF_A = 841.89;   // altura A4 em pontos
const NAVAL_PDF_MARGEM = 56.0;

function naval_pdf_novo(array $meta = []): array
{
    return ['paginas' => [''], 'imagens' => [], 'y' => NAVAL_PDF_A - NAVAL_PDF_MARGEM, 'meta' => $meta, 'rodape' => $meta['rodape'] ?? ''];
}

/** Texto UTF-8 → WinAnsi (Windows-1252), escapado para uma string PDF. */
function naval_pdf_str(string $s): string
{
    return '(' . strtr(naval_pdf_win($s), ['\\' => '\\\\', '(' => '\\(', ')' => '\\)', "\r" => '', "\n" => ' ']) . ')';
}

/** Larguras dos caracteres WinAnsi (0–255) da Helvetica e da Helvetica-Bold, em milésimos do tamanho (métricas AFM). */
const NAVAL_PDF_LARG_F1 = '0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,278,278,355,556,556,889,667,191,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,278,278,584,584,584,556,1015,667,667,722,722,667,611,778,722,278,500,667,556,833,722,778,667,778,722,667,611,722,667,944,667,667,611,278,278,278,469,556,333,556,556,500,556,556,278,556,556,222,222,500,222,833,556,556,556,556,333,500,278,556,500,722,500,500,500,334,260,334,584,761,556,0,222,556,333,1000,556,556,333,1000,667,333,1000,0,611,0,0,222,222,333,333,350,556,1000,333,1000,500,333,944,0,500,667,278,333,556,556,556,556,260,556,333,737,370,556,584,333,737,333,400,584,333,333,333,556,537,278,333,333,365,556,834,834,834,611,667,667,667,667,667,667,1000,722,667,667,667,667,278,278,278,278,722,722,778,778,778,778,778,584,778,722,722,722,722,667,667,611,556,556,556,556,556,556,889,500,556,556,556,556,278,278,278,278,556,556,556,556,556,556,556,584,611,556,556,556,556,500,556,500';
const NAVAL_PDF_LARG_F2 = '0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,278,333,474,556,556,889,722,238,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,333,333,584,584,584,611,975,722,722,722,722,667,611,778,722,278,556,722,611,833,722,778,667,778,722,667,611,722,667,944,667,667,611,333,278,333,584,556,333,556,611,556,611,556,333,611,611,278,278,556,278,889,611,611,611,611,389,556,333,611,556,778,556,556,500,389,280,389,584,761,556,0,278,556,500,1000,556,556,333,1000,667,333,1000,0,611,0,0,278,278,500,500,350,556,1000,333,1000,556,333,944,0,500,667,278,333,556,556,556,556,280,556,333,737,370,556,584,333,737,333,400,584,333,333,333,611,556,278,333,333,365,556,834,834,834,611,722,722,722,722,722,722,1000,722,667,667,667,667,278,278,278,278,722,722,778,778,778,778,778,584,778,722,722,722,722,667,667,611,556,556,556,556,556,556,889,556,556,556,556,556,278,278,278,278,611,611,611,611,611,611,611,584,611,611,611,611,611,556,611,556';

/** Texto UTF-8 → Windows-1252 (a codificação das fontes do PDF). */
function naval_pdf_win(string $s): string
{
    $s = str_replace(["\u{2013}", "\u{2014}", "\u{2019}", "\u{201C}", "\u{201D}", "\u{2026}", "\u{00A0}"], ['-', '-', "'", '"', '"', '...', ' '], $s);
    return function_exists('mb_convert_encoding') ? (string) mb_convert_encoding($s, 'Windows-1252', 'UTF-8') : (string) @iconv('UTF-8', 'Windows-1252//TRANSLIT', $s);
}

/** Largura exacta de um texto em Helvetica (pontos), para quebrar e justificar as linhas. */
function naval_pdf_largura(string $s, float $tam, bool $negrito = false): float
{
    static $tab = [];
    $k = $negrito ? 2 : 1;
    if (!isset($tab[$k])) $tab[$k] = array_map('intval', explode(',', $negrito ? NAVAL_PDF_LARG_F2 : NAVAL_PDF_LARG_F1));
    $w = 0;
    $b = naval_pdf_win($s);
    for ($i = 0, $n = strlen($b); $i < $n; $i++) $w += $tab[$k][ord($b[$i])] ?: 556;
    return $w * $tam / 1000;
}

/** Quebra o texto em linhas que cabem na largura; com $fim, marca as linhas que terminam um parágrafo. */
function naval_pdf_linhas(string $s, float $tam, float $largura, bool $negrito = false, array &$fim = []): array
{
    $out = [];
    $fim = [];
    foreach (preg_split('/\R/u', $s) as $par) {
        $linha = '';
        foreach (preg_split('/\s+/u', trim($par)) as $p) {
            $tenta = $linha === '' ? $p : $linha . ' ' . $p;
            if ($linha !== '' && naval_pdf_largura($tenta, $tam, $negrito) > $largura) {
                $fim[] = false;
                $out[] = $linha;
                $linha = $p;
            } else {
                $linha = $tenta;
            }
        }
        $fim[] = true;
        $out[] = $linha;
    }
    return $out;
}

function naval_pdf_nova_pagina(array &$p): void
{
    $p['paginas'][] = '';
    $p['y'] = NAVAL_PDF_A - NAVAL_PDF_MARGEM;
}

/** Garante espaço na página actual (senão abre outra). */
function naval_pdf_espaco(array &$p, float $altura): void
{
    if ($p['y'] - $altura < NAVAL_PDF_MARGEM + 24) naval_pdf_nova_pagina($p);
}

function naval_pdf_op(array &$p, string $op): void
{
    $p['paginas'][count($p['paginas']) - 1] .= $op . "\n";
}

/**
 * Parágrafo com quebra de linha, justificado (espaçamento entre palavras, Tw), salvo a última linha de cada
 * parágrafo; $cor em RGB 0–1.
 */
function naval_pdf_texto(array &$p, string $s, float $tam = 10, bool $negrito = false, float $x = NAVAL_PDF_MARGEM, ?float $largura = null, array $cor = [0.04, 0.12, 0.23]): void
{
    $largura = $largura ?? (NAVAL_PDF_L - NAVAL_PDF_MARGEM - $x);
    $alt = $tam * 1.35;
    $fim = [];
    foreach (naval_pdf_linhas($s, $tam, $largura, $negrito, $fim) as $i => $l) {
        naval_pdf_espaco($p, $alt);
        $p['y'] -= $alt;
        $espacos = substr_count($l, ' ');
        $tw = !$fim[$i] && $espacos ? ($largura - naval_pdf_largura($l, $tam, $negrito)) / $espacos : 0.0;
        naval_pdf_op($p, sprintf('BT /%s %.1F Tf %.3F %.3F %.3F rg %.3F Tw %.2F %.2F Td %s Tj ET', $negrito ? 'F2' : 'F1', $tam, $cor[0], $cor[1], $cor[2], max(0.0, $tw), $x, $p['y'], naval_pdf_str($l)));
    }
}

/** Par «rótulo: valor» numa linha (rótulo em negrito). */
function naval_pdf_campo(array &$p, string $rotulo, string $valor, float $tam = 10): void
{
    $alt = $tam * 1.45;
    $larg = NAVAL_PDF_L - 2 * NAVAL_PDF_MARGEM - 150;
    $linhas = naval_pdf_linhas($valor === '' ? '—' : $valor, $tam, $larg);
    naval_pdf_espaco($p, $alt * count($linhas));
    $p['y'] -= $alt;
    naval_pdf_op($p, sprintf('BT /F2 %.1F Tf 0.27 0.33 0.43 rg %.2F %.2F Td %s Tj ET', $tam, NAVAL_PDF_MARGEM, $p['y'], naval_pdf_str($rotulo)));
    foreach ($linhas as $i => $l) {
        if ($i) $p['y'] -= $alt;
        naval_pdf_op($p, sprintf('BT /F1 %.1F Tf 0.04 0.12 0.23 rg %.2F %.2F Td %s Tj ET', $tam, NAVAL_PDF_MARGEM + 150, $p['y'], naval_pdf_str($l)));
    }
}

function naval_pdf_linha(array &$p, float $espaco = 8, array $cor = [0.0, 0.34, 0.72]): void
{
    naval_pdf_espaco($p, $espaco * 2);
    $p['y'] -= $espaco;
    naval_pdf_op($p, sprintf('%.3F %.3F %.3F RG 0.8 w %.2F %.2F m %.2F %.2F l S', $cor[0], $cor[1], $cor[2], NAVAL_PDF_MARGEM, $p['y'], NAVAL_PDF_L - NAVAL_PDF_MARGEM, $p['y']));
    $p['y'] -= $espaco;
}

function naval_pdf_espacar(array &$p, float $n): void
{
    $p['y'] -= $n;
}

/** Imagem JPEG (bytes) com a largura indicada; devolve false se não for um JPEG válido. */
function naval_pdf_imagem(array &$p, string $jpeg, float $largura, float $x = NAVAL_PDF_MARGEM): bool
{
    $info = @getimagesizefromstring($jpeg);
    if (!$info || $info[2] !== IMAGETYPE_JPEG) return false;
    $alt = $largura * $info[1] / max(1, $info[0]);
    naval_pdf_espaco($p, $alt + 4);
    $n = count($p['imagens']) + 1;
    $p['imagens'][$n] = ['dados' => $jpeg, 'l' => $info[0], 'a' => $info[1], 'canais' => $info['channels'] ?? 3];
    $p['y'] -= $alt;
    naval_pdf_op($p, sprintf('q %.2F 0 0 %.2F %.2F %.2F cm /Im%d Do Q', $largura, $alt, $x, $p['y'], $n));
    return true;
}

/**
 * Objectos das imagens, dos conteúdos e das páginas, numerados a partir de $prox (as fontes são os objectos 3 e 4,
 * o nó das páginas é o 2). $primeira e $total servem o «Página n de N» do rodapé.
 */
function naval_pdf_objectos(array $p, int &$prox, int $primeira, int $total, string $rotulo = 'Página'): array
{
    $obj = [];
    $imgRef = [];
    foreach ($p['imagens'] as $n => $im) {
        $cs = $im['canais'] == 1 ? '/DeviceGray' : ($im['canais'] == 4 ? '/DeviceCMYK' : '/DeviceRGB');
        $obj[$prox] = ['<< /Type /XObject /Subtype /Image /Width ' . $im['l'] . ' /Height ' . $im['a'] . ' /ColorSpace ' . $cs
            . ' /BitsPerComponent 8 /Filter /DCTDecode /Length ' . strlen($im['dados']) . ' >>', $im['dados']];
        $imgRef[] = '/Im' . $n . ' ' . $prox . ' 0 R';
        $prox++;
    }
    $recursos = '<< /Font << /F1 3 0 R /F2 4 0 R >>' . ($imgRef ? ' /XObject << ' . implode(' ', $imgRef) . ' >>' : '') . ' >>';
    $kids = [];
    foreach ($p['paginas'] as $i => $conteudo) {
        // rodapé de cada página: texto fixo do documento e «Página n de N»
        $rod = '';
        if ($p['rodape'] !== '') $rod .= sprintf('BT /F1 7.5 Tf 0.35 0.4 0.5 rg %.2F 30 Td %s Tj ET', NAVAL_PDF_MARGEM, naval_pdf_str($p['rodape'])) . "\n";
        $rod .= sprintf('BT /F1 7.5 Tf 0.35 0.4 0.5 rg %.2F 30 Td %s Tj ET', NAVAL_PDF_L - NAVAL_PDF_MARGEM - 60, naval_pdf_str($rotulo . ' ' . ($primeira + $i) . ' de ' . $total)) . "\n";
        $stream = $conteudo . $rod;
        $c = $prox++;
        $obj[$c] = ['<< /Length ' . strlen($stream) . ' >>', $stream];
        $pg = $prox++;
        $obj[$pg] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' . NAVAL_PDF_L . ' ' . NAVAL_PDF_A . '] /Resources ' . $recursos . ' /Contents ' . $c . ' 0 R >>';
        $kids[] = $pg . ' 0 R';
    }
    return [$obj, $kids];
}

function naval_pdf_escrever_obj(int $n, $o): string
{
    return is_array($o) ? $n . " 0 obj\n" . $o[0] . "\nstream\n" . $o[1] . "\nendstream\nendobj\n" : $n . " 0 obj\n" . $o . "\nendobj\n";
}

/** Monta o ficheiro PDF (objectos, xref e trailer). */
function naval_pdf_gerar(array $p): string
{
    $obj = [];
    $obj[1] = '<< /Type /Catalog /Pages 2 0 R >>';
    $obj[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
    $obj[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
    $prox = 5;
    $total = count($p['paginas']);
    list($novos, $kids) = naval_pdf_objectos($p, $prox, 1, $total);
    $obj += $novos;
    $obj[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
    $info = $prox++;
    $m = $p['meta'];
    $obj[$info] = '<< /Title ' . naval_pdf_str((string) ($m['titulo'] ?? 'Documento')) . ' /Author ' . naval_pdf_str('Grupo Naval · Portal RTM')
        . ' /Producer ' . naval_pdf_str('Portal RTM') . ' /CreationDate (D:' . date('YmdHis') . ') >>';
    ksort($obj);
    $out = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
    $pos = [];
    foreach ($obj as $n => $o) {
        $pos[$n] = strlen($out);
        $out .= naval_pdf_escrever_obj($n, $o);
    }
    $xref = strlen($out);
    $max = max(array_keys($obj));
    $out .= "xref\n0 " . ($max + 1) . "\n0000000000 65535 f \n";
    for ($i = 1; $i <= $max; $i++) $out .= isset($pos[$i]) ? sprintf("%010d 00000 n \n", $pos[$i]) : "0000000000 65535 f \n";
    $out .= "trailer\n<< /Size " . ($max + 1) . " /Root 1 0 R /Info $info 0 R >>\nstartxref\n$xref\n%%EOF\n";
    return $out;
}

/**
 * Acrescenta páginas a um PDF gerado por naval_pdf_gerar, por actualização incremental (ISO 32000-1, 7.5.6):
 * os bytes do original ficam intactos no início do ficheiro novo, pelo que o SHA-256 do original continua
 * verificável a partir do documento assinado.
 */
function naval_pdf_acrescentar(string $original, array $p): string
{
    if (!preg_match_all('/startxref\s+(\d+)/', $original, $sx) || !preg_match_all('/\/Size (\d+)/', $original, $sz)
        || !preg_match_all('/\/Type \/Pages \/Kids \[([^\]]*)\] \/Count (\d+)/', $original, $pg) ) {
        throw new NavalErro('O PDF original não tem a estrutura esperada.', 500);
    }
    $prev = (int) end($sx[1]);
    $prox = (int) end($sz[1]);
    $kidsAntes = trim((string) end($pg[1]));
    $nAntes = (int) end($pg[2]);
    $inicio = $prox;
    // as páginas do original mantêm a numeração própria (os bytes não mudam); as acrescentadas numeram-se à parte
    list($obj, $kids) = naval_pdf_objectos($p, $prox, 1, count($p['paginas']), 'Página de assinaturas');
    $info = $prox++;
    $obj[$info] = '<< /Title ' . naval_pdf_str((string) ($p['meta']['titulo'] ?? 'Documento')) . ' /Author ' . naval_pdf_str('Grupo Naval · Portal RTM')
        . ' /Producer ' . naval_pdf_str('Portal RTM') . ' /ModDate (D:' . date('YmdHis') . ') >>';
    $out = $original;
    if (substr($out, -1) !== "\n") $out .= "\n";
    $pos = [];
    $pos[2] = strlen($out);
    $out .= naval_pdf_escrever_obj(2, '<< /Type /Pages /Kids [' . $kidsAntes . ' ' . implode(' ', $kids) . '] /Count ' . ($nAntes + count($kids)) . ' >>');
    foreach ($obj as $n => $o) {
        $pos[$n] = strlen($out);
        $out .= naval_pdf_escrever_obj($n, $o);
    }
    $xref = strlen($out);
    $out .= "xref\n2 1\n" . sprintf("%010d 00000 n \n", $pos[2]) . $inicio . ' ' . ($prox - $inicio) . "\n";
    for ($i = $inicio; $i < $prox; $i++) $out .= sprintf("%010d 00000 n \n", $pos[$i]);
    $out .= "trailer\n<< /Size " . $prox . ' /Root 1 0 R /Info ' . $info . " 0 R /Prev $prev >>\nstartxref\n$xref\n%%EOF\n";
    return $out;
}
