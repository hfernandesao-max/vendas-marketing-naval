<?php
declare(strict_types=1);

require_once __DIR__ . '/../geo/auth/auth_check.php';

// Só entra quem tem pelo menos uma página do portal no seu perfil
$paginas = ['inicio', 'vendas-promotor', 'vendas-dia', 'vendas-cliente', 'ranking', 'documentos', 'pagamentos', 'auditoria', 'registo-cargas', 'stock-carrinha',
    'stock-armazem', 'vendas-artigo', 'vendas-marca', 'cadastros', 'cadastros-analise', 'cadastros-clientes', 'clientes', 'plano-visitas', 'sincronizacao', 'plano-cargas', 'sap-exportar', 'agt', 'pendentes', 'acessos', 'equipamentos'];
$algum = false;
foreach ($paginas as $pg) {
    if (naval_pode($pg)) {
        $algum = true;
        break;
    }
}
if (!$algum) naval_pagina_exigir('inicio', '../');

header('Content-Type: text/html; charset=utf-8');
// Código CSRF da sessão para o cliente da API trocar a sessão por um token de acesso (reports/js/api.js)
$meta = '<meta name="csrf-token" content="' . htmlspecialchars(naval_csrf(), ENT_QUOTES, 'UTF-8') . '">';
$html = preg_replace('/<head>/i', '<head>' . "\n  " . $meta, (string) file_get_contents(__DIR__ . '/index.html'), 1);
echo naval_portal_versionar((string) $html, __DIR__);

/**
 * Velocidade: cada ficheiro do portal (módulos JS e estilos) leva a sua versão no endereço (?v=…, da data e do
 * tamanho do ficheiro), e o navegador guarda-o sem voltar a perguntar ao servidor (cache «immutable» no .htaccess).
 * Depois de uma actualização, os ficheiros alterados têm uma versão nova e descarregam-se logo; os outros não.
 *  - módulos ES: um «import map» liga cada endereço ao endereço com a versão (os import entre módulos ficam iguais);
 *  - «modulepreload» de todos os módulos: o navegador pede-os em paralelo, sem esperar pela cadeia de import.
 * Navegadores sem import map (iOS anterior a 16.4) recebem os módulos sem versão, que o .htaccess serve sem cache
 * longa: nunca ficam com código antigo.
 */
function naval_portal_versionar(string $html, string $raiz): string
{
    $versao = function (string $rel) use ($raiz) {
        $f = $raiz . '/' . $rel;
        return is_file($f) ? substr(md5(filemtime($f) . '|' . filesize($f)), 0, 10) : '';
    };
    $modulos = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($raiz . '/js', FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (substr($f->getFilename(), -3) !== '.js') continue;
        $rel = 'js/' . str_replace('\\', '/', substr($f->getPathname(), strlen($raiz . '/js/')));
        if ($rel === 'js/tema-arranque.js') continue;   // script clássico, não é módulo
        $modulos[$rel] = $versao($rel);
    }
    ksort($modulos);
    $mapa = [];
    $pre = '';
    foreach ($modulos as $rel => $v) {
        $mapa['./' . $rel] = './' . $rel . '?v=' . $v;
        if ($rel !== 'js/main.js') $pre .= '  <link rel="modulepreload" href="' . $rel . '?v=' . $v . '">' . "\n";
    }
    $imp = '<script type="importmap">' . json_encode(['imports' => $mapa], JSON_UNESCAPED_SLASHES) . '</script>' . "\n";
    // estilos e o script do tema: versão no endereço
    $html = (string) preg_replace_callback('/(href|src)="((?:css|js)\/[a-z0-9_.-]+\.(?:css|js))"/i', function ($m) use ($versao) {
        $v = $versao($m[2]);
        return $v === '' ? $m[0] : $m[1] . '="' . $m[2] . '?v=' . $v . '"';
    }, $html);
    // o import map tem de vir antes de qualquer módulo; o pré-carregamento logo a seguir
    return (string) preg_replace('/<\/head>/i', '  ' . $imp . $pre . '</head>', $html, 1);
}
