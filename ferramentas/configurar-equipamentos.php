<?php
/**
 * Ligar o portal à base de dados do módulo Equipamentos (uma vez, no servidor do portal, na pasta do portal):
 *
 *     php ferramentas/configurar-equipamentos.php
 *
 * Antes: a Informática cria a base de dados e a conta com ferramentas/sql/equipamentos-criar-bd.sql (sysadmin).
 * Esta ferramenta:
 *  1. pede o servidor (por omissão o do OLAP, OLAP_HOST, onde fica a base de dados própria do módulo; sem OLAP, o do
 *     Pinguim BO), a base de dados, a conta e a palavra-passe;
 *  2. liga-se e, se as tabelas ATV_* ainda não existirem, cria-as (ferramentas/sql/equipamentos-sqlserver.sql);
 *  3. grava ATV_DB_* no .env (com cópia de segurança do .env anterior) e corre ferramentas/verificar-equipamentos.php
 *     e ferramentas/verificar-mdm.php (cruzamento dos equipamentos do MDM com o portal).
 * Recusa a base de dados do Pinguim BO (DB_NAME): o portal nunca escreve lá. Não mostra a palavra-passe gravada.
 *
 * Sem perguntas: variáveis de ambiente CONF_ATV_DB_HOST, CONF_ATV_DB_NAME, CONF_ATV_DB_USER, CONF_ATV_DB_PASS.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . '/nucleo/arranque.php';
naval_servico('bd');

function equip_pergunta(string $texto, string $variavel, string $actual): string
{
    $v = getenv($variavel);
    if ($v !== false && $v !== '') return trim((string) $v);
    echo $texto . ($actual !== '' ? ' [' . $actual . ']' : '') . ': ';
    $r = trim((string) fgets(STDIN));
    return $r === '' ? $actual : $r;
}

/** Lotes de um script SQL Server separados por «GO» (como no SSMS), sem os que só têm comentários. */
function equip_lotes(string $sql): array
{
    return array_values(array_filter(preg_split('/^\s*GO\s*;?\s*$/mi', $sql) ?: [], function ($l) {
        return trim((string) preg_replace(['~/\*.*?\*/~s', '/^\s*--.*$/m'], '', $l)) !== '';
    }));
}

echo "Equipamentos: ligar o portal à base de dados do módulo\n\n";
$olap = naval_config('OLAP_HOST');
$host = equip_pergunta('Servidor SQL Server (nome ou IP; Enter = ' . ($olap !== '' ? 'o do OLAP' : 'o do Pinguim BO') . ')', 'CONF_ATV_DB_HOST',
    naval_config('ATV_DB_HOST', $olap !== '' ? $olap : naval_config('DB_HOST')));
// as definições que faltam herdam-se de DB_* (Pinguim BO); no servidor do OLAP valem as do OLAP (porta, certificado)
$doOlap = $olap !== '' && strcasecmp($host, $olap) === 0;
$herdadas = [];
if ($doOlap) foreach (['PORT', 'DRIVER', 'ENCRYPT', 'NOME_CERTIFICADO'] as $k) if (naval_config('OLAP_' . $k) !== '') $herdadas[$k] = naval_config('OLAP_' . $k);
$nome = equip_pergunta('Base de dados', 'CONF_ATV_DB_NAME', naval_config('ATV_DB_NAME', 'GN_EQUIPAMENTOS'));
$user = equip_pergunta('Conta', 'CONF_ATV_DB_USER', naval_config('ATV_DB_USER', 'portal_equipamentos'));
$pass = equip_pergunta('Palavra-passe da conta', 'CONF_ATV_DB_PASS', '');

if ($host === '' || $nome === '' || $user === '' || $pass === '') {
    fwrite(STDERR, "Faltam dados: servidor, base de dados, conta e palavra-passe.\n");
    exit(1);
}
if (!preg_match('/^[A-Za-z0-9_]{1,128}$/', $nome)) {
    fwrite(STDERR, "Nome da base de dados inválido (só letras, números e _).\n");
    exit(1);
}
if ((strcasecmp($nome, naval_config('DB_NAME')) === 0 && strcasecmp($host, naval_config('DB_HOST')) === 0)
    || ($doOlap && strcasecmp($nome, naval_config('OLAP_NAME')) === 0)) {
    fwrite(STDERR, "Essa é a base de dados do Pinguim BO ou do OLAP: o portal só as lê. Crie uma base de dados própria no mesmo servidor (ferramentas/sql/equipamentos-criar-bd.sql).
");
    exit(1);
}

try {
    $pdo = naval_bd_criar('ATV_DB', true, array_merge($herdadas, ['HOST' => $host, 'NAME' => $nome, 'USER' => $user, 'PASS' => $pass]));
    echo "  ok     ligação a $nome em $host\n";
} catch (Throwable $e) {
    fwrite(STDERR, '  FALHA  ligação: ' . $e->getMessage() . "\n");
    exit(1);
}

$odbc = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'odbc';
$existe = (int) $pdo->query("SELECT COUNT(*) AS N FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = 'ATV_ACTIVO'")->fetchColumn() > 0;
if ($existe) {
    echo "  ok     tabelas ATV_* já existem (não alteradas)\n";
} else {
    $sql = (string) file_get_contents(__DIR__ . '/sql/equipamentos-sqlserver.sql');
    $lotes = equip_lotes($sql);
    try {
        foreach ($lotes as $i => $lote) $pdo->exec($odbc ? naval_bd_para_odbc($lote) : $lote);
        echo '  ok     tabelas, vistas e regras criadas (' . count($lotes) . " lotes)\n";
    } catch (Throwable $e) {
        fwrite(STDERR, '  FALHA  ao criar as tabelas (lote ' . (($i ?? 0) + 1) . '): ' . $e->getMessage() . "\n"
            . "         Confirme que a conta tem db_ddladmin nesta base de dados (equipamentos-criar-bd.sql). Nada foi gravado no .env.\n");
        exit(1);
    }
}

$grava = ['ATV_DB_NAME' => $nome, 'ATV_DB_USER' => $user, 'ATV_DB_PASS' => $pass];
foreach ($herdadas as $k => $v) if (naval_config('ATV_DB_' . $k) === '') $grava['ATV_DB_' . $k] = $v;
if ($host !== naval_config('DB_HOST')) $grava['ATV_DB_HOST'] = $host;
try {
    $copia = naval_env_gravar($grava, 'equipamentos');
    echo '  ok     gravado no .env: ' . implode(', ', array_keys($grava)) . " (cópia do anterior: $copia)\n\n";
} catch (Throwable $e) {
    fwrite(STDERR, '  FALHA  ' . $e->getMessage() . "\n");
    exit(1);
}

$php = escapeshellarg(PHP_BINARY);
passthru($php . ' ' . escapeshellarg(__DIR__ . '/verificar-equipamentos.php'), $r1);
echo "\n";
passthru($php . ' ' . escapeshellarg(__DIR__ . '/verificar-mdm.php'), $r2);
exit($r1 || $r2 ? 1 : 0);
