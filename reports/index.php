<?php
declare(strict_types=1);

require_once __DIR__ . '/../geo/auth/auth_check.php';

// Só entra quem tem pelo menos uma página do portal no seu perfil
$paginas = ['inicio', 'vendas-promotor', 'vendas-dia', 'vendas-cliente', 'ranking', 'documentos', 'pagamentos', 'auditoria', 'registo-cargas', 'stock-carrinha',
    'stock-armazem', 'vendas-artigo', 'vendas-marca', 'cadastros', 'cadastros-analise', 'cadastros-clientes', 'clientes', 'plano-visitas', 'sincronizacao', 'plano-cargas', 'sap-exportar', 'pendentes', 'acessos'];
$algum = false;
foreach ($paginas as $pg) {
    if (naval_pode($pg)) {
        $algum = true;
        break;
    }
}
if (!$algum) naval_pagina_exigir('inicio', '../');

header('Content-Type: text/html; charset=utf-8');
readfile(__DIR__ . '/index.html');
