<?php
/**
 * Token de acesso à API já dentro da página (menos uma ida e volta ao servidor antes do primeiro pedido): o mesmo
 * token de 15 minutos de POST v1/auth/token, emitido para a sessão aberta. A página não fica em cache (no-store) e
 * o api.js tira-o do DOM ao lê-lo. Sem chave configurada, devolve '' e o api.js pede o token como antes.
 */
function naval_meta_token(): string
{
    try {
        require_once __DIR__ . '/seguranca/jwt.php';
        $u = naval_utilizador();
        if (!$u) return '';
        $t = naval_jwt_emitir($u, session_id());
        return '<meta name="api-token" content="' . htmlspecialchars((string) $t['access_token'], ENT_QUOTES, 'UTF-8') . '" data-s="' . (int) $t['expires_in'] . '">';
    } catch (Throwable $e) {
        return '';
    }
}

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
function naval_portal_versionar(string $html, string $raiz, array $extra = [], string $prefixo = './', string $inicio = 'js/main.js'): string
{
    $versao = function (string $rel) use ($raiz) {
        $f = $raiz . '/' . $rel;
        // nosemgrep: weak-crypto -- resumo não criptográfico (identificador/versão), sem uso de segurança
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
    foreach ($modulos as $rel => $v) $mapa[$prefixo . $rel] = $prefixo . $rel . '?v=' . $v;
    $pre = '';
    foreach (naval_portal_arranque($raiz, array_merge([$inicio], $extra)) as $rel) {
        if ($rel !== $inicio && isset($modulos[$rel])) $pre .= '  <link rel="modulepreload" href="' . ($prefixo === './' ? '' : $prefixo) . $rel . '?v=' . $modulos[$rel] . '">' . "\n";
    }
    $imp = '<script type="importmap">' . json_encode(['imports' => $mapa], JSON_UNESCAPED_SLASHES) . '</script>' . "\n";
    // estilos e o script do tema: versão no endereço
    $p = $prefixo === './' ? '' : preg_quote($prefixo, '/');
    $html = (string) preg_replace_callback('/(href|src)="' . $p . '((?:css|js)\/[a-z0-9_.-]+\.(?:css|js))"/i', function ($m) use ($versao, $prefixo) {
        $v = $versao($m[2]);
        return $v === '' ? $m[0] : $m[1] . '="' . ($prefixo === './' ? '' : $prefixo) . $m[2] . '?v=' . $v . '"';
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
