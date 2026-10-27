<?php
/**
 * Envio de email da plataforma pelo Microsoft 365 (Microsoft Graph, permissão de aplicação Mail.Send).
 *
 * O SMTP com palavra-passe (autenticação básica) foi retirado pela Microsoft em 2026; o Graph com uma
 * aplicação registada é o método suportado. Configuração no .env (fora do GitHub):
 *   EMAIL_TENANT_ID=...        identificador do inquilino (Entra ID → Visão geral)
 *   EMAIL_CLIENT_ID=...        identificador da aplicação registada
 *   EMAIL_CLIENT_SECRET=...    segredo da aplicação
 *   EMAIL_REMETENTE=noreply@naval.co.ao   caixa de correio que envia
 * Para testes sem enviar: EMAIL_MODO=ficheiro grava as mensagens em dados-privados/email-saida/.
 */
defined('NAVAL_NUCLEO') || exit;
require_once dirname(__DIR__) . '/cliente-http.php';

function naval_email_config(): array
{
    return [
        'modo' => strtolower(naval_config('EMAIL_MODO', 'graph')),
        'tenant' => naval_config('EMAIL_TENANT_ID'),
        'cliente' => naval_config('EMAIL_CLIENT_ID'),
        'segredo' => naval_config('EMAIL_CLIENT_SECRET'),
        'remetente' => naval_config('EMAIL_REMETENTE'),
    ];
}

function naval_email_configurado(): bool
{
    $c = naval_email_config();
    if ($c['modo'] === 'ficheiro') return true;
    return $c['tenant'] !== '' && $c['cliente'] !== '' && $c['segredo'] !== '' && naval_email_valido($c['remetente']);
}

/** Pedido HTTP (cliente comum do núcleo): devolve [código, corpo, erro]. */
function naval_http(string $metodo, string $url, array $cabecalhos, string $corpo): array
{
    $r = naval_http_pedido($metodo, $url, ['cabecalhos' => $cabecalhos, 'corpo' => $corpo, 'tempo' => 30, 'ligacao' => 10]);
    return [$r['codigo'], (string) $r['corpo'], $r['errno'] !== 0 ? $r['erro'] : ''];
}

/** Token de acesso do Graph (guardado até expirar). */
function naval_email_token(): array
{
    $c = naval_email_config();
    $f = naval_pasta_privada() . '/email-token.json';
    $t = is_file($f) ? json_decode((string) file_get_contents($f), true) : null;
    if (is_array($t) && ($t['expira'] ?? 0) > time() + 60 && ($t['cliente'] ?? '') === $c['cliente']) return [$t['token'], ''];
    [$codigo, $r, $erro] = naval_http('POST', 'https://login.microsoftonline.com/' . rawurlencode($c['tenant']) . '/oauth2/v2.0/token',
        ['Content-Type: application/x-www-form-urlencoded'],
        http_build_query(['client_id' => $c['cliente'], 'client_secret' => $c['segredo'], 'scope' => 'https://graph.microsoft.com/.default', 'grant_type' => 'client_credentials']));
    $j = json_decode($r, true);
    if ($codigo !== 200 || empty($j['access_token'])) {
        return [null, 'Microsoft 365 recusou a autenticação da aplicação' . ($erro ? ': ' . $erro : (isset($j['error_description']) ? ': ' . strtok($j['error_description'], "\r\n") : ' (código ' . $codigo . ')'))];
    }
    naval_json_gravar($f, ['token' => $j['access_token'], 'expira' => time() + (int) ($j['expires_in'] ?? 3000), 'cliente' => $c['cliente']], false);
    return [$j['access_token'], ''];
}

/**
 * Envia um email. $para: lista de ['email'=>, 'nome'=>]. $anexos: lista de ['nome'=>, 'tipo'=>, 'conteudo'=> bytes].
 * Devolve ['ok' => bool, 'erro' => string].
 */
function naval_enviar_email(array $para, string $assunto, string $html, array $anexos = []): array
{
    $c = naval_email_config();
    $dest = [];
    // Domínios autorizados (EMAIL_DOMINIOS_PERMITIDOS no .env, separados por vírgula): impede o envio pela
    // caixa da empresa para endereços externos não previstos. Vazio = qualquer domínio.
    $dominios = array_filter(array_map(function ($x) { return strtolower(trim($x)); }, explode(',', naval_config('EMAIL_DOMINIOS_PERMITIDOS'))));
    $recusados = [];
    foreach ($para as $p) {
        if (!naval_email_valido($p['email'] ?? null)) continue;
        $e = trim((string) $p['email']);
        if ($dominios && !in_array(strtolower((string) substr(strrchr($e, '@'), 1)), $dominios, true)) {
            $recusados[] = $e;
            continue;
        }
        $dest[] = ['emailAddress' => ['address' => $e, 'name' => str_replace(["\r", "\n"], ' ', (string) ($p['nome'] ?? ''))]];
    }
    if ($recusados) naval_registar('email_destinatario_recusado', ['enderecos' => $recusados]);
    if (!$dest) return ['ok' => false, 'erro' => $recusados ? 'Os destinatários não são de domínios autorizados para envio (' . implode(', ', $dominios) . ').' : 'Não há destinatários com email válido.'];
    if (!naval_email_configurado()) return ['ok' => false, 'erro' => 'O envio de email ainda não está configurado no servidor (.env: EMAIL_TENANT_ID, EMAIL_CLIENT_ID, EMAIL_CLIENT_SECRET, EMAIL_REMETENTE).'];

    $msg = [
        'subject' => $assunto,
        'body' => ['contentType' => 'HTML', 'content' => $html],
        'toRecipients' => $dest,
        'attachments' => array_map(function ($a) {
            return ['@odata.type' => '#microsoft.graph.fileAttachment', 'name' => $a['nome'], 'contentType' => $a['tipo'], 'contentBytes' => base64_encode($a['conteudo'])];
        }, $anexos),
    ];

    if ($c['modo'] === 'ficheiro') {
        $dir = naval_pasta('email-saida');
        $base = $dir . '/' . date('Ymd-His') . '-' . substr(hash('sha256', $assunto . microtime()), 0, 6);
        file_put_contents($base . '.html', $html);
        foreach ($anexos as $a) file_put_contents($base . '-' . preg_replace('/[^\w.-]+/', '_', $a['nome']), $a['conteudo']);
        file_put_contents($base . '.json', json_encode(['assunto' => $assunto, 'para' => $dest, 'anexos' => array_column($anexos, 'nome')], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        return ['ok' => true, 'erro' => ''];
    }

    [$token, $erro] = naval_email_token();
    if (!$token) return ['ok' => false, 'erro' => $erro];
    [$codigo, $r, $erroHttp] = naval_http('POST', 'https://graph.microsoft.com/v1.0/users/' . rawurlencode($c['remetente']) . '/sendMail',
        ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
        json_encode(['message' => $msg, 'saveToSentItems' => true], JSON_UNESCAPED_UNICODE));
    if ($codigo === 202) return ['ok' => true, 'erro' => ''];
    $j = json_decode($r, true);
    $det = $erroHttp ?: ($j['error']['message'] ?? ('código ' . $codigo));
    if ($codigo === 401) @unlink(naval_pasta_privada() . '/email-token.json');
    return ['ok' => false, 'erro' => 'O Microsoft 365 não aceitou o envio: ' . $det];
}
