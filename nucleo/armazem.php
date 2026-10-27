<?php
/**
 * Armazenamento em ficheiros JSON (pasta privada): leitura tolerante e escrita atómica,
 * para que um pedido nunca leia um ficheiro a meio de ser gravado.
 */
defined('NAVAL_NUCLEO') || exit;

/** Lê um ficheiro JSON; se não existir ou estiver danificado, devolve a omissão. */
function naval_json_ler(string $f, array $omissao = []): array
{
    if (!is_file($f)) return $omissao;
    $d = json_decode((string) file_get_contents($f), true);
    return is_array($d) ? $d : $omissao;
}

/** Grava um ficheiro JSON: escreve numa cópia temporária e troca-a de uma vez. */
function naval_json_gravar(string $f, array $d, bool $legivel = true): void
{
    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | ($legivel ? JSON_PRETTY_PRINT : 0);
    naval_ficheiro_gravar($f, (string) json_encode($d, $flags));
}

/** Grava bytes num ficheiro de forma atómica (cria a pasta se for preciso). */
function naval_ficheiro_gravar(string $f, string $conteudo): void
{
    $dir = dirname($f);
    if (!is_dir($dir)) @mkdir($dir, 0770, true);
    $tmp = $f . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (file_put_contents($tmp, $conteudo, LOCK_EX) === false) {
        throw new RuntimeException('Não foi possível gravar ' . basename($f) . '.');
    }
    if (!@rename($tmp, $f)) {
        // Windows: se outro processo tiver o ficheiro aberto, grava directamente
        @unlink($tmp);
        if (file_put_contents($f, $conteudo, LOCK_EX) === false) throw new RuntimeException('Não foi possível gravar ' . basename($f) . '.');
    }
}

/** Acrescenta uma linha JSON a um ficheiro (registos). */
function naval_jsonl_acrescentar(string $f, array $linha): void
{
    $dir = dirname($f);
    if (!is_dir($dir)) @mkdir($dir, 0770, true);
    @file_put_contents($f, json_encode($linha, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
}

/**
 * Executa $f em exclusivo (bloqueio de ficheiro com flock na pasta privada): serializa as sequências
 * ler → alterar → gravar dos ficheiros partilhados (contas, bloqueios, limites), para que dois pedidos
 * em simultâneo não percam alterações um do outro (ASVS 11.1.6). Reentrante no mesmo pedido.
 *
 * @template T
 * @param callable(): T $f
 * @return T
 */
function naval_exclusivo(string $nome, callable $f)
{
    static $abertos = [];
    $nome = (string) preg_replace('/[^a-z0-9_-]/i', '', $nome);
    if (isset($abertos[$nome])) return $f();   // já temos o bloqueio neste pedido
    $dir = naval_pasta_privada() . '/bloqueios-ficheiros';
    if (!is_dir($dir)) @mkdir($dir, 0770, true);
    $h = @fopen($dir . '/' . $nome . '.lock', 'c');
    if (!$h || !flock($h, LOCK_EX)) throw new NavalErro('O servidor está ocupado. Tente de novo.', 503);
    $abertos[$nome] = true;
    try {
        return $f();
    } finally {
        unset($abertos[$nome]);
        flock($h, LOCK_UN);
        fclose($h);
    }
}
