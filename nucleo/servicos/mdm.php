<?php
/**
 * ManageEngine MDM Plus (cloud): leitura pela API v1 com OAuth 2.0 da Zoho (Self Client, refresh token).
 *
 * Fase 1: SÓ LEITURA (verificar a ligação, listar os equipamentos e cruzá-los com o registo do portal). As ordens
 * ao MDM (Lost Mode, grupos, nome, apagamento) são da fase 2 e não existem neste ficheiro.
 *
 * .env: MDM_API_BASE (omissão https://mdm.manageengine.com), MDM_ACCOUNTS_BASE (omissão https://accounts.zoho.com),
 *       MDM_OAUTH_CLIENT_ID, MDM_OAUTH_CLIENT_SECRET, MDM_OAUTH_REFRESH_TOKEN, MDM_TOKEN_CACHE (facultativo).
 * Documentação: https://www.manageengine.com/mobile-device-management/api/oauth/ e …/api/devices/
 */
defined('NAVAL_NUCLEO') || exit;

const NAVAL_MDM_CHAVES = ['MDM_OAUTH_CLIENT_ID', 'MDM_OAUTH_CLIENT_SECRET', 'MDM_OAUTH_REFRESH_TOKEN'];

function naval_mdm_configurada(): bool
{
    return naval_config_em_falta(NAVAL_MDM_CHAVES) === [];
}

/** Pedido HTTP (curl) → [código, corpo]. */
function naval_mdm_http(string $metodo, string $url, array $cabecalhos = [], ?string $corpo = null): array
{
    if (!function_exists('curl_init')) throw new RuntimeException('A extensão curl do PHP não está activa.');
    $ch = curl_init($url);
    $op = [CURLOPT_CUSTOMREQUEST => $metodo, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_HTTPHEADER => $cabecalhos];
    if ($corpo !== null) $op[CURLOPT_POSTFIELDS] = $corpo;
    curl_setopt_array($ch, $op);
    $r = curl_exec($ch);
    $codigo = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $erro = curl_error($ch);
    curl_close($ch);
    if ($r === false || $codigo === 0) throw new RuntimeException('Sem resposta de ' . (string) parse_url($url, PHP_URL_HOST) . ($erro !== '' ? ': ' . $erro : '') . '.');
    return [$codigo, (string) $r];
}

function naval_mdm_ficheiro_token(): string
{
    return naval_config('MDM_TOKEN_CACHE', naval_pasta('mdm') . '/token.json');
}

/** Access token (válido 1 hora), renovado com o refresh token e guardado na pasta privada até 2 min antes de expirar. */
function naval_mdm_token(bool $forcar = false): string
{
    $f = naval_mdm_ficheiro_token();
    if (!$forcar && is_readable($f)) {
        $c = json_decode((string) file_get_contents($f), true);
        if (is_array($c) && !empty($c['access_token']) && (int) ($c['expira'] ?? 0) > time() + 120) return (string) $c['access_token'];
    }
    naval_exigir_config(NAVAL_MDM_CHAVES, 'a ligação ao MDM Plus');
    $base = rtrim(naval_config('MDM_ACCOUNTS_BASE', 'https://accounts.zoho.com'), '/');
    list($codigo, $corpo) = naval_mdm_http('POST', $base . '/oauth/v2/token', ['Content-Type: application/x-www-form-urlencoded'], http_build_query([
        'refresh_token' => naval_config('MDM_OAUTH_REFRESH_TOKEN'), 'client_id' => naval_config('MDM_OAUTH_CLIENT_ID'),
        'client_secret' => naval_config('MDM_OAUTH_CLIENT_SECRET'), 'grant_type' => 'refresh_token',
    ]));
    $j = json_decode($corpo, true);
    if ($codigo !== 200 || empty($j['access_token'])) {
        // a Zoho responde 200 com {"error":"invalid_code"} quando o refresh token ou o cliente estão errados
        throw new RuntimeException('A Zoho recusou a renovação do token (HTTP ' . $codigo . ', ' . (is_array($j) && isset($j['error']) ? (string) $j['error'] : 'resposta inesperada') . ').');
    }
    @file_put_contents($f, json_encode(['access_token' => $j['access_token'], 'expira' => time() + (int) ($j['expires_in'] ?? 3600)]), LOCK_EX);
    @chmod($f, 0600);
    return (string) $j['access_token'];
}

