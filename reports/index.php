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
// Pré-carregamento do que a pessoa vai usar logo: a página principal (se a vê) e o dicionário do idioma da conta
$extra = [];
if (naval_pode('inicio')) $extra[] = 'js/paginas/inicio.js';
$idioma = naval_idioma_conta(naval_utilizador());
if (in_array($idioma, ['en', 'ti'], true)) $extra[] = 'js/i18n/' . $idioma . '.js';
echo naval_portal_versionar((string) $html, __DIR__, $extra);

/**
 * Velocidade: cada ficheiro do portal (módulos JS e estilos) leva a sua versão no endereço (?v=…, da data e do
 * tamanho do ficheiro), e o navegador guarda-o sem voltar a perguntar ao servidor (cache «immutable» no .htaccess).
 * Depois de uma actualização, os ficheiros alterados têm uma versão nova e descarregam-se logo; os outros não.
 *  - módulos ES: um «import map» liga cada endereço ao endereço com a versão (os import entre módulos ficam iguais,
 *    e vale também para os módulos carregados a pedido: páginas e dicionários dos idiomas);
 *  - «modulepreload» só do arranque (os módulos que main.js importa, directa ou indirectamente) e de $extra (com
 *    as suas dependências): o navegador pede-os em paralelo, sem esperar pela cadeia de import; as outras páginas e
 *    os outros idiomas descarregam-se só quando se abrem.
 * Navegadores sem import map (iOS anterior a 16.4) recebem os módulos sem versão, que o .htaccess serve sem cache
 * longa: nunca ficam com código antigo.
 */
function naval_portal_versionar(string $html, string $raiz, array $extra = []): string
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
    foreach ($modulos as $rel => $v) $mapa['./' . $rel] = './' . $rel . '?v=' . $v;
    $pre = '';
    foreach (naval_portal_arranque($raiz, array_merge(['js/main.js'], $extra)) as $rel) {
        if ($rel !== 'js/main.js' && isset($modulos[$rel])) $pre .= '  <link rel="modulepreload" href="' . $rel . '?v=' . $modulos[$rel] . '">' . "\n";
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

/**
 * Módulos do arranque: os de $inicio e todos os que eles importam estaticamente (import … from / import '…' /
 * export … from), por ordem de descoberta. Os import() dinâmicos (páginas e idiomas a pedido) não entram.
 */
function naval_portal_arranque(string $raiz, array $inicio): array
{
    $vistos = [];
    $fila = $inicio;
    while ($fila) {
        $rel = array_shift($fila);
        if (isset($vistos[$rel]) || !is_file($raiz . '/' . $rel)) continue;
        $vistos[$rel] = true;
        $codigo = (string) file_get_contents($raiz . '/' . $rel);
        preg_match_all('/^\s*(?:import|export)\s+(?:[\w*${}\s,]+?\s+from\s+)?[\'"](\.{1,2}\/[^\'"]+\.js)[\'"]/m', $codigo, $m);
        foreach ($m[1] as $dep) {
            $partes = [];
            foreach (explode('/', dirname($rel) . '/' . $dep) as $p) {
                if ($p === '' || $p === '.') continue;
                if ($p === '..') array_pop($partes);
                else $partes[] = $p;
            }
            $fila[] = implode('/', $partes);
        }
    }
    return array_keys($vistos);
}
