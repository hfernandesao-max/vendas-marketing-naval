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
/** Rede que as apps usavam ao entrar: Wi-Fi com Internet confirmada pelo Android, Wi-Fi sem Internet, dados móveis, nenhuma. */
const NAVAL_SALA_REDES = ['wifi', 'wifi_sem_internet', 'dados_moveis', 'sem_rede'];
/** Minutos que se espera pela sincronização depois de entrar antes de a dar como em falta. */
const NAVAL_SALA_ESPERA_SINC = 5;

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
    // rota do telemóvel (configuração gerida da app no MDM) e a rede que as apps estavam a usar ao entrar
    $rota = preg_match('/^\d{3,5}$/', (string) ($p['rota'] ?? '')) ? (string) $p['rota'] : '';
    $rede = in_array($p['rede'] ?? '', NAVAL_SALA_REDES, true) ? (string) $p['rede'] : '';
    $kioskPedido = ($p['modo'] ?? '') === 'kiosk' && naval_config('SALA_MODO', 'app') === 'kiosk' && $mdm !== '';
    $quando = date('Y-m-d H:i:s', $agora);
    naval_jsonl_acrescentar(naval_sala_pasta() . '/' . date('Y-m-d', $agora) . '.jsonl', ['em' => $quando, 'evento' => $evento, 'app' => $app, 'modelo' => $modelo,
        'dispositivo_mdm' => $mdm, 'rota' => $rota, 'rede' => $rede, 'dados_moveis' => ($p['dados_moveis'] ?? '') === 'true',
        'wifi_sala' => ($p['wifi_sala'] ?? '') === 'true', 'winrest_aberto' => ($p['winrest_aberto'] ?? '') === 'true']);
    return naval_exclusivo('sala-presenca', function () use ($evento, $app, $mdm, $modelo, $rota, $kioskPedido, $quando, $agora) {
        $f = naval_sala_pasta() . '/agora.json';
        $todos = naval_json_ler($f) ?: [];
        $x = $todos[$app] ?? ['modelo' => $modelo, 'desde' => $quando, 'dispositivo_mdm' => $mdm, 'kiosk_ate' => null];
        $x['ultimo'] = $quando;
        $x['modelo'] = $modelo;
        if ($rota !== '') $x['rota'] = $rota;
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

/** Eventos de um dia (AAAA-MM-DD) gravados pela app. */
function naval_sala_eventos(string $dia): array
{
    if (!naval_data_valida($dia)) return [];
    $f = naval_sala_pasta() . '/' . $dia . '.jsonl';
    if (!is_file($f)) return [];
    $o = [];
    foreach ((array) file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $l) {
        $x = json_decode((string) $l, true);
        if (is_array($x)) $o[] = $x;
    }
    return $o;
}

/**
 * Sincronizações dos telemóveis num dia, do Pinguim BO (SYNC_PHSE_HISTORY, só SELECT): [rota => [hora de fim, …]] por
 * ordem. É a prova, do próprio Pinguim BO, de que o telemóvel enviou as vendas.
 */
function naval_sala_sincronizacoes(string $dia): array
{
    naval_servico('bd');
    if (!naval_bd_configurada() || !naval_data_valida($dia)) return [];
    $o = [];
    foreach (naval_bd_consultar("
        SELECT LTRIM(RTRIM(S.CODE)) AS ROTA, CONVERT(VARCHAR(19), COALESCE(H.END_TIMESTAMP, H.START_TIMESTAMP), 120) AS EM
        FROM SYNC_PHSE_HISTORY H
        LEFT JOIN STORE S ON S.ID = H.STORE_ID
        WHERE H.START_TIMESTAMP >= :de AND H.START_TIMESTAMP < :ate", [':de' => $dia, ':ate' => date('Y-m-d', strtotime($dia . ' 12:00') + 86400)]) as $x) {
        if (preg_match('/^\d{3,5}$/', (string) $x['ROTA'])) $o[(string) $x['ROTA']][] = (string) $x['EM'];
    }
    foreach ($o as &$l) sort($l);
    unset($l);
    return $o;
}

/**
 * Função pura (testada): quem entrou hoje na sala e se o telemóvel sincronizou depois de entrar, para o painel do LCD.
 * $eventos: naval_sala_eventos do dia; $sincs: naval_sala_sincronizacoes; $promotores: {rota: nome}. Por rota, a
 * última entrada do dia; estado «sincronizou» (há uma sincronização no Pinguim BO depois da entrada), «a_aguardar»
 * (entrou há menos de NAVAL_SALA_ESPERA_SINC minutos) ou «sem_sincronizar». Telemóveis sem rota na configuração
 * ficam em «sem_rota» (não se sabe que sincronização é a sua).
 */
function naval_sala_chegadas(array $eventos, array $sincs, array $promotores, int $agora, int $espera = NAVAL_SALA_ESPERA_SINC): array
{
    $porRota = [];
    $semRota = 0;
    foreach ($eventos as $e) {
        if (($e['evento'] ?? '') !== 'entrada') continue;
        $r = (string) ($e['rota'] ?? '');
        if ($r === '') {
            $semRota++;
            continue;
        }
        if (!isset($porRota[$r]) || (string) $e['em'] > $porRota[$r]['entrada']) {
            $porRota[$r] = ['rota' => $r, 'entrada' => (string) $e['em'], 'rede' => (string) ($e['rede'] ?? ''), 'dados_moveis' => !empty($e['dados_moveis']),
                'winrest_aberto' => !empty($e['winrest_aberto'])];
        }
    }
    $l = [];
    foreach ($porRota as $r => $x) {
        $depois = array_values(array_filter((array) ($sincs[$r] ?? []), function ($t) use ($x) { return $t >= $x['entrada']; }));
        $x['promotor'] = (string) ($promotores[$r] ?? '');
        $x['sincronizou'] = $depois ? $depois[0] : null;
        $x['minutos'] = $depois ? (int) round((strtotime($depois[0]) - strtotime($x['entrada'])) / 60) : null;
        $x['ultima_sinc'] = ($sincs[$r] ?? []) ? end($sincs[$r]) : null;
        $x['estado'] = $depois ? 'sincronizou' : ($agora - strtotime($x['entrada']) < $espera * 60 ? 'a_aguardar' : 'sem_sincronizar');
        $l[] = $x;
    }
    usort($l, function ($a, $b) { return strcmp($b['entrada'], $a['entrada']); });
    return ['linhas' => $l, 'entraram' => count($l), 'sincronizaram' => count(array_filter($l, function ($x) { return $x['estado'] === 'sincronizou'; })),
        'em_falta' => count(array_filter($l, function ($x) { return $x['estado'] === 'sem_sincronizar'; })), 'sem_rota' => $semRota, 'espera' => $espera];
}

/** As chegadas de hoje (para o painel e para o aviso), com as sincronizações lidas do Pinguim BO. */
function naval_sala_chegadas_hoje(int $agora): array
{
    naval_servico('rotas');
    $dia = date('Y-m-d', $agora);
    $ev = naval_sala_eventos($dia);
    $sincs = [];
    if ($ev) {
        try {
            $sincs = naval_sala_sincronizacoes($dia);
        } catch (Throwable $e) {
            error_log('[sala] sincronizações: ' . $e->getMessage());
        }
    }
    return naval_sala_chegadas($ev, $sincs, naval_promotores(), $agora);
}

/**
 * Ciclo (tarefas periódicas, no máximo a cada 2 minutos): avisa quem acompanha a rota quando um telemóvel entrou na
 * sala e não sincronizou em NAVAL_SALA_ESPERA_SINC minutos (notificação «sala_sem_sincronizar», uma vez por entrada).
 */
function naval_sala_ciclo(int $agora): ?array
{
    if (naval_config('SALA_AVISO_SINC', '1') === '0') return null;
    if (!naval_sala_eventos(date('Y-m-d', $agora))) return null;
    $marca = naval_pasta('limites') . '/sala-sinc';
    if (is_file($marca) && filemtime($marca) > $agora - 120) return null;
    @touch($marca);
    $c = naval_sala_chegadas_hoje($agora);
    naval_servico('notificacoes');
    $feitos = [];
    foreach ($c['linhas'] as $x) {
        if ($x['estado'] !== 'sem_sincronizar') continue;
        $rede = ['wifi' => 'Wi-Fi da sala', 'wifi_sem_internet' => 'Wi-Fi sem Internet', 'dados_moveis' => 'dados móveis', 'sem_rede' => 'sem rede'][$x['rede']] ?? '—';
        $tx = naval_notif_txt('sala_sinc', ['r' => $x['rota'], 'h' => substr($x['entrada'], 11, 5), 'm' => (string) NAVAL_SALA_ESPERA_SINC, 'rede' => $rede]);
        $n = naval_notificar('sala_sem_sincronizar', $tx['pt'][0], $tx['pt'][1], ['chave' => 'sala_sinc|' . $x['rota'] . '|' . $x['entrada'], 'rota' => $x['rota'],
            'url' => '../reports/#sincronizacao', 'textos' => $tx], $agora);
        if (empty($n['repetida'])) $feitos[] = $x['rota'];
    }
    if ($feitos) naval_registar('sala_sem_sincronizar', ['rotas' => $feitos], 'sistema');
    return $feitos ?: null;
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
