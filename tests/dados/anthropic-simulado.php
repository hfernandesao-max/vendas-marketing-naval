<?php
// API de mensagens da Anthropic simulada, para os testes do assistente (php -S 127.0.0.1:8802 anthropic-simulado.php).
// Com ferramentas: 1.º pedido → pede «vendas_resumo» de 24-10-2026 por rota; 2.º pedido (com o resultado) → responde com o
// total que a ferramenta devolveu. Sem ferramentas (resumo diário) → um parágrafo fixo. Verifica a chave e a versão.
header('Content-Type: application/json');
if (($_SERVER['HTTP_X_API_KEY'] ?? '') !== 'chave-ia-de-teste' || ($_SERVER['HTTP_ANTHROPIC_VERSION'] ?? '') !== '2023-06-01') {
    http_response_code(401);
    echo '{"type":"error","error":{"type":"authentication_error","message":"invalid x-api-key"}}';
    return;
}
$q = json_decode((string) file_get_contents('php://input'), true) ?: [];
$uso = ['input_tokens' => 120, 'output_tokens' => 40];
$texto = function (string $t) use ($uso) { echo json_encode(['id' => 'msg_t', 'type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'text', 'text' => $t]], 'stop_reason' => 'end_turn', 'usage' => $uso]); };
if (empty($q['tools'])) {
    $texto('Parágrafo de teste escrito pelo assistente.');
    return;
}
$ultima = end($q['messages']);
if (is_array($ultima['content'])) {
    $res = json_decode((string) ($ultima['content'][0]['content'] ?? ''), true) ?: [];
    $texto('O total de 24-10-2026 foi de **' . number_format((float) ($res['total'] ?? -1), 0, ',', ' ') . ' Kz**.' . "\n- Rotas: " . count($res['linhas'] ?? []));
    return;
}
$nomes = array_column($q['tools'], 'name');
echo json_encode(['id' => 'msg_t', 'type' => 'message', 'role' => 'assistant', 'stop_reason' => 'tool_use', 'usage' => $uso, 'content' => [
    ['type' => 'text', 'text' => 'Vou consultar as vendas.'],
    ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => in_array('vendas_resumo', $nomes, true) ? 'vendas_resumo' : 'rotas',
        'input' => ['de' => '2026-10-24', 'ate' => '2026-10-24', 'agrupar' => 'rota']],
]]);
