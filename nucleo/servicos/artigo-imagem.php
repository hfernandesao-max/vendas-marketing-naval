<?php
/**
 * Imagens dos artigos no Pinguim BO (só SELECT): PRODUCT.IMAGE_ID aponta para BLOBBANK (BLOBDATA, JPEG/PNG).
 * Guardadas 1 dia na pasta privada; só se servem imagens (o tipo vem dos primeiros bytes, nunca do nome).
 */
defined('NAVAL_NUCLEO') || exit;

const NAVAL_ARTIGO_IMAGEM_MAX = 2 * 1024 * 1024;

/** Tipo da imagem pelos primeiros bytes (função pura): image/jpeg, image/png, image/gif, image/webp ou null. */
function naval_artigo_imagem_tipo(string $b): ?string
{
    if (strncmp($b, "\xFF\xD8\xFF", 3) === 0) return 'image/jpeg';
    if (strncmp($b, "\x89PNG\r\n\x1A\n", 8) === 0) return 'image/png';
    if (strncmp($b, 'GIF8', 4) === 0) return 'image/gif';
    if (strncmp($b, 'RIFF', 4) === 0 && substr($b, 8, 4) === 'WEBP') return 'image/webp';
    return null;
}

/** A imagem do artigo {tipo, dados} ou null (sem imagem, sem ligação ou formato desconhecido). */
function naval_artigo_imagem(string $codigo): ?array
{
    $pasta = naval_pasta('artigos-imagens');
    $f = $pasta . '/' . hash('sha256', $codigo) . '.bin';
    $semF = $pasta . '/' . hash('sha256', $codigo) . '.sem';
    if (is_file($f) && filemtime($f) > time() - 86400) {
        $d = (string) file_get_contents($f);
        $t = naval_artigo_imagem_tipo($d);
        if ($t) return ['tipo' => $t, 'dados' => $d];
    }
    if (is_file($semF) && filemtime($semF) > time() - 86400) return null;
    naval_servico('bd');
    if (!naval_bd_configurada()) return null;
    try {
        $r = naval_bd_consultar("SELECT TOP 1 B.BLOBDATA AS D FROM PRODUCT P INNER JOIN BLOBBANK B ON B.ID = P.IMAGE_ID
            WHERE P.DELETED = 0 AND LTRIM(RTRIM(P.CODE)) = :c AND P.IMAGE_ID IS NOT NULL", [':c' => $codigo]);
    } catch (Throwable $e) {
        error_log('[imagem do artigo] ' . $e->getMessage());
        return null;
    }
    $d = $r ? $r[0]['D'] : null;
    if (is_resource($d)) $d = stream_get_contents($d);
    $d = is_string($d) ? $d : '';
    // o controlador ODBC devolve o varbinary em texto hexadecimal («FFD8FF…»): volta aos bytes
    if ($d !== '' && strlen($d) % 2 === 0 && ctype_xdigit(substr($d, 0, 64)) && naval_artigo_imagem_tipo($d) === null) {
        $b = @hex2bin($d);
        if (is_string($b)) $d = $b;
    }
    $t = $d !== '' && strlen($d) <= NAVAL_ARTIGO_IMAGEM_MAX ? naval_artigo_imagem_tipo($d) : null;
    if (!$t) {
        @touch($semF);
        return null;
    }
    @file_put_contents($f, $d, LOCK_EX);
    return ['tipo' => $t, 'dados' => $d];
}

/** Códigos dos artigos com imagem no Pinguim BO (guardado 12 horas): {codigo: true}. */
function naval_artigos_com_imagem(): array
{
    naval_servico('bd');
    if (!naval_bd_configurada()) return [];
    return (array) naval_cache('artigos-com-imagem|' . floor(time() / 43200), 43200, function () {
        try {
            $out = [];
            foreach (naval_bd_consultar("SELECT LTRIM(RTRIM(CODE)) AS C FROM PRODUCT WHERE DELETED = 0 AND IMAGE_ID IS NOT NULL", []) as $r) $out[(string) $r['C']] = true;
            return $out;
        } catch (Throwable $e) {
            error_log('[artigos com imagem] ' . $e->getMessage());
            return [];
        }
    });
}
