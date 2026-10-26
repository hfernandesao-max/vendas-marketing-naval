<?php
/** Registos de actividade: uma linha JSON por acção, um ficheiro por mês, na pasta privada. */
defined('NAVAL_NUCLEO') || exit;

/** Registo de actividade (JSON por linha, um ficheiro por mês, fora da pasta pública). */
function naval_registar(string $accao, array $detalhe = [], ?string $login = null): void
{
    $u = naval_utilizador();
    $linha = [
        'data' => date('Y-m-d H:i:s'),
        'utilizador' => $login ?? ($u['login'] ?? ($_SESSION['usuario_login'] ?? '')),
        'perfil' => $u['perfil'] ?? '',
        'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
        'accao' => $accao,
        'detalhe' => $detalhe,
    ];
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
            if ($j) $out[] = $j;
        }
        if (count($out) >= $max) break;
    }
    return $out;
}
