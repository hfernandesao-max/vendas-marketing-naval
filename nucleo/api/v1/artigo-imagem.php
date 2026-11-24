<?php
/**
 * Imagem de um artigo, como está no Pinguim BO (PRODUCT.IMAGE_ID → BLOBBANK.BLOBDATA, só SELECT), para os pedidos e
 * o inventário na app. Guardada no servidor 1 dia (pasta privada) para não ler a base de dados a cada pedido.
 * Recursos: quem pede mercadoria ou conta («requisicoes-carrinha» ou «conciliacao-carrinha»).
 *
 * GET v1/artigo-imagem?codigo=10000332     image/jpeg (ou png/gif/webp); 404 sem imagem
 */
defined('NAVAL_API') || exit;
naval_exigir(['requisicoes-carrinha', 'conciliacao-carrinha']);
naval_servico('artigo-imagem');
$codigo = (string) ($_GET['codigo'] ?? '');
if (!preg_match('/^[A-Za-z0-9._-]{1,40}$/', $codigo)) throw new NavalErro('Código do artigo inválido.', 400);
$img = naval_artigo_imagem($codigo);
if ($img === null) throw new NavalErro('Este artigo não tem imagem no Pinguim BO.', 404);
header('Content-Type: ' . $img['tipo']);
header('Cache-Control: private, max-age=86400');
header('X-Content-Type-Options: nosniff');
header('Content-Length: ' . strlen($img['dados']));
echo $img['dados'];
exit;
