<?php
/**
 * Presença dos telemóveis na sala de resultados (LCD de 75") e quiosque temporário do Winrest pelo MDM.
 * A app «Naval Sala» (ferramentas/android-sala) detecta a entrada pela rede Wi-Fi da sala e avisa aqui
 * (POST v1/sala/presenca, chave SALA_APP_CHAVE). Ver docs/SALA-SINCRONIZACAO.md.
 *
 *   - Regista cada evento (entrada, saída) por dia e mantém quem está na sala agora, para o painel do LCD.
 *   - Modo «kiosk» (SALA_MODO=kiosk e a app nesse modo): associa ao equipamento o perfil de quiosque de uma só app no
 *     Winrest (SALA_MDM_PERFIL_KIOSK) pela API do ManageEngine MDM e retira-o sozinho ao fim de SALA_KIOSK_MINUTOS (3)
 *     ou à saída. O caminho da API de associação a um equipamento (SALA_MDM_CAMINHO_PERFIS) tem de ser confirmado na
 *     documentação da API do tenant antes de ligar o modo; o token precisa do escopo de escrita (MDMDeviceMgmt.UPDATE).
 */
defined('NAVAL_NUCLEO') || exit;

const NAVAL_SALA_EVENTOS = ['entrada', 'entrada_sem_disparo', 'saida', 'sem_permissao_sobreposicao'];

function naval_sala_pasta(): string
{
    return naval_pasta('sala/presenca');
}

/** Quem está na sala agora: {app: {modelo, desde, ultimo, dispositivo_mdm, kiosk_ate}}. */
function naval_sala_presentes(): array
{
    return naval_json_ler(naval_sala_pasta() . '/agora.json') ?: [];
}

/** Valida e regista um evento da app; no modo kiosk, pede ou retira o quiosque. Devolve o estado do equipamento. */
function naval_sala_presenca(array $p, ?int $agora = null): array
{
    $agora = $agora ?? time();
    $evento = (string) ($p['evento'] ?? '');
    if (!in_array($evento, NAVAL_SALA_EVENTOS, true)) throw new NavalErro('Evento desconhecido.', 400);
    $app = (string) ($p['app'] ?? '');
    if (!preg_match('/^[A-Za-z0-9]{6,64}$/', $app)) throw new NavalErro('Identificação da app inválida.', 400);
    $mdm = (string) ($p['dispositivo_mdm'] ?? '');
    if ($mdm !== '' && !preg_match('/^[0-9]{1,30}$/', $mdm)) throw new NavalErro('ID do equipamento no MDM inválido.', 400);
    $modelo = mb_substr(naval_texto((string) ($p['modelo'] ?? ''), 60), 0, 60);
    $kioskPedido = ($p['modo'] ?? '') === 'kiosk' && naval_config('SALA_MODO', 'app') === 'kiosk' && $mdm !== '';
    $quando = date('Y-m-d H:i:s', $agora);
    naval_jsonl_acrescentar(naval_sala_pasta() . '/' . date('Y-m-d', $agora) . '.jsonl', ['em' => $quando, 'evento' => $evento, 'app' => $app, 'modelo' => $modelo,
        'dispositivo_mdm' => $mdm, 'wifi_sala' => ($p['wifi_sala'] ?? '') === 'true', 'winrest_aberto' => ($p['winrest_aberto'] ?? '') === 'true']);
    return naval_exclusivo('sala-presenca', function () use ($evento, $app, $mdm, $modelo, $kioskPedido, $quando, $agora) {
        $f = naval_sala_pasta() . '/agora.json';
        $todos = naval_json_ler($f) ?: [];
        $x = $todos[$app] ?? ['modelo' => $modelo, 'desde' => $quando, 'dispositivo_mdm' => $mdm, 'kiosk_ate' => null];
        $x['ultimo'] = $quando;
        $x['modelo'] = $modelo;
        if ($evento === 'saida') {
            if (!empty($x['kiosk_ate']) && $mdm !== '') naval_sala_kiosk($mdm, false);
            unset($todos[$app]);
            naval_json_gravar($f, $todos);
            return ['na_sala' => false];
        }
        if ($evento === 'entrada' && $kioskPedido) {
            try {
                naval_sala_kiosk($mdm, true);
                $x['kiosk_ate'] = date('Y-m-d H:i:s', $agora + 60 * max(1, (int) naval_config('SALA_KIOSK_MINUTOS', '3')));
            } catch (Throwable $e) {
                $x['kiosk_erro'] = mb_substr($e->getMessage(), 0, 200);
                naval_registar('sala_kiosk_falhou', ['dispositivo' => $mdm, 'erro' => $x['kiosk_erro']]);
            }
        }
        $todos[$app] = $x;
        naval_json_gravar($f, $todos);
        return ['na_sala' => true, 'kiosk_ate' => $x['kiosk_ate'] ?? null];
    });
}

