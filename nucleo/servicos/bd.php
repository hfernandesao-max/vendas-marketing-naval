<?php
/**
 * Base de dados do Pinguim BO (Microsoft SQL Server, PingWinBO): ligação directa, SÓ DE LEITURA.
 *
 * O portal só lê: todas as consultas passam por naval_bd_consultar(), que recusa qualquer instrução
 * que não seja SELECT (ou WITH … SELECT). Recomendação: um utilizador da base de dados só com a
 * função db_datareader, para a protecção existir também no próprio SQL Server.
 *
 * .env: DB_DRIVER (sqlsrv | odbc | mysql), DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS,
 *       DB_ODBC_DRIVER, DB_TIMEOUT, DB_TEMPO_CONSULTA, DB_ENCRYPT, DB_CONFIAR_CERTIFICADO, DB_DSN (DSN completo, se preferir).
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
    // sem valores predefinidos para o servidor e o utilizador (nunca «sa»): vêm só do .env
    if (naval_config('DB_DSN') === '') naval_exigir_config(['DB_HOST', 'DB_NAME'], 'a base de dados do Pinguim BO');
    return $pdo = naval_bd_criar('DB');
}

/**
 * Controladores ODBC do SQL Server, do mais recente para o mais antigo. O 18 e o 17 suportam TLS 1.2+, validação do
 * certificado e ApplicationIntent; o antigo «SQL Server» do Windows (SQLSRV32) não valida o certificado como deve e
 * fica só como último recurso, assinalado como fora da norma.
 */
const NAVAL_BD_ODBC = ['ODBC Driver 18 for SQL Server', 'ODBC Driver 17 for SQL Server', 'SQL Server'];
const NAVAL_BD_ODBC_ANTIGO = 'SQL Server';
const NAVAL_BD_MEMORIA_HORAS = 6;   // depois de uma falha da validação do certificado, só se volta a tentar ao fim de 6 horas

/**
 * DSN de uma ligação ao SQL Server (função pura, testada). $c: host, port, nome, timeout, app; $tipo: 'sqlsrv' ou o
 * nome do controlador ODBC; $cifrar e $validar: cifra e validação do certificado (ISO/IEC 27001 A.8.24).
 */
function naval_bd_dsn(array $c, string $tipo, bool $cifrar, bool $validar): string
{
    $servidor = $c['host'] . ($c['port'] !== '' ? ',' . $c['port'] : '');
    $tempo = max(1, (int) $c['timeout']);
    $app = preg_replace('/[^\w.\-]/', '', (string) $c['app']);
    if ($tipo === 'sqlsrv') {
        return 'sqlsrv:Server=' . $servidor . ';Database=' . $c['nome'] . ';Encrypt=' . ($cifrar ? 'true' : 'false')
            . ';TrustServerCertificate=' . ($validar ? 'false' : 'true') . ';LoginTimeout=' . $tempo . ';APP=' . $app . ';ApplicationIntent=ReadOnly';
    }
    $dsn = 'odbc:Driver={' . $tipo . '};Server=' . $servidor . ';Database=' . $c['nome'] . ';Encrypt=' . ($cifrar ? 'yes' : 'no')
        . ';TrustServerCertificate=' . ($validar ? 'no' : 'yes') . ';LoginTimeout=' . $tempo . ';APP=' . $app . ';';
    // o controlador antigo não conhece ApplicationIntent
    if ($tipo !== NAVAL_BD_ODBC_ANTIGO) $dsn .= 'ApplicationIntent=ReadOnly;';
    return $dsn;
}

/** O que se aprendeu nas últimas ligações desta fonte (controlador que serve, certificado validado ou não). */
function naval_bd_memoria(string $p, ?array $novo = null): array
{
    $f = naval_pasta('bd') . '/ligacao-' . strtolower(preg_replace('/\W/', '', $p)) . '.json';
    if ($novo !== null) {
        naval_json_gravar($f, $novo);
        return $novo;
    }
    return (array) naval_json_ler($f, []);
}

