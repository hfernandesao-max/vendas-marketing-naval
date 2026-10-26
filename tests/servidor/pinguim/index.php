<?php
/**
 * Pinguim BO de teste: exportdata com três documentos de 24-10-2026. O primeiro é o exemplo do modelo
 * RTM_Sales_SAP_Upload_Format (rota 1011, duas linhas, 461 400 Kz em numerário).
 */
if (($_SERVER['PHP_AUTH_USER'] ?? '') !== 'u' || ($_SERVER['PHP_AUTH_PW'] ?? '') !== 'p') {
    http_response_code(401);
    exit;
}
header('Content-Type: application/xml');
function linha($cod, $desc, $q, $net, $tax)
{
    return "<line><product_code>$cod</product_code><product_description>$desc</product_description><uom>CAR</uom><quantity>$q</quantity>"
        . "<base_amount>0</base_amount><total>$net</total><discount_amount>0</discount_amount><tax_amount>$tax</tax_amount></line>";
}
function documento($tipo, $nr, $rota, $ent, $nif, $tot, $data, array $pags, $linhas)
{
    $f = '';
    foreach ($pags as $k => $v) $f .= "<line><payment_code>$k</payment_code><amount>$v</amount></line>";
    return "<transaction><document_type>$tipo</document_type><deleted>0</deleted><store_code>$rota</store_code><entity_code>$ent</entity_code>"
        . "<total>$tot</total><transaction_date>$data</transaction_date><transaction_time>09:15:00</transaction_time><document_nr>$nr</document_nr>"
        . "<tax_number>$nif</tax_number><footer>$f</footer><detail>$linhas</detail></transaction>";
}
echo '<?xml version="1.0" encoding="UTF-8"?><export>'
    . '<payment_methods><payment_method><code>MC</code><description>Multicaixa</description></payment_method></payment_methods>'
    . '<entities><entity><code>0000000007</code><description>Yonitk- Comércio Geral e prestação de serviços </description><tax_number>5417000000</tax_number></entity>'
    . '<entity><code>X2</code><description>Loja B</description><tax_number>5000000002</tax_number></entity></entities><transactions>'
    . documento('FR', 'FR0126S78515N/1', '1011', '0000000007', '5417000000', 461400, '2026-10-24', ['NU' => 461400], linha('10000009', 'Fraldas', 30, 394736.84, 55263.16) . linha('10000010', 'Sabão', 20, 10000, 1400))
    . documento('FT', 'FT0126S78516N/1', '1002', 'X2', '5000000002', 114000, '2026-10-24', ['MC' => 50000, 'TB' => 20000], linha('10000009', 'Fraldas', 10, 100000, 14000))
    . documento('FR', 'FR0126S78517N/1', '1001', 'X2', '5000000002', 60000, '2026-10-24', ['NU' => 30000, 'MC' => 30000], linha('10000010', 'Sabão', 5, 40000, 5600))
    . '</transactions></export>';
