<?php
/** Registos de actividade: uma linha JSON por acção, um ficheiro por mês, na pasta privada. */
defined('NAVAL_NUCLEO') || exit;
require_once __DIR__ . '/geoip.php';

/** Registo de actividade (JSON por linha, um ficheiro por mês, fora da pasta pública). */
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
    naval_jsonl_acrescentar(naval_pasta_privada() . '/registos/acessos-' . date('Y-m') . '.jsonl', $linha);
}

function naval_ler_registos(int $max = 300): array
{
    $dir = naval_pasta_privada() . '/registos';
    $fich = glob($dir . '/acessos-*.jsonl') ?: [];
    rsort($fich);
    $out = [];
    foreach ($fich as $f) {
        $linhas = file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        for ($i = count($linhas) - 1; $i >= 0 && count($out) < $max; $i--) {
            $j = json_decode($linhas[$i], true);
            if (!$j) continue;
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
