<?php
/** Funções de texto e datas comuns aos serviços. */
defined('NAVAL_NUCLEO') || exit;

/** Texto de uma linha, sem espaços a mais, cortado a $max caracteres. */
function naval_texto($v, int $max = 80): string
{
    $s = trim((string) preg_replace('/\s+/u', ' ', (string) $v));
    return (string) preg_replace('/^(.{0,' . $max . '}).*$/us', '$1', $s);
}

/** Texto para comparar nomes: maiúsculas, sem acentos, só letras e números. */
function naval_chave_texto(string $s): string
{
    $s = strtr($s, ['á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ú' => 'u', 'ç' => 'c',
        'Á' => 'A', 'À' => 'A', 'Â' => 'A', 'Ã' => 'A', 'É' => 'E', 'Ê' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ú' => 'U', 'Ç' => 'C']);
    return trim((string) preg_replace('/[^A-Z0-9]+/', ' ', strtoupper($s)));
}

/** Data AAAA-MM-DD válida. */
function naval_data_valida($d): bool
{
    return is_string($d) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
}

/** AAAA-MM-DD → DD-MM-AAAA (formato das datas mostradas ao utilizador). */
function naval_data_pt(string $d): string
{
    $p = explode('-', $d);
    return count($p) === 3 ? $p[2] . '-' . $p[1] . '-' . $p[0] : $d;
}

/** Email com formato válido. */
function naval_email_valido($email): bool
{
    return is_string($email) && filter_var(trim($email), FILTER_VALIDATE_EMAIL) !== false;
}
