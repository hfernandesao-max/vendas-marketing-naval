<?php
/**
 * Base de dados do Pinguim BO (Microsoft SQL Server, PingWinBO): ligação directa, SÓ DE LEITURA.
 *
 * O portal só lê: todas as consultas passam por naval_bd_consultar(), que recusa qualquer instrução
 * que não seja SELECT (ou WITH … SELECT). Recomendação: um utilizador da base de dados só com a
 * função db_datareader, para a protecção existir também no próprio SQL Server.
 *
 * .env: DB_DRIVER (sqlsrv | odbc | mysql), DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS,
 *       DB_ODBC_DRIVER, DB_TIMEOUT, DB_DSN (DSN completo, se preferir).
 */
defined('NAVAL_NUCLEO') || exit;

/** Há configuração da base de dados no .env? */
function naval_bd_configurada(): bool
{
    return naval_config('DB_HOST') !== '' || naval_config('DB_DSN') !== '';
}

/**
 * Ligação PDO (uma por pedido). Ordem: DSN do .env; sqlsrv nativo; ODBC (driver do .env e, se falhar,
 * o driver «SQL Server» do Windows); MySQL.
 */
function naval_bd(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    if (!naval_bd_configurada()) throw new RuntimeException('A base de dados não está configurada no .env (DB_HOST, DB_NAME, DB_USER, DB_PASS).');

    $driver = strtolower(naval_config('DB_DRIVER', 'sqlsrv'));
    // sem valores predefinidos para o servidor e o utilizador (nunca «sa»): vêm só do .env
    if (naval_config('DB_DSN') === '') naval_exigir_config(['DB_HOST', 'DB_NAME'], 'a base de dados do Pinguim BO');
    $host = naval_config('DB_HOST');
    $port = naval_config('DB_PORT', '1433');
    $nome = naval_config('DB_NAME');
    $user = naval_config('DB_USER');   // vazio = autenticação integrada do Windows
    $pass = naval_config('DB_PASS');
    $dsnEnv = naval_config('DB_DSN');
    $drivers = PDO::getAvailableDrivers();
    $opcoes = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_TIMEOUT => 10];

    // Ligação cifrada (ASVS 9.2.2): DB_ENCRYPT=1 por omissão; DB_CONFIAR_CERTIFICADO=0 exige um certificado
    // válido no SQL Server (recomendado); =1 aceita o certificado próprio do SQL Server (cifra sem validar).
    $cifrar = naval_config('DB_ENCRYPT', '1') !== '0';
    $confiar = naval_config('DB_CONFIAR_CERTIFICADO', '1') !== '0';
    $tls = ';Encrypt=' . ($cifrar ? 'yes' : 'no') . ';TrustServerCertificate=' . ($confiar ? 'yes' : 'no');   // ODBC
    $tlsSqlsrv = ';Encrypt=' . ($cifrar ? 'true' : 'false') . ';TrustServerCertificate=' . ($confiar ? 'true' : 'false');   // pdo_sqlsrv
    if ($dsnEnv !== '') return $pdo = new PDO($dsnEnv, $user, $pass, $opcoes);
    if ($driver === 'sqlsrv' && in_array('sqlsrv', $drivers, true)) {
        return $pdo = new PDO('sqlsrv:Server=' . $host . ($port !== '' ? ',' . $port : '') . ';Database=' . $nome . $tlsSqlsrv, $user, $pass, $opcoes);
    }
    if (in_array('odbc', $drivers, true)) {
        $odbc = naval_config('DB_ODBC_DRIVER', 'ODBC Driver 17 for SQL Server');
        $tempo = (int) naval_config('DB_TIMEOUT', '5');
        $servidor = 'Server=' . $host . ($port !== '' ? ',' . $port : '') . ';Database=' . $nome . ';';
        try {
            return $pdo = new PDO('odbc:Driver={' . $odbc . '};' . $servidor . ltrim($tls, ';') . ';LoginTimeout=' . $tempo . ';', $user, $pass, $opcoes);
        } catch (PDOException $e) {
            if ($odbc === 'SQL Server') throw $e;
            return $pdo = new PDO('odbc:Driver={SQL Server};' . $servidor . ltrim($tls, ';') . ';LoginTimeout=' . $tempo . ';', $user, $pass, $opcoes);
        }
    }
    if ($driver === 'mysql' && in_array('mysql', $drivers, true)) {
        return $pdo = new PDO('mysql:host=' . $host . ($port !== '' ? ';port=' . $port : '') . ';dbname=' . $nome . ';charset=utf8mb4', $user, $pass, $opcoes);
    }
    throw new RuntimeException('Nenhum driver PDO compatível no PHP para ligar ao SQL Server. Disponíveis: ' . implode(', ', $drivers));
}