/**
 * Cria uma ligação PDO a partir das chaves do .env com o prefixo indicado (DB_HOST, DB_NAME, …; ou, por
 * exemplo, ATV_DB_HOST, ATV_DB_NAME, …). Com $herdar, as chaves em falta vêm das do Pinguim BO (DB_*):
 * servidor, porta, driver e cifra — útil para uma base de dados própria no mesmo SQL Server.
 *
 * Configuração de referência (Microsoft SQL Server, ISO/IEC 27001 A.8.24, A.5.15):
 *   - controlador: pdo_sqlsrv, ou ODBC Driver 18 → 17 (o primeiro que estiver instalado; DB_ODBC_DRIVER fixa um);
 *   - cifra obrigatória (DB_ENCRYPT=1) e certificado validado: valida-se sempre primeiro; enquanto o servidor não
 *     tiver um certificado válido, a ligação faz-se cifrada sem validação e fica assinalada como fora da norma na
 *     Conformidade (DB_CONFIAR_CERTIFICADO=0 recusa-a);
 *   - ApplicationIntent=ReadOnly e o nome da aplicação (APP=PortalRTM…), para o DBA ver quem faz cada consulta;
 *   - tempo limite de ligação (DB_TIMEOUT, 5 s) e de consulta (DB_TEMPO_CONSULTA, 30 s; no ODBC limita a espera por
 *     bloqueios, LOCK_TIMEOUT, para nunca atrasar a facturação do Pinguim BO).
 */
