<?php
/**
 * Resolver os avisos «Por configurar no servidor» da Conformidade (uma vez, no servidor, na pasta do portal):
 *
 *     php ferramentas/configurar-conformidade.php
 *
 *  1. COPIAS_CHAVE: gera uma chave aleatória de 256 bits para cifrar as cópias (AES-256-GCM), se ainda não houver.
 *     Mostra-a UMA vez para guardar fora do servidor (cofre de palavras-passe da Informática): sem ela, as cópias
 *     externas não se recuperam se o servidor se perder (ISO/IEC 27001 A.8.13, A.8.24).
 *  2. COPIAS_PASTA_EXTERNA e REGISTOS_COPIA_PASTA: pede as pastas fora do servidor (pasta de rede \\servidor\partilha\…
 *     ou disco externo) e confirma que existem e que o portal consegue escrever e apagar lá (A.8.13, A.8.15).
 *  3. Certificado do SQL Server (Pinguim BO e OLAP): mostra porque a validação falhou e o que fazer (A.8.24); com o
 *     nome do certificado (DB_NOME_CERTIFICADO / OLAP_NOME_CERTIFICADO) a validação funciona mesmo ligando pelo IP.
 * Grava no .env com cópia de segurança do .env anterior (naval_env_gravar). Nunca mostra outros segredos.
 *
 * Sem perguntas: variáveis de ambiente CONF_COPIAS_PASTA_EXTERNA, CONF_REGISTOS_COPIA_PASTA, CONF_DB_NOME_CERTIFICADO,
 * CONF_OLAP_NOME_CERTIFICADO (vazio = não mudar).
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . '/nucleo/arranque.php';
naval_servico('bd');

function conf_pergunta(string $texto, string $variavel, string $actual): string
{
    $v = getenv($variavel);
    if ($v !== false) return trim((string) $v);
    echo $texto . ($actual !== '' ? ' [' . $actual . ']' : '') . ': ';
    $r = trim((string) fgets(STDIN));
    return $r === '' ? $actual : $r;
}

/** A pasta existe e o portal escreve, lê e apaga lá? '' se sim, senão o motivo. */
function conf_pasta_ok(string $p): string
{
    if (!is_dir($p)) return 'a pasta não existe ou não está acessível a partir do servidor';
    $f = rtrim($p, '/\\') . DIRECTORY_SEPARATOR . '.portal-rtm-teste-' . bin2hex(random_bytes(4));
    if (@file_put_contents($f, 'teste') !== 5) return 'o portal não consegue escrever lá (permissões da conta do serviço web)';
    $ok = @file_get_contents($f) === 'teste';
    @unlink($f);
    if (!$ok) return 'o portal escreve mas não consegue ler o que escreveu';
    if (is_file($f)) return 'o portal não consegue apagar lá';
    return '';
}

echo "Conformidade: configurar o que só a Informática pode configurar\n\n";
$grava = [];

// 1. chave das cópias
if (naval_config('COPIAS_CHAVE') === '') {
    $chave = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $grava['COPIAS_CHAVE'] = $chave;
    echo "1. Chave das cópias gerada. GUARDE-A AGORA fora do servidor (cofre da Informática); não volta a ser mostrada:\n\n";
    echo '      ' . $chave . "\n\n";
    echo '   impressão digital (para confirmar mais tarde): ' . substr(hash('sha256', $chave), 0, 16) . "\n\n";
} else {
    echo '1. Chave das cópias já definida (impressão digital ' . substr(hash('sha256', naval_config('COPIAS_CHAVE')), 0, 16) . ").\n\n";
}

// 2. pastas fora do servidor
foreach (['COPIAS_PASTA_EXTERNA' => 'Pasta externa das cópias (ex.: \\\\nas\\backups\\portal-rtm)', 'REGISTOS_COPIA_PASTA' => 'Pasta externa dos registos de auditoria (ex.: \\\\nas\\registos\\portal-rtm)'] as $k => $txt) {
    $p = conf_pergunta('2. ' . $txt, 'CONF_' . $k, naval_config($k));
    if ($p === '') {
        echo "   (fica por definir)\n";
        continue;
    }
    $m = conf_pasta_ok($p);
    if ($m !== '') {
        echo '   FALHA  ' . $p . ': ' . $m . ". Não gravado.\n";
        continue;
    }
    if ($p !== naval_config($k)) $grava[$k] = $p;
    echo '   ok     ' . $p . "\n";
}
echo "\n";

// 3. certificados das bases de dados
foreach (['DB' => 'Pinguim BO', 'OLAP' => 'OLAP'] as $p => $nome) {
    $mem = naval_bd_memoria($p);
    if (!$mem) {
        echo '3. ' . $nome . ": ainda sem ligações registadas neste servidor.\n";
        continue;
    }
    if (!empty($mem['certificado_validado'])) {
        echo '3. ' . $nome . ": certificado validado.\n";
        continue;
    }
    echo '3. ' . $nome . ': certificado NÃO validado — ' . naval_bd_causa_certificado((string) ($mem['falha_certificado']['erro'] ?? '')) . "\n";
    $n = conf_pergunta('   Nome do certificado do SQL Server de ' . $nome . ' (vazio = não mudar)', 'CONF_' . $p . '_NOME_CERTIFICADO', naval_config($p . '_NOME_CERTIFICADO'));
    if ($n !== '' && $n !== naval_config($p . '_NOME_CERTIFICADO')) {
        if (!preg_match('/^[A-Za-z0-9.*-]{1,253}$/', $n)) echo "   FALHA  nome inválido. Não gravado.\n";
        else $grava[$p . '_NOME_CERTIFICADO'] = $n;
    }
}
echo "\n";

if (!$grava) {
    echo "Nada a gravar.\n";
    exit(0);
}
try {
    $copia = naval_env_gravar($grava, 'conformidade');
    echo 'Gravado no .env: ' . implode(', ', array_keys($grava)) . ' (cópia do .env anterior: ' . $copia . ").\n";
    echo "A próxima verificação da Conformidade (02:00) confirma; ou em Administração › Conformidade › «Verificar agora».\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'FALHA  ' . $e->getMessage() . "\n");
    exit(1);
}