/** Palavras que nunca podem aparecer numa consulta do portal (fora de textos entre plicas). */
const NAVAL_BD_PROIBIDAS = ['INSERT', 'UPDATE', 'DELETE', 'MERGE', 'DROP', 'ALTER', 'CREATE', 'TRUNCATE', 'EXEC', 'EXECUTE', 'GRANT', 'REVOKE', 'DENY',
    'INTO', 'BACKUP', 'RESTORE', 'DBCC', 'SHUTDOWN', 'BULK', 'OPENROWSET', 'OPENQUERY', 'OPENDATASOURCE', 'KILL', 'RECONFIGURE', 'USE', 'DECLARE', 'SET', 'WAITFOR'];

/**
 * Garante que a instrução é só de leitura: uma única instrução, a começar por SELECT ou WITH, sem
 * nenhuma palavra de escrita ou de execução. Lança RuntimeException se não for.
 */
function naval_bd_so_leitura(string $sql): void
{
    // Retira comentários e textos entre plicas (não contam para a verificação)
    $limpo = (string) preg_replace(["~/\\*.*?\\*/~s", '~--[^\n]*~', "~'(?:[^']|'')*'~", '~"[^"]*"~', '~\[[^\]]*\]~'], [' ', ' ', "''", '""', '[]'], $sql);
    $limpo = trim($limpo);
    $limpo = rtrim($limpo, "; \t\r\n");
    if (strpos($limpo, ';') !== false) throw new RuntimeException('Consulta recusada: só é permitida uma instrução.');
    if (!preg_match('/^(SELECT|WITH)\b/i', $limpo)) throw new RuntimeException('Consulta recusada: o portal só faz SELECT.');
    if (preg_match('/\b(' . implode('|', NAVAL_BD_PROIBIDAS) . ')\b/i', $limpo, $m)) {
        throw new RuntimeException('Consulta recusada: «' . strtoupper($m[1]) . '» não é permitido (o portal só lê dados).');
    }
    if (preg_match('/\b(xp_|sp_)\w*/i', $limpo, $m)) throw new RuntimeException('Consulta recusada: procedimentos do sistema não são permitidos.');
}

/** SELECT com parâmetros; devolve as linhas (arrays associativos). */
function naval_bd_consultar(string $sql, array $parametros = []): array
{
    naval_bd_so_leitura($sql);
    $st = naval_bd()->prepare($sql);
    $st->execute($parametros);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** Estado da ligação: SELECT 1 e o tempo de resposta. */
function naval_bd_estado(): array
{
    $t0 = microtime(true);
    naval_bd_consultar('SELECT 1 AS OK');
    return ['ok' => true, 'segundos' => round(microtime(true) - $t0, 3), 'origem' => 'SQL Server' . (naval_detalhe(naval_config('DB_NAME')) ? ' (' . naval_config('DB_NAME') . ')' : '')];   // nome da base só para SADM/TI
}

/** AAAAMMDD ou AAAA-MM-DD → AAAA-MM-DD. */
function naval_bd_data(string $d): string
{
    $s = (string) preg_replace('/\D/', '', $d);
    return strlen($s) === 8 ? substr($s, 0, 4) . '-' . substr($s, 4, 2) . '-' . substr($s, 6, 2) : date('Y-m-d');
}
