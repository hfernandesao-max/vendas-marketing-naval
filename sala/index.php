<?php
/**
 * Painel da sala comercial (ecrã de TV): página pública, sem conta nem palavra-passe; os dados só chegam com o código
 * da ligação (fragmento #t=…, enviado à API no cabeçalho X-Painel-Codigo; ver nucleo/api/v1/sala.php).
 *
 * O .htaccess da raiz recusa os index.html (as páginas são servidas pelos seus controladores PHP): esta página também
 * é servida por aqui, com a versão de cada ficheiro no endereço (as actualizações chegam logo aos ecrãs ligados).
 */
declare(strict_types=1);

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache');
header('X-Robots-Tag: noindex, nofollow');
$html = (string) file_get_contents(__DIR__ . '/index.html');
foreach (['sala.css', 'sala.js'] as $f) {
    $v = substr(md5((string) @filemtime(__DIR__ . '/' . $f) . '|' . (string) @filesize(__DIR__ . '/' . $f)), 0, 10);
    $html = str_replace('"' . $f . '"', '"' . $f . '?v=' . $v . '"', $html);
}
echo $html;