function naval_bd_criar(string $p, bool $herdar = false, array $sobrepor = []): PDO
{
    // $sobrepor: chaves com outro valor só nesta ligação (ex.: USER/PASS da conta das migrações do actualizador)
    $cfg = function (string $k, string $omissao = '') use ($p, $herdar, $sobrepor) {
        if (isset($sobrepor[$k]) && $sobrepor[$k] !== '') return (string) $sobrepor[$k];
        $v = naval_config($p . '_' . $k);
        if ($v === '' && $herdar && $p !== 'DB') $v = naval_config('DB_' . $k);
        return $v !== '' ? $v : $omissao;
    };
    $driver = strtolower($cfg('DRIVER', 'sqlsrv'));
    $c = ['host' => $cfg('HOST'), 'port' => $cfg('PORT', '1433'), 'nome' => $cfg('NAME'), 'timeout' => $cfg('TIMEOUT', '5'),
        'app' => 'PortalRTM' . ($p === 'DB' ? '' : '-' . ucfirst(strtolower(str_replace('_DB', '', $p))))];
    // vazio = autenticação integrada do Windows: o PDO recebe null (com «» o ODBC acrescentaria UID= e PWD= vazios)
    $user = $cfg('USER') !== '' ? $cfg('USER') : null;
    $pass = $cfg('PASS') !== '' ? $cfg('PASS') : null;
    $dsnEnv = naval_config($p . '_DSN');
    $drivers = PDO::getAvailableDrivers();
    $tempoConsulta = max(5, (int) $cfg('TEMPO_CONSULTA', '30'));
    $opcoes = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_TIMEOUT => (int) $c['timeout']];
    // o pdo_sqlsrv recusa PDO::ATTR_TIMEOUT no construtor («unsupported attribute»): o tempo de ligação vai no DSN
    $opcoesSqlsrv = $opcoes;
    unset($opcoesSqlsrv[PDO::ATTR_TIMEOUT]);
    if (defined('PDO::SQLSRV_ATTR_QUERY_TIMEOUT')) $opcoesSqlsrv[constant('PDO::SQLSRV_ATTR_QUERY_TIMEOUT')] = $tempoConsulta;

    $cifrar = $cfg('ENCRYPT', '1') !== '0';
    $certCfg = $cfg('CONFIAR_CERTIFICADO', '');   // 0 = exige sempre a validação; outro valor = automático (valida primeiro)
    $mem = naval_bd_memoria($p);
    $registo = ['fonte' => $p, 'cifra' => $cifrar, 'app' => $c['app'], 'tempo_ligacao' => (int) $c['timeout'], 'tempo_consulta' => $tempoConsulta];
    $fim = function (PDO $pdo, string $ctl, bool $validado, ?string $erroCert) use ($p, &$registo, $mem, $tempoConsulta) {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'odbc') {
            try {
                $pdo->exec('SET LOCK_TIMEOUT ' . ($tempoConsulta * 1000));
            } catch (Throwable $e) {
                /* sem efeito no servidor: fica o limite de tempo do PHP */
            }
        }
        $registo = ['controlador' => $ctl, 'certificado_validado' => $validado, 'intencao' => $ctl !== NAVAL_BD_ODBC_ANTIGO] + $registo;
        $GLOBALS['naval_bd_ligacao'][$p] = $registo;
        $nova = ['controlador' => $ctl, 'certificado_validado' => $validado, 'em' => date('Y-m-d H:i:s'),
            'falha_certificado' => $erroCert !== null ? ['em' => date('Y-m-d H:i:s'), 'erro' => mb_substr($erroCert, 0, 300)] : ($validado ? null : ($mem['falha_certificado'] ?? null))];
        if (($mem['controlador'] ?? null) !== $nova['controlador'] || ($mem['certificado_validado'] ?? null) !== $validado || $erroCert !== null
            || substr((string) ($mem['em'] ?? ''), 0, 13) !== substr($nova['em'], 0, 13)) {
            try {
                naval_bd_memoria($p, $nova);
            } catch (Throwable $e) {
                /* memória só para acelerar */
            }
        }
        return $pdo;
    };

    if ($dsnEnv !== '') {
        $pdo = new PDO($dsnEnv, $user, $pass, stripos($dsnEnv, 'sqlsrv:') === 0 ? $opcoesSqlsrv : $opcoes);
        $registo['dsn_proprio'] = true;
        $validado = stripos($dsnEnv, 'TrustServerCertificate=yes') === false && stripos($dsnEnv, 'TrustServerCertificate=true') === false;
        return $fim($pdo, stripos($dsnEnv, 'sqlsrv:') === 0 ? 'pdo_sqlsrv' : 'DSN do .env', $validado && $cifrar, null);
    }
    if ($driver === 'mysql' && in_array('mysql', $drivers, true)) {
        return new PDO('mysql:host=' . $c['host'] . ($c['port'] !== '' ? ';port=' . $c['port'] : '') . ';dbname=' . $c['nome'] . ';charset=utf8mb4', $user, $pass, $opcoes);
    }

    // controladores a tentar: pdo_sqlsrv; depois o ODBC fixado no .env ou, sem ele, 18 → 17 → antigo (um controlador
    // que não está instalado falha logo, sem esperar pelo servidor)
    $tipos = [];
    if ($driver === 'sqlsrv' && in_array('sqlsrv', $drivers, true)) $tipos[] = 'sqlsrv';
    if (in_array('odbc', $drivers, true)) {
        $fixo = $cfg('ODBC_DRIVER');
        foreach ($fixo !== '' ? [$fixo] : NAVAL_BD_ODBC as $l) $tipos[] = $l;
    }
    if (!$tipos) throw new RuntimeException('Nenhum driver PDO compatível no PHP para ligar ao SQL Server. Disponíveis: ' . implode(', ', $drivers));

    // certificado: valida-se sempre primeiro. Se a validação falhar e DB_CONFIAR_CERTIFICADO não for 0, tenta-se uma
    // vez sem validar (fica assinalado na Conformidade e no correio diário) e só se volta a tentar validar ao fim de
    // 6 horas, para não atrasar cada pedido. Com 0, a ligação sem certificado válido é recusada.
    $exigir = $certCfg === '0';
    $falhou = (string) ($mem['falha_certificado']['em'] ?? '');
    $saltarValidacao = !$exigir && $cifrar && $falhou !== '' && empty($mem['certificado_validado'])
        && time() - (int) strtotime($falhou) < NAVAL_BD_MEMORIA_HORAS * 3600;
    $erro = null;
    foreach ($tipos as $tipo) {
        $ctl = $tipo === 'sqlsrv' ? 'pdo_sqlsrv' : $tipo;
        $op = $tipo === 'sqlsrv' ? $opcoesSqlsrv : $opcoes;
        $erroCert = null;
        if ($cifrar && !$saltarValidacao) {
            try {
                return $fim(new PDO(naval_bd_dsn($c, $tipo, true, true), $user, $pass, $op), $ctl, true, null);
            } catch (PDOException $e) {
                $erro = $e;
                if (naval_bd_erro_controlador($e)) continue;          // controlador não instalado: o seguinte
                if ($exigir) throw new RuntimeException('Ligação recusada: o certificado do SQL Server não foi validado e ' . $p . '_CONFIAR_CERTIFICADO=0 exige a validação. ' . $e->getMessage(), 0, $e);
                $erroCert = $e->getMessage();
            }
        }
        try {
            return $fim(new PDO(naval_bd_dsn($c, $tipo, $cifrar, false), $user, $pass, $op), $ctl, false, $erroCert);
        } catch (PDOException $e) {
            if (naval_bd_erro_controlador($e)) {
                $erro = $e;
                continue;
            }
            throw $erroCert !== null && $erro ? $erro : $e;          // o servidor não responde: o erro original
        }
    }
    throw $erro ?? new RuntimeException('Sem controlador do SQL Server disponível.');
}