/**
 * Associa (ligar) ou retira o perfil de quiosque do Winrest a um equipamento no MDM. O caminho vem de
 * SALA_MDM_CAMINHO_PERFIS (por omissão /devices/{id}/profiles, a confirmar no tenant), com {"profile_ids": [...]}.
 */
function naval_sala_kiosk(string $dispositivo, bool $ligar): void
{
    naval_servico('mdm');
    $perfil = naval_config('SALA_MDM_PERFIL_KIOSK');
    if (!preg_match('/^[0-9]{1,30}$/', $perfil)) throw new NavalErro('SALA_MDM_PERFIL_KIOSK por configurar (ID do perfil de quiosque do Winrest no MDM).', 503);
    $caminho = str_replace('{id}', rawurlencode($dispositivo), naval_config('SALA_MDM_CAMINHO_PERFIS', '/devices/{id}/profiles'));
    $url = rtrim(naval_config('MDM_API_BASE', 'https://mdm.manageengine.com'), '/') . '/api/v1/mdm' . $caminho;
    $corpo = json_encode(['profile_ids' => [(int) $perfil]]);
    for ($i = 0; $i < 2; $i++) {
        [$codigo] = naval_mdm_http($ligar ? 'POST' : 'DELETE', $url, ['Content-Type: application/json', 'Accept: application/json',
            'Authorization: Zoho-oauthtoken ' . naval_mdm_token($i > 0)], $corpo);
        if ($codigo === 401 && $i === 0) continue;
        if ($codigo >= 400) throw new RuntimeException('A API do MDM respondeu HTTP ' . $codigo . ' ao ' . ($ligar ? 'associar' : 'retirar') . ' o quiosque.');
        naval_registar($ligar ? 'sala_kiosk_ligado' : 'sala_kiosk_retirado', ['dispositivo' => $dispositivo]);
        return;
    }
    throw new RuntimeException('A API do MDM recusou a autenticação.');
}

/** Rotina periódica: retira os quiosques que passaram o prazo e esquece quem não dá sinal há mais de 2 horas. */
function naval_sala_tick(?int $agora = null): int
{
    $agora = $agora ?? time();
    $f = naval_sala_pasta() . '/agora.json';
    if (!is_file($f)) return 0;
    return naval_exclusivo('sala-presenca', function () use ($f, $agora) {
        $todos = naval_json_ler($f) ?: [];
        $n = 0;
        foreach ($todos as $app => $x) {
            if (!empty($x['kiosk_ate']) && strtotime((string) $x['kiosk_ate']) <= $agora) {
                try {
                    naval_sala_kiosk((string) $x['dispositivo_mdm'], false);
                    $todos[$app]['kiosk_ate'] = null;
                    $n++;
                } catch (Throwable $e) {
                    error_log('[sala] retirar quiosque: ' . $e->getMessage());
                }
            }
            if (strtotime((string) ($x['ultimo'] ?? '2000-01-01')) < $agora - 7200 && empty($todos[$app]['kiosk_ate'])) unset($todos[$app]);
        }
        naval_json_gravar($f, $todos);
        return $n;
    });
}