/** GET na API do MDM (/api/v1/mdm…) → JSON; repete uma vez com um token novo se a resposta for 401. */
function naval_mdm_get(string $caminho, array $query = []): array
{
    $url = rtrim(naval_config('MDM_API_BASE', 'https://mdm.manageengine.com'), '/') . '/api/v1/mdm' . $caminho . ($query ? '?' . http_build_query($query) : '');
    for ($i = 0; $i < 2; $i++) {
        list($codigo, $corpo) = naval_mdm_http('GET', $url, ['Accept: application/json', 'Authorization: Zoho-oauthtoken ' . naval_mdm_token($i > 0)]);
        if ($codigo === 401 && $i === 0) continue;
        if ($codigo >= 400) throw new RuntimeException('A API do MDM respondeu HTTP ' . $codigo . ' a GET ' . $caminho . '.');
        $j = json_decode($corpo, true);
        if (!is_array($j)) throw new RuntimeException('Resposta inesperada da API do MDM a GET ' . $caminho . '.');
        return $j;
    }
    throw new RuntimeException('A API do MDM recusou a autenticação.');
}

/** Todos os equipamentos (paginação limit/offset). Filtros documentados: platform (2 = Android), imei, serial_number, search. */
function naval_mdm_dispositivos(array $filtros = []): array
{
    $todos = [];
    $limite = 200;
    for ($offset = 0; $offset < 20000; $offset += $limite) {
        $pagina = naval_mdm_get('/devices', $filtros + ['limit' => $limite, 'offset' => $offset])['devices'] ?? [];
        $todos = array_merge($todos, $pagina);
        if (count($pagina) < $limite) break;
    }
    return $todos;
}

/**
 * Cruza os equipamentos do MDM com os do portal (ATV_ACTIVO, smartphones): pelo ID do MDM, pelo IMEI (1 ou 2) e pelo
 * n.º de série. Devolve, por equipamento do portal, o equipamento do MDM encontrado (ou null) e se o ID guardado bate.
 */
function naval_mdm_cruzar(array $portal, array $mdm): array
{
    $porId = $porImei = $porSerie = [];
    foreach ($mdm as $d) {
        $id = (string) ($d['device_id'] ?? '');
        if ($id === '') continue;
        $porId[$id] = $d;
        foreach (['imei', 'imei1', 'imei2'] as $k) {
            foreach ((array) ($d[$k] ?? []) as $v) if ((string) $v !== '') $porImei[preg_replace('/\D/', '', (string) $v)] = $d;
        }
        if (!empty($d['serial_number'])) $porSerie[strtoupper((string) $d['serial_number'])] = $d;
    }
    $out = [];
    foreach ($portal as $a) {
        $d = null;
        $como = '';
        if (!empty($a['MdmDeviceId']) && isset($porId[trim((string) $a['MdmDeviceId'])])) {
            $d = $porId[trim((string) $a['MdmDeviceId'])];
            $como = 'ID do MDM';
        }
        foreach (['Imei1', 'Imei2'] as $k) {
            if (!$d && !empty($a[$k]) && isset($porImei[trim((string) $a[$k])])) {
                $d = $porImei[trim((string) $a[$k])];
                $como = 'IMEI';
            }
        }
        if (!$d && !empty($a['NumSerie']) && isset($porSerie[strtoupper(trim((string) $a['NumSerie']))])) {
            $d = $porSerie[strtoupper(trim((string) $a['NumSerie']))];
            $como = 'n.º de série';
        }
        $out[] = [
            'id' => $a['IdActivo'], 'mdm' => $d, 'como' => $como,
            'id_guardado' => trim((string) ($a['MdmDeviceId'] ?? '')), 'id_certo' => $d ? trim((string) ($a['MdmDeviceId'] ?? '')) === (string) $d['device_id'] : null,
        ];
    }
    return $out;
}
