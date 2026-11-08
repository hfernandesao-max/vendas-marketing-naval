<?php
/**
 * Registos de actividade: uma linha JSON por acção, um ficheiro por mês, na pasta privada.
 *
 * Registos invioláveis (ISO/IEC 27001, controlo 8.15; ISO/IEC 27002): cada linha leva o resumo SHA-256 da linha
 * anterior («ha») e o seu próprio («h»), calculado sobre o texto exacto gravado. Alterar, apagar ou trocar a ordem de
 * uma linha parte a cadeia, e o último resumo fica também em registos/cadeia.json (o corte do fim nota-se). A tarefa
 * diária da conformidade verifica a cadeia toda, guarda um selo do dia e copia os ficheiros para fora do servidor
 * (REGISTOS_COPIA_PASTA). Ver nucleo/servicos/conformidade.php.
 */
defined('NAVAL_NUCLEO') || exit;
require_once __DIR__ . '/geoip.php';

const NAVAL_REGISTOS_INICIO = '0000000000000000000000000000000000000000000000000000000000000000';

function naval_registos_pasta(): string
{
    return naval_pasta_privada() . '/registos';
}

function naval_registos_cadeia_ficheiro(): string
{
    return naval_registos_pasta() . '/cadeia.json';
}

/** Registo de actividade (JSON por linha, um ficheiro por mês, fora da pasta pública), encadeado por SHA-256. */
function naval_registar(string $accao, array $detalhe = [], ?string $login = null): void
{
    $u = naval_utilizador();
    $linha = [
        'data' => date('Y-m-d H:i:s'),
        'utilizador' => $login ?? ($u['login'] ?? ($_SESSION['usuario_login'] ?? '')),
        'perfil' => $u['perfil'] ?? '',
        'ip' => function_exists('naval_ip_cliente') ? naval_ip_cliente() : (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
        'accao' => $accao,
        'detalhe' => $detalhe,
    ];
    // Localização aproximada do IP (base de dados local, ver geoip.php), gravada no momento: fica certa
    // mesmo que o IP mude de dono depois.
    $loc = naval_geoip($linha['ip']);
    if ($loc) $linha['local'] = $loc;
    $f = naval_registos_pasta() . '/acessos-' . date('Y-m') . '.jsonl';
    try {
        naval_exclusivo('registos-cadeia', function () use ($f, $linha) {
            $c = naval_json_ler(naval_registos_cadeia_ficheiro(), ['ultimo' => NAVAL_REGISTOS_INICIO, 'n' => 0]);
            $corpo = (string) json_encode($linha + ['ha' => (string) $c['ultimo']], JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
            if ($corpo === '') return;
            $h = hash('sha256', $corpo);
            $dir = dirname($f);
            if (!is_dir($dir)) @mkdir($dir, 0770, true);
            if (@file_put_contents($f, substr($corpo, 0, -1) . ',"h":"' . $h . '"}' . "\n", FILE_APPEND | LOCK_EX) === false) return;
            naval_json_gravar(naval_registos_cadeia_ficheiro(), ['ultimo' => $h, 'n' => (int) $c['n'] + 1, 'em' => $linha['data'], 'ficheiro' => basename($f)]);
        });
    } catch (Throwable $e) {
        // o registo nunca impede a acção: sem bloqueio, grava sem encadear e a verificação diária assinala-o
        naval_jsonl_acrescentar($f, $linha + ['sem_cadeia' => true]);
    }
}

/**
 * Verifica a cadeia de todos os ficheiros de registos, por ordem. Devolve ['ok', 'linhas', 'encadeadas', 'ultimo',
 * 'falhas' => [[ficheiro, linha, motivo]]]. As linhas anteriores à cadeia (sem «h») contam-se mas não se verificam.
 */
function naval_registos_verificar(?string $pasta = null, ?string $cadeia = null): array
{
    $pasta = $pasta ?? naval_registos_pasta();
    $fich = glob($pasta . '/acessos-*.jsonl') ?: [];
    sort($fich);
    $ant = null;
    $n = 0;
    $enc = 0;
    $falhas = [];
    foreach ($fich as $f) {
        $num = 0;
        foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $l) {
            $num++;
            $n++;
            if (!preg_match('/^(.*),"h":"([0-9a-f]{64})"\}$/s', $l, $m)) {
                if ($ant !== null) $falhas[] = [basename($f), $num, strpos($l, '"sem_cadeia":true') !== false ? 'linha gravada sem cadeia' : 'linha sem resumo depois do início da cadeia'];
                continue;
            }
            $corpo = $m[1] . '}';
            $j = json_decode($corpo, true);
            if (!is_array($j) || !hash_equals($m[2], hash('sha256', $corpo))) {
                $falhas[] = [basename($f), $num, 'conteúdo alterado'];
            } elseif ($ant !== null && !hash_equals($ant, (string) ($j['ha'] ?? ''))) {
                $falhas[] = [basename($f), $num, 'linha anterior apagada ou fora de ordem'];
            }
            $ant = $m[2];
            $enc++;
        }
    }
    $c = naval_json_ler($cadeia ?? naval_registos_cadeia_ficheiro(), []);
    if ($ant !== null && isset($c['ultimo']) && !hash_equals((string) $c['ultimo'], $ant)) $falhas[] = ['cadeia.json', 0, 'faltam as últimas linhas dos registos'];
    return ['ok' => !$falhas, 'linhas' => $n, 'encadeadas' => $enc, 'ultimo' => (string) $ant, 'falhas' => array_slice($falhas, 0, 50)];
}

function naval_ler_registos(int $max = 300): array
{
    $dir = naval_registos_pasta();
    $fich = glob($dir . '/acessos-*.jsonl') ?: [];
    rsort($fich);
    $out = [];
    foreach ($fich as $f) {
        $linhas = file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        for ($i = count($linhas) - 1; $i >= 0 && count($out) < $max; $i--) {
            $j = json_decode($linhas[$i], true);
            if (!$j) continue;
            unset($j['h'], $j['ha'], $j['sem_cadeia']);
            if (!isset($j['local']) && !empty($j['ip'])) {   // registos anteriores à localização
                $loc = naval_geoip((string) $j['ip']);
                if ($loc) $j['local'] = $loc + ['posterior' => true];
            }
            $out[] = $j;
        }
        if (count($out) >= $max) break;
    }
    return $out;
}
