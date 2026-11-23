<?php
/**
 * Configurar a ligação ao MDM Plus (uma vez, no servidor, na pasta do portal):
 *
 *     php ferramentas/configurar-mdm.php
 *
 * Pede o Client ID e o Client Secret do Self Client (consola https://api-console.zoho.com) e o código gerado em
 * «Generate Code» (válido poucos minutos) ou, em alternativa, um refresh token já obtido. Troca o código pelo refresh
 * token na Zoho, grava as três chaves MDM_OAUTH_* no .env (com cópia de segurança do .env anterior) e corre a
 * verificação (ferramentas/verificar-mdm.php). Nunca mostra os valores secretos no ecrã.
 *
 * Sem perguntas (ex.: por script): variáveis de ambiente MDM_CONFIG_CLIENT_ID, MDM_CONFIG_CLIENT_SECRET e
 * MDM_CONFIG_CODIGO (ou MDM_CONFIG_REFRESH_TOKEN).
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . '/nucleo/arranque.php';
naval_servico('mdm');

/** Lê uma linha do teclado (ou da variável de ambiente indicada). */
function mdm_pergunta(string $texto, string $variavel): string
{
    $v = (string) getenv($variavel);
    if ($v !== '') return trim($v);
    echo $texto . ': ';
    return trim((string) fgets(STDIN));
}

echo "MDM Plus: configurar a ligação (Self Client da Zoho)\n\n";
$id = mdm_pergunta('Client ID', 'MDM_CONFIG_CLIENT_ID');
$segredo = mdm_pergunta('Client Secret', 'MDM_CONFIG_CLIENT_SECRET');
$refresh = (string) getenv('MDM_CONFIG_REFRESH_TOKEN');
$codigo = $refresh === '' ? mdm_pergunta('Código gerado em «Generate Code» (ou deixe vazio para colar um refresh token)', 'MDM_CONFIG_CODIGO') : '';
if ($refresh === '' && $codigo === '') $refresh = mdm_pergunta('Refresh token', 'MDM_CONFIG_REFRESH_TOKEN');
if ($id === '' || $segredo === '' || ($codigo === '' && $refresh === '')) {
    fwrite(STDERR, "Faltam dados: Client ID, Client Secret e o código (ou o refresh token).\n");
    exit(1);
}

if ($refresh === '') {
    $base = rtrim(naval_config('MDM_ACCOUNTS_BASE', 'https://accounts.zoho.com'), '/');
    try {
        list($http, $corpo) = naval_mdm_http('POST', $base . '/oauth/v2/token', ['Content-Type: application/x-www-form-urlencoded'],
            http_build_query(['code' => $codigo, 'client_id' => $id, 'client_secret' => $segredo, 'grant_type' => 'authorization_code']));
    } catch (Throwable $e) {
        fwrite(STDERR, '  FALHA  ' . $e->getMessage() . "\n");
        exit(1);
    }
    $j = json_decode($corpo, true);
    if ($http !== 200 || empty($j['refresh_token'])) {
        $erro = is_array($j) && isset($j['error']) ? (string) $j['error'] : 'resposta inesperada';
        fwrite(STDERR, '  FALHA  A Zoho recusou o código (HTTP ' . $http . ', ' . $erro . ').' . "\n");
        if ($erro === 'invalid_code') fwrite(STDERR, "         O código expirou ou já foi usado: gere outro em «Generate Code» e corra de novo.\n");
        if ($erro === 'invalid_client') fwrite(STDERR, "         Client ID ou Client Secret errados.\n");
        exit(1);
    }
    $refresh = (string) $j['refresh_token'];
    echo "  ok     código trocado pelo refresh token\n";
}

try {
    $copia = naval_env_gravar(['MDM_OAUTH_CLIENT_ID' => $id, 'MDM_OAUTH_CLIENT_SECRET' => $segredo, 'MDM_OAUTH_REFRESH_TOKEN' => $refresh], 'mdm');
    @unlink(naval_mdm_ficheiro_token());   // token de acesso antigo, de outras credenciais
    echo "  ok     chaves MDM_OAUTH_* gravadas no .env (cópia do anterior: " . $copia . ")\n\n";
} catch (Throwable $e) {
    fwrite(STDERR, '  FALHA  ' . $e->getMessage() . "\n");
    exit(1);
}

// verificação com o .env novo, num processo próprio (o .env já foi lido por este)
// nosemgrep: exec-use -- linha de comandos do administrador: argumentos com escapeshellarg ou validados
passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/verificar-mdm.php'), $codigoSaida);
exit((int) $codigoSaida);
