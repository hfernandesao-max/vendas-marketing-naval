<?php
// API do MDM Plus e OAuth da Zoho simulados, para os testes de ferramentas/verificar-mdm.php (php -S … mdm-simulado.php).
$c = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
header('Content-Type: application/json');
if ($c === '/oauth/v2/token' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $ok = ($_POST['grant_type'] ?? '') === 'refresh_token' && ($_POST['refresh_token'] ?? '') === 'rt-teste' && ($_POST['client_id'] ?? '') === 'cliente-teste' && ($_POST['client_secret'] ?? '') !== '';
    echo json_encode($ok ? ['access_token' => 'at-novo', 'api_domain' => 'https://www.zohoapis.com', 'token_type' => 'Bearer', 'expires_in' => 3600] : ['error' => 'invalid_code']);
    exit;
}
if ($c === '/api/v1/mdm/devices') {
    if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Zoho-oauthtoken at-novo') {
        http_response_code(401);
        echo json_encode(['error_code' => 'COM0013', 'error_description' => 'Unauthorized']);
        exit;
    }
    $todos = [
        ['device_id' => '236708000000175195', 'device_name' => '1001 - Andrea Santos', 'imei' => ['353326512163561', '353326512163579'], 'serial_number' => 'BV6200ProNEU0031328', 'platform_type_id' => 2],
        ['device_id' => '236708000000175300', 'device_name' => '1002 - Cristina Sachuma', 'imei' => ['353326512164429'], 'serial_number' => 'XYZ', 'platform_type_id' => 2],
        ['device_id' => '236708000000175999', 'device_name' => 'Outro', 'imei' => ['111111111111111'], 'serial_number' => 'OUTRO', 'platform_type_id' => 2],
    ];
    $limite = max(1, (int) ($_GET['limit'] ?? 50));
    $offset = (int) ($_GET['offset'] ?? 0);
    $resposta = json_encode(['devices' => array_slice($todos, $offset, $limite), 'total_record_count' => count($todos)]);
    echo $resposta;
    exit;
}
http_response_code(404);
echo '{}';