/** O erro diz que o controlador ODBC não está instalado? */
function naval_bd_erro_controlador(Throwable $e): bool
{
    return (bool) preg_match('/IM002|Data source name not found|driver.*not.*(found|loaded)|Can.t open lib|nome da fonte de dados n.o encontrado/i', $e->getMessage());
}

/**
 * Conformidade de cada ligação às bases de dados (para a página Conformidade e o estado do sistema): controlador,
 * cifra, certificado validado, intenção só de leitura, nome da aplicação e tempos limite. Usa a ligação deste pedido
 * ou, sem ela, o que ficou registado da última.
 */
function naval_bd_conformidade(string $p = 'DB'): array
{
    $r = $GLOBALS['naval_bd_ligacao'][$p] ?? null;
    $mem = naval_bd_memoria($p);
    if (!$r && !$mem) return ['fonte' => $p, 'conhecida' => false, 'controlos' => [], 'conforme' => null];
    $ctl = (string) ($r['controlador'] ?? $mem['controlador'] ?? '');
    $validado = (bool) ($r['certificado_validado'] ?? $mem['certificado_validado'] ?? false);
    $cifra = (bool) ($r['cifra'] ?? naval_config($p . '_ENCRYPT', '1') !== '0');
    $antigo = $ctl === NAVAL_BD_ODBC_ANTIGO;
    $controlos = [
        ['id' => 'controlador', 'ok' => !$antigo && $ctl !== '', 'valor' => $ctl, 'norma' => 'ISO/IEC 27001 A.8.8'],
        ['id' => 'cifra', 'ok' => $cifra, 'valor' => $cifra ? 'Encrypt=yes' : 'Encrypt=no', 'norma' => 'ISO/IEC 27001 A.8.24'],
        ['id' => 'certificado', 'ok' => $cifra && $validado, 'valor' => $validado ? 'validado' : 'não validado', 'norma' => 'ISO/IEC 27001 A.8.24',
            'erro' => $validado ? null : ($mem['falha_certificado']['erro'] ?? null)],
        ['id' => 'intencao', 'ok' => !$antigo, 'valor' => $antigo ? '—' : 'ApplicationIntent=ReadOnly', 'norma' => 'ISO/IEC 27001 A.5.15'],
        ['id' => 'aplicacao', 'ok' => true, 'valor' => (string) ($r['app'] ?? 'PortalRTM'), 'norma' => 'ISO/IEC 27001 A.8.15'],
        ['id' => 'tempos', 'ok' => true, 'valor' => (int) ($r['tempo_ligacao'] ?? max(1, (int) naval_config($p . '_TIMEOUT', '5'))) . ' s / ' . (int) ($r['tempo_consulta'] ?? max(5, (int) naval_config($p . '_TEMPO_CONSULTA', '30'))) . ' s', 'norma' => 'ISO/IEC 25010'],
    ];
    return ['fonte' => $p, 'conhecida' => true, 'em' => (string) ($mem['em'] ?? date('Y-m-d H:i:s')), 'controlos' => $controlos,
        'conforme' => !array_filter($controlos, function ($x) { return !$x['ok']; })];
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

/**
 * Texto vindo da base de dados em UTF-8. O driver ODBC devolve os campos de texto na página de código do
 * Windows (Windows-1252), por isso «Armazém» chegava como bytes inválidos em UTF-8 e aparecia «Armaz�m».
 * Texto que já é UTF-8 válido (driver sqlsrv, MySQL) fica como está.
 */
function naval_bd_utf8(string $v): string
{
    if ($v === '' || preg_match('//u', $v)) return $v;
    if (function_exists('mb_convert_encoding')) return (string) mb_convert_encoding($v, 'UTF-8', 'Windows-1252');
    if (function_exists('iconv')) {
        $r = @iconv('Windows-1252', 'UTF-8//IGNORE', $v);
        if ($r !== false) return $r;
    }
    return (string) preg_replace('/[\x80-\xFF]/', '?', $v);
}

/**
 * Texto enviado para a base de dados pelo driver ODBC: na página de código do Windows (Windows-1252), a mesma
 * em que o ODBC devolve o texto. Sem isto, «FORMAÇÃO» numa consulta ou num parâmetro chegava ao SQL Server
 * como «FORMAÃ‡ÃƒO» e as comparações com acentos falhavam.
 */
function naval_bd_para_odbc(string $v): string
{
    if ($v === '' || !preg_match('/[\x80-\xFF]/', $v)) return $v;
    if (function_exists('mb_convert_encoding')) return (string) mb_convert_encoding($v, 'Windows-1252', 'UTF-8');
    if (function_exists('iconv')) {
        $r = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $v);
        if ($r !== false) return $r;
    }
    return $v;
}

