<?php
/**
 * Configurar o assistente IA (uma vez, no servidor, na pasta do portal):
 *
 *     php ferramentas/configurar-ia.php
 *
 * Pede a chave da API da Anthropic (consola https://console.anthropic.com › API Keys, conta comercial da empresa),
 * confirma-a com um pedido de teste e grava IA_API_KEY no .env (com cópia de segurança do .env anterior).
 * Nunca mostra a chave no ecrã. Sem perguntas (ex.: por script): variável de ambiente IA_CONFIG_CHAVE.
 * Opções: --modelo=<modelo> (omissão: claude-sonnet-5-5), --limite=<tokens por mês> (omissão: 5 000 000).
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . '/nucleo/arranque.php';
require_once dirname(__DIR__) . '/nucleo/cliente-http.php';

$opcoes = [];
foreach (array_slice($argv, 1) as $a) if (preg_match('/^--([a-z]+)=(.+)$/', $a, $m)) $opcoes[$m[1]] = $m[2];

echo "Assistente IA: configurar a ligação à API da Anthropic\n\n";
$chave = trim((string) getenv('IA_CONFIG_CHAVE'));
if ($chave === '') {
    echo 'Chave da API (não aparece no ecrã): ';
    if (DIRECTORY_SEPARATOR === '/') @system('stty -echo');
    $chave = trim((string) fgets(STDIN));
    if (DIRECTORY_SEPARATOR === '/') @system('stty echo');
    echo "\n";
}
if (!preg_match('/^[A-Za-z0-9_\-]{20,300}$/', $chave)) {
    fwrite(STDERR, "  FALHA  chave com formato inválido.\n");
    exit(1);
}
$modelo = (string) ($opcoes['modelo'] ?? naval_config('IA_MODELO', 'claude-sonnet-5-5'));
if (!preg_match('/^[a-z0-9.\-]{3,80}$/', $modelo)) {
    fwrite(STDERR, "  FALHA  modelo inválido.\n");
    exit(1);
}
$limite = isset($opcoes['limite']) ? (string) max(0, (int) $opcoes['limite']) : null;

// pedido de teste (poucos tokens) antes de gravar
$url = rtrim(naval_config('IA_API_URL', 'https://api.anthropic.com'), '/') . '/v1/messages';
$r = naval_http_pedido('POST', $url, ['cabecalhos' => ['Content-Type: application/json', 'x-api-key: ' . $chave, 'anthropic-version: 2023-06-01'],
    'corpo' => json_encode(['model' => $modelo, 'max_tokens' => 5, 'messages' => [['role' => 'user', 'content' => 'Responde só: ok']]]), 'tempo' => 40]);
if ($r['corpo'] === false || $r['errno']) {
    fwrite(STDERR, '  FALHA  sem ligação a ' . parse_url($url, PHP_URL_HOST) . ' (' . $r['erro'] . "). Pedir à TI a saída HTTPS para api.anthropic.com.\n");
    exit(1);
}
if ($r['codigo'] !== 200) {
    $j = json_decode((string) $r['corpo'], true);
    fwrite(STDERR, '  FALHA  a API recusou (HTTP ' . $r['codigo'] . ': ' . (is_array($j) ? (string) ($j['error']['message'] ?? '') : '') . ")\n");
    if ($r['codigo'] === 401) fwrite(STDERR, "         Chave errada ou revogada: criar outra na consola da Anthropic.\n");
    if ($r['codigo'] === 404) fwrite(STDERR, "         Modelo desconhecido: indicar outro com --modelo=.\n");
    exit(1);
}
echo "  ok     chave e modelo aceites pela API ($modelo)\n";
$chaves = ['IA_API_KEY' => $chave, 'IA_MODELO' => $modelo];
if ($limite !== null) $chaves['IA_LIMITE_TOKENS_MES'] = $limite;
try {
    $copia = naval_env_gravar($chaves, 'ia');
    echo "  ok     chaves gravadas no .env (cópia do anterior: $copia)\n";
    echo "  ok     assistente IA a funcionar\n";
} catch (Throwable $e) {
    fwrite(STDERR, '  FALHA  ' . $e->getMessage() . "\n");
    exit(1);
}
