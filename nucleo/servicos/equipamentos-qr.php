<?php
/**
 * Equipamentos — página pública do código QR das etiquetas (https://id.naval.co.ao/a/NAV-SMT-0001 → equipamentos/ficha.php).
 *
 *  - Contacto de devolução: gravado no portal (Equipamentos › Página pública do código QR, «Equipamentos – Administrar»),
 *    com as chaves ATV_CONTACTO_DEVOLUCAO_TEL / _EMAIL do .env como valores por omissão.
 *  - Tipo do equipamento: deduzido do próprio ID (NAV-SMT → smartphone, NAV-IMP → impressora móvel), sem consultar a
 *    base de dados — a página nunca revela se o ID existe nem nenhum dado interno.
 *  - Leitura sem sessão: fica no registo de actividade; se o equipamento estiver dado como perdido ou roubado, regista
 *    também o evento LEITURA_QR na ficha e avisa quem administra os equipamentos (com a localização aproximada do IP).
 *    A resposta ao leitor é sempre a mesma, exista ou não o equipamento.
 */
defined('NAVAL_NUCLEO') || exit;

const NAVAL_ATV_QR_TIPOS = ['SMT' => ['Smartphone', 'Smartphone'], 'IMP' => ['Impressora móvel', 'Mobile printer']];

function naval_atv_qr_ficheiro(): string
{
    return naval_pasta('equipamentos') . '/contacto-devolucao.json';
}

/** Contacto de devolução mostrado na página pública: {tel, email, origem: portal|env|vazio}. */
function naval_atv_contacto(): array
{
    $g = naval_json_ler(naval_atv_qr_ficheiro());
    if (isset($g['tel']) || isset($g['email'])) {
        return ['tel' => (string) ($g['tel'] ?? ''), 'email' => (string) ($g['email'] ?? ''), 'origem' => 'portal',
            'alterado_por' => (string) ($g['alterado_por'] ?? ''), 'alterado_em' => (string) ($g['alterado_em'] ?? '')];
    }
    $tel = naval_config('ATV_CONTACTO_DEVOLUCAO_TEL');
    $email = naval_config('ATV_CONTACTO_DEVOLUCAO_EMAIL');
    return ['tel' => $tel, 'email' => $email, 'origem' => $tel !== '' || $email !== '' ? 'env' : 'vazio', 'alterado_por' => '', 'alterado_em' => ''];
}

/** Grava o contacto de devolução (telefone internacional e/ou endereço de correio electrónico válidos). */
function naval_atv_gravar_contacto(array $d, string $login): array
{
    $tel = trim(preg_replace('/\s+/', ' ', (string) ($d['tel'] ?? '')));
    $email = strtolower(trim((string) ($d['email'] ?? '')));
    if ($tel !== '' && !preg_match('/^\+?[0-9][0-9 ()-]{5,22}$/', $tel)) {
        throw new NavalErro('Telefone inválido (ex.: +244 923 000 000).', 400, ['campo' => 'tel']);
    }
    if ($email !== '' && (strlen($email) > 120 || filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
        throw new NavalErro('Endereço de correio electrónico inválido.', 400, ['campo' => 'email']);
    }
    if ($tel === '' && $email === '') throw new NavalErro('Indique pelo menos um contacto: telefone ou correio electrónico.', 400);
    $antes = naval_atv_contacto();
    $novo = ['tel' => $tel, 'email' => $email, 'alterado_por' => $login, 'alterado_em' => date('Y-m-d H:i:s')];
    naval_exclusivo('atv-contacto', function () use ($novo) { naval_json_gravar(naval_atv_qr_ficheiro(), $novo); });
    naval_registar('equipamentos_contacto', ['antes' => ['tel' => $antes['tel'], 'email' => $antes['email']], 'depois' => ['tel' => $tel, 'email' => $email]]);
    return naval_atv_contacto();
}

/** ID lido do código QR, em maiúsculas; '' se não tiver o formato NAV-SMT-0001. */
function naval_atv_qr_id(string $id): string
{
    $id = strtoupper(trim($id));
    return preg_match('/^[A-Z]{3}-[A-Z]{3}-\d{4}$/', $id) ? $id : '';
}

/** Nome do tipo [pt, en] a partir do ID (NAV-SMT-0001 → Smartphone); genérico para outros tipos. */
function naval_atv_qr_tipo(string $id): array
{
    return NAVAL_ATV_QR_TIPOS[substr($id, 4, 3)] ?? ['Equipamento', 'Device'];
}

/**
 * Leitura do código QR sem sessão: registo de actividade e, se o equipamento estiver dado como perdido ou roubado,
 * evento na ficha e aviso a quem administra. Devolve o que fez (para os testes); nunca lança excepções.
 */
function naval_atv_qr_leitura_publica(string $id, ?int $agora = null): array
{
    $agora = $agora ?? time();
    $r = ['registada' => false, 'perdido' => false, 'aviso' => null];
    try {
        naval_registar('equipamentos_qr_publico', ['id' => $id]);
        $r['registada'] = true;
        naval_servico('equipamentos');
        if (!naval_atv_configurada()) return $r;
        $e = naval_atv_q('SELECT Estado FROM ATV_ACTIVO WHERE IdActivo = :id', [':id' => $id]);
        if (!$e || ($e[0]['Estado'] ?? '') !== 'PERDIDO_ROUBADO') return $r;
        $r['perdido'] = true;
        $ip = function_exists('naval_ip_cliente') ? naval_ip_cliente() : (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $loc = function_exists('naval_geoip') ? naval_geoip($ip) : [];
        $local = isset($loc['rede']) ? 'rede interna' : trim(implode(', ', array_filter([(string) ($loc['cidade'] ?? ''), (string) ($loc['pais_nome'] ?? ($loc['pais'] ?? ''))])));
        if ($local === '') $local = 'desconhecido';
        naval_atv_evento_inserir($id, 'LEITURA_QR', ['Observacoes' => mb_substr('Leitura pública (sem sessão) de equipamento dado como perdido ou roubado · IP ' . $ip . ' · ' . $local, 0, 1000)]);
        $h = date('H:i', $agora);
        naval_servico('notificacoes');
        $txt = naval_notif_txt('perdido_lido', ['id' => $id, 'h' => $h, 'l' => $local]);
        // uma vez por hora e por equipamento (as leituras seguidas não repetem o aviso)
        $r['aviso'] = naval_notificar('equipamento_lido', $txt['pt'][0], $txt['pt'][1],
            ['chave' => 'qr|' . $id . '|' . date('YmdH', $agora), 'textos' => $txt, 'url' => '../reports/#equipamentos?id=' . rawurlencode($id)], $agora);
    } catch (Throwable $e) {
        error_log('[equipamentos-qr] ' . $e->getMessage());
    }
    return $r;
}
