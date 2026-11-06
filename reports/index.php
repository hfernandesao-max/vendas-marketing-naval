<?php
declare(strict_types=1);

require_once __DIR__ . '/../geo/auth/auth_check.php';
require_once __DIR__ . '/../nucleo/versoes.php';

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
$meta = '<meta name="csrf-token" content="' . htmlspecialchars(naval_csrf(), ENT_QUOTES, 'UTF-8') . '">' . naval_meta_token();
$html = preg_replace('/<head>/i', '<head>' . "\n  " . $meta, (string) file_get_contents(__DIR__ . '/index.html'), 1);
// Pré-carregamento do que a pessoa vai usar logo: a página principal (se a vê) e o dicionário do idioma da conta
$extra = [];
if (naval_pode('inicio')) $extra[] = 'js/paginas/inicio.js';
$idioma = naval_idioma_conta(naval_utilizador());
if (in_array($idioma, ['en', 'ti'], true)) $extra[] = 'js/i18n/' . $idioma . '.js';
echo naval_portal_versionar((string) $html, __DIR__, $extra);
