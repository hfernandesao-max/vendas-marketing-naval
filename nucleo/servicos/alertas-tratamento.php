<?php
/**
 * Tratamento dos alertas (gestão de alarmes ISA-18.2 / IEC 62682 e gestão de eventos ITIL 4, como no ServiceNow, no
 * PagerDuty e no Microsoft Sentinel). Os alertas calculam-se de novo a cada pedido (alertas.php) e têm um
 * identificador estável; aqui guarda-se o que as pessoas fizeram com cada um:
 *
 *   - assumir     «em tratamento por X»: continua visível para todos (ninguém faz o trabalho a dobrar);
 *   - resolver    com a nota da acção tomada (obrigatória); sai da lista activa. Se o problema continuar mais de
 *                 24 horas depois, o alerta reabre sozinho («continua depois de resolvido»);
 *   - silenciar   até uma data (no máximo 30 dias), com o motivo (obrigatório); volta sozinho quando a data acaba;
 *   - reabrir     desfaz a resolução ou o silêncio, com o motivo.
 *
 * Cada passo fica registado (quem, quando, nota) no histórico do alerta e no registo de actividade; nada se apaga
 * (os registos de alertas que já não existem saem ao fim de 90 dias). Ficheiro: dados-privados/alertas/tratamento.json.
 */
defined('NAVAL_NUCLEO') || exit;

const NAVAL_ALT_ACCOES = ['assumir', 'resolver', 'silenciar', 'reabrir', 'largar'];
const NAVAL_ALT_SILENCIO_MAX = 30;      // dias
const NAVAL_ALT_REABRE_HORAS = 24;      // resolvido mas o problema continua: reabre ao fim de 24 horas
const NAVAL_ALT_GUARDAR_DIAS = 90;

function naval_alt_ficheiro(): string
{
    return naval_pasta('alertas') . '/tratamento.json';
}

/** {id: registo} */
function naval_alt_ler(): array
{
    return (array) naval_json_ler(naval_alt_ficheiro(), []);
}

/**
 * Junta o tratamento a cada alerta e diz se está activo: 'tratamento' => {estado, por, em, nota, ate, hist}. Estados
 * efectivos: novo, assumido, resolvido, silenciado, reaberto. Activos (na lista principal e nas contagens): novo,
 * assumido e reaberto.
 */
function naval_alt_aplicar(array $alertas, int $agora, ?array $reg = null): array
{
    $reg = $reg ?? naval_alt_ler();
    $hoje = date('Y-m-d', $agora);
    foreach ($alertas as $i => $a) {
        $r = $reg[(string) ($a['id'] ?? '')] ?? null;
        $e = 'novo';
        if ($r) {
            $e = (string) $r['estado'];
            if ($e === 'silenciado' && (string) ($r['ate'] ?? '') < $hoje) $e = 'novo';          // o silêncio acabou
            if ($e === 'resolvido' && $agora - (int) strtotime((string) $r['em']) > NAVAL_ALT_REABRE_HORAS * 3600) $e = 'reaberto';
        }
        $alertas[$i]['tratamento'] = ['estado' => $e] + ($r ? array_intersect_key($r, ['por' => 1, 'em' => 1, 'nota' => 1, 'ate' => 1, 'hist' => 1]) : []);
        $alertas[$i]['activo'] = in_array($e, ['novo', 'assumido', 'reaberto'], true);
    }
    return $alertas;
}

/** Regista um passo no tratamento de um alerta. Devolve o registo actualizado. */
function naval_alt_tratar(string $id, string $accao, string $nota, ?string $ate, string $login, int $agora): array
{
    if (!preg_match('/^[\w|.:\-]{3,160}$/u', $id)) throw new NavalErro('Alerta inválido.', 400);
    if (!in_array($accao, NAVAL_ALT_ACCOES, true)) throw new NavalErro('Acção inválida.', 400);
    $nota = naval_texto($nota, 500);
    if (in_array($accao, ['resolver', 'silenciar', 'reabrir'], true) && mb_strlen($nota) < 5) {
        throw new NavalErro($accao === 'resolver' ? 'Diga o que foi feito para resolver (pelo menos 5 caracteres).'
            : ($accao === 'silenciar' ? 'Diga porque silencia este alerta (pelo menos 5 caracteres).' : 'Diga porque reabre este alerta.'), 400);
    }
    $hoje = date('Y-m-d', $agora);
    if ($accao === 'silenciar') {
        $max = date('Y-m-d', $agora + NAVAL_ALT_SILENCIO_MAX * 86400);
        if (!$ate || !naval_data_valida($ate) || $ate < $hoje || $ate > $max) {
            throw new NavalErro('Escolha até quando fica silenciado (de hoje até ' . naval_data_pt($max) . ').', 400);
        }
    }
    return naval_exclusivo('alertas-tratamento', function () use ($id, $accao, $nota, $ate, $login, $agora) {
        $reg = naval_alt_ler();
        $em = date('Y-m-d H:i:s', $agora);
        $r = $reg[$id] ?? ['hist' => []];
        $estado = ['assumir' => 'assumido', 'resolver' => 'resolvido', 'silenciar' => 'silenciado', 'reabrir' => 'novo', 'largar' => 'novo'][$accao];
        $r['estado'] = $estado;
        $r['por'] = $login;
        $r['em'] = $em;
        $r['nota'] = $nota;
        $r['ate'] = $accao === 'silenciar' ? $ate : null;
        $r['hist'][] = array_filter(['accao' => $accao, 'por' => $login, 'em' => $em, 'nota' => $nota, 'ate' => $accao === 'silenciar' ? $ate : null], function ($v) { return $v !== null && $v !== ''; });
        $r['hist'] = array_slice($r['hist'], -50);
        $reg[$id] = $r;
        // limpeza: registos sem movimento há mais de 90 dias
        $limite = date('Y-m-d H:i:s', $agora - NAVAL_ALT_GUARDAR_DIAS * 86400);
        foreach ($reg as $k => $x) if ((string) ($x['em'] ?? '') < $limite) unset($reg[$k]);
        naval_json_gravar(naval_alt_ficheiro(), $reg);
        naval_registar('alerta_' . $accao, ['alerta' => $id, 'nota' => $nota, 'ate' => $ate]);
        return $r;
    });
}
