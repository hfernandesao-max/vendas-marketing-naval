<?php
/**
 * Stock de artigos RTM (carrinhas e armazéns) → JSON para as páginas de stock (ver nucleo/servicos/stock.php).
 *
 * GET v1/stock?data=AAAA-MM-DD&armazem=…   (ou start_date/end_date AAAAMMDD: posição no fim do período)
 * Fonte: SQL Server do Pinguim BO (dbo.GET_STOCK, só SELECT).
 */
defined('NAVAL_API') || exit;   // só pela API central (api/index.php): token JWT já validado
naval_servico('bd');
naval_servico('stock');
naval_servico('rotas');
naval_exigir(['stock-carrinha', 'stock-armazem', 'inicio']);
@set_time_limit(180);

// POST {accao: confirmar_carrinha, rota, codigo, nome, csrf}: armazém da carrinha de uma rota (SADM e TI)
if (naval_e_post()) {
    $p = naval_pedido();
    naval_exigir_csrf($p);
    if (!in_array(naval_perfil(), ['SADM', 'TI'], true)) throw new NavalErro('Só o super administrador e a informática confirmam o armazém de cada carrinha.', 403);
    if ((string) ($p['accao'] ?? '') !== 'confirmar_carrinha') throw new NavalErro('Acção desconhecida.', 400);
    $eu = (array) naval_utilizador();
    $c = naval_stock_confirmar_carrinha((string) ($p['rota'] ?? ''), (string) ($p['codigo'] ?? ''), (string) ($p['nome'] ?? ''), (string) ($eu['login'] ?? ''));
    naval_json(['ok' => true, 'confirmadas' => $c]);
}

$data = naval_bd_data((string) ($_GET['data'] ?? $_GET['end_date'] ?? $_GET['start_date'] ?? date('Ymd')));
$armazem = trim((string) ($_GET['armazem'] ?? $_GET['rota'] ?? ''));
$d = naval_stock($data, $armazem);
if ($d === null) throw new NavalErro('Dados de stock não disponíveis.', 404, ['detalhe' => 'Sem ligação ao SQL Server e sem ficheiro de stock disponível.']);
naval_json(naval_stock_ambito($d, naval_rotas(), naval_pode('stock-armazem')));