/** SELECT com parâmetros; devolve as linhas (arrays associativos), com o texto em UTF-8. */
function naval_bd_consultar(string $sql, array $parametros = []): array
{
    naval_bd_so_leitura($sql);
    $pdo = naval_bd();
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'odbc') {
        $sql = naval_bd_para_odbc($sql);
        foreach ($parametros as $k => $v) if (is_string($v)) $parametros[$k] = naval_bd_para_odbc($v);
    }
    $t0 = microtime(true);
    $st = $pdo->prepare($sql);
    $st->execute($parametros);
    $linhas = $st->fetchAll(PDO::FETCH_ASSOC);
    // consultas lentas ficam registadas para revisão (sem parâmetros: não há dados pessoais no registo)
    $ms = (int) round((microtime(true) - $t0) * 1000);
    if ($ms > 5000) error_log('[bd lenta] ' . $ms . ' ms · ' . mb_substr((string) preg_replace('/\s+/', ' ', $sql), 0, 160));
    foreach ($linhas as &$l) {
        foreach ($l as $k => $v) {
            if (is_string($v)) $l[$k] = naval_bd_utf8($v);
        }
    }
    unset($l);
    return $linhas;
}

/** Estado da ligação: SELECT 1 e o tempo de resposta. */
function naval_bd_estado(): array
{
    $t0 = microtime(true);
    naval_bd_consultar('SELECT 1 AS OK');
    return ['ok' => true, 'segundos' => round(microtime(true) - $t0, 3), 'origem' => 'SQL Server' . (naval_detalhe(naval_config('DB_NAME')) ? ' (' . naval_config('DB_NAME') . ')' : ''),   // nome da base só para SADM/TI
        'ligacao' => naval_bd_conformidade('DB')];
}

/** AAAAMMDD ou AAAA-MM-DD → AAAA-MM-DD. */
function naval_bd_data(string $d): string
{
    $s = (string) preg_replace('/\D/', '', $d);
    return strlen($s) === 8 ? substr($s, 0, 4) . '-' . substr($s, 4, 2) . '-' . substr($s, 6, 2) : date('Y-m-d');
}
