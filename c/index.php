<?php
/**
 * Cartaz QR do cliente: página pública de encomendas e actualização de dados (código ?k=…, validado pela API;
 * ver nucleo/servicos/clientes-qr.php).
 *
 * O .htaccess da raiz recusa os index.html (as páginas são servidas pelos seus controladores PHP): esta página também
 * é servida por aqui, com a versão de cada ficheiro no endereço (as actualizações chegam logo aos ecrãs ligados).
 */
declare(strict_types=1);

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache');
header('X-Robots-Tag: noindex, nofollow');
$html = (string) file_get_contents(__DIR__ . '/index.html');
foreach (['c.css', 'c.js'] as $f) {
    $v = substr(md5((string) @filemtime(__DIR__ . '/' . $f) . '|' . (string) @filesize(__DIR__ . '/' . $f)), 0, 10);
    $html = str_replace('"' . $f . '"', '"' . $f . '?v=' . $v . '"', $html);
}
echo $html;
