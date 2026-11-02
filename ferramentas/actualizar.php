<?php
/**
 * Actualizações automáticas do portal (na pasta do portal, no servidor):
 *
 *   php ferramentas/actualizar.php configurar        token do GitHub (só leitura) e correio para os avisos; uma vez
 *   php ferramentas/actualizar.php tarefa [HH:MM]    cria a tarefa agendada do Windows (todos os dias, 02:15 por omissão)
 *   php ferramentas/actualizar.php estado            versão instalada e versão mais recente publicada
 *   php ferramentas/actualizar.php verificar         mostra o que mudaria, sem alterar nada
 *   php ferramentas/actualizar.php aplicar           aplica já a versão mais recente
 *         [--pacote=ficheiro.zip] [--forcar] [--sem-http]
 *   php ferramentas/actualizar.php reverter          repõe a versão anterior (última cópia de segurança)
 *   php ferramentas/actualizar.php automatico        o que a tarefa agendada corre (respeita ACTUALIZACAO_AUTOMATICA)
 *
 * Como funciona e o que nunca toca: nucleo/servicos/actualizacao.php. Os segredos nunca são mostrados no ecrã.
 * Saída: 0 = bem; 1 = falha ou versão revertida; 2 = parado por ficheiros alterados no servidor.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
// No Windows, o ficheiro em execução fica bloqueado e não pode ser substituído: para aplicar uma versão, o
// actualizador corre a partir de uma cópia de si próprio na pasta privada (NAVAL_ACT_RAIZ indica o portal).
$raizPortal = (string) getenv('NAVAL_ACT_RAIZ') ?: dirname(__DIR__);
require_once $raizPortal . '/nucleo/arranque.php';
naval_servico('actualizacao');

$args = array_slice($argv, 1);
$comando = $args[0] ?? 'estado';
if (in_array($comando, ['aplicar', 'automatico', 'reverter'], true) && (string) getenv('NAVAL_ACT_RAIZ') === '') {
    $copia = naval_act_pasta() . '/executor.php';
    if (!@copy(__FILE__, $copia)) {
        fwrite(STDERR, "  FALHA  Não foi possível preparar a cópia do actualizador na pasta privada.\n");
        exit(1);
    }
    putenv('NAVAL_ACT_RAIZ=' . $raizPortal);
    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($copia) . ' ' . implode(' ', array_map('escapeshellarg', $args)), $r);
    exit((int) $r);
}
$opcao = function (string $nome) use ($args) {
    foreach ($args as $a) {
        if ($a === '--' . $nome) return true;
        if (strpos($a, '--' . $nome . '=') === 0) return substr($a, strlen($nome) + 3);
    }
    return null;
};
$pergunta = function (string $texto, string $variavel): string {
    $v = (string) getenv($variavel);
    if ($v !== '') return trim($v);
    echo $texto . ': ';
    return trim((string) fgets(STDIN));
};
$falha = function (string $m): void {
    fwrite(STDERR, '  FALHA  ' . $m . "\n");
    exit(1);
};

try {
    switch ($comando) {
        case 'verificar-instalacao':
            // chamado pelo actualizador num processo novo, depois de copiar: o código novo arranca e liga às bases de dados?
            require_once naval_raiz() . '/nucleo/api/router.php';
            naval_servico('bd');
            if (naval_bd_configurada()) naval_bd_consultar('SELECT 1 AS X');
            if (naval_config('ATV_DB_DSN') !== '' || naval_config('ATV_DB_NAME') !== '') {
                naval_servico('equipamentos');
                naval_atv_bd()->query('SELECT COUNT(*) FROM ATV_TIPO')->fetch();
            }
            echo "ok\n";
            exit(0);

        case 'configurar':
            echo "Actualizações automáticas: configurar\n\n";
            echo "Token do GitHub: «Fine-grained token» só com «Contents: Read-only» no repositório "
                . naval_config('ACTUALIZACAO_REPOSITORIO', NAVAL_ACT_REPOSITORIO) . " (não é mostrado no ecrã depois de gravado).\n";
            $token = $pergunta('Token', 'ACTUALIZACAO_CONFIG_TOKEN');
            if (!preg_match('/^(github_pat_|ghp_)[A-Za-z0-9_]{20,}$/', $token)) $falha('Isto não parece um token do GitHub (começa por github_pat_).');
            $email = $pergunta('Correio electrónico para os avisos (vazio = sem avisos; vários separados por vírgula)', 'ACTUALIZACAO_CONFIG_EMAIL');
            $chaves = ['ACTUALIZACAO_GITHUB_TOKEN' => $token, 'ACTUALIZACAO_AUTOMATICA' => '1'];
            if ($email !== '') $chaves['ACTUALIZACAO_EMAIL'] = $email;
            $copia = naval_env_gravar($chaves, 'actualizacao');
            echo "  ok     chaves gravadas no .env (cópia do anterior: $copia)\n";
            // nova leitura do .env num processo próprio: confirma o acesso ao GitHub
            passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' estado', $r);
            exit((int) $r);

        case 'tarefa':
            $hora = (string) ($args[1] ?? '02:15');
            if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $hora)) $falha('Hora inválida (HH:MM).');
            if (DIRECTORY_SEPARATOR !== '\\') $falha('A tarefa agendada é do Windows; noutro sistema use o cron com «php ' . __FILE__ . ' automatico».');
            // aspas interiores do /TR com \" (o escapeshellarg do Windows troca as aspas por espaços)
            $cmd = 'schtasks /Create /F /TN "Portal RTM - Actualizacao" /SC DAILY /ST ' . $hora . ' /RU SYSTEM /RL HIGHEST /TR "\\"' . PHP_BINARY . '\\" \\"' . __FILE__ . '\\" automatico"';
            passthru($cmd, $r);
            if ($r !== 0) $falha('Não foi possível criar a tarefa (abrir a linha de comandos como administrador).');
            echo "  ok     tarefa «Portal RTM - Actualizacao» todos os dias às $hora (conta SYSTEM)\n";
            exit(0);

        case 'estado':
            $e = naval_act_estado();
            echo 'Instalada:  ' . ($e['versao'] !== '' ? $e['versao'] . ' (' . substr((string) $e['commit'], 0, 7) . ', ' . $e['aplicada_em'] . ')' : 'ainda nenhuma actualização automática') . "\n";
            if (!empty($e['recusada'])) echo 'Recusada:   ' . $e['recusada'] . " (revertida; só volta com «aplicar --forcar»)\n";
            echo 'Automática: ' . (naval_config('ACTUALIZACAO_AUTOMATICA') === '1' ? 'ligada' : 'desligada (ACTUALIZACAO_AUTOMATICA=1 para ligar)') . "\n";
            $u = naval_act_ultima_versao();
            echo 'Publicada:  ' . $u['versao'] . ' (' . substr($u['commit'], 0, 7) . ")\n";
            if ($u['dias_token'] !== null) echo 'Token:      expira dentro de ' . $u['dias_token'] . " dias\n";
            echo "  ok     ligação ao GitHub a funcionar\n";
            exit(0);

        case 'verificar':
        case 'aplicar':
        case 'automatico':
            $auto = $comando === 'automatico';
            if ($auto && naval_config('ACTUALIZACAO_AUTOMATICA') !== '1') {
                echo "Actualizações automáticas desligadas (ACTUALIZACAO_AUTOMATICA).\n";
                exit(0);
            }
            $r = naval_act_aplicar([
                'pacote' => is_string($opcao('pacote')) ? $opcao('pacote') : null,
                'ensaio' => $comando === 'verificar',
                'forcar' => $opcao('forcar') === true,
                'sem_http' => $opcao('sem-http') === true,
                'sem_ziparchive' => $opcao('sem-ziparchive') === true,
            ]);
            foreach ($r['avisos'] ?? [] as $a) echo '  aviso  ' . $a . "\n";
            switch ($r['resultado']) {
                case 'actualizada':
                    echo '  ok     actualizado para ' . $r['versao'] . "\n";
                    naval_act_avisar('actualizado para ' . $r['versao'], array_merge(['O portal foi actualizado para a versão ' . $r['versao'] . ' (' . $r['ficheiros'] . ' ficheiros'
                        . ($r['migracoes'] ? ', migrações ' . implode(', ', $r['migracoes']) : '') . ') e verificado.', 'Cópia de segurança: ' . $r['copia'] . '.'], $r['avisos']));
                    exit(0);
                case 'conflito':
                    echo "  PARADO há ficheiros alterados à mão no servidor (lista acima). Nada foi alterado.\n";
                    echo "         Levar essas alterações para o GitHub, ou aplicar com --forcar (ficam guardadas na cópia de segurança).\n";
                    naval_act_avisar('actualização parada', array_merge(['A versão ' . $r['versao'] . ' não foi aplicada: estes ficheiros foram alterados à mão no servidor desde a última actualização: '
                        . implode(', ', $r['plano']['conflitos']) . '.', 'Nada foi alterado. Ver «php ferramentas/actualizar.php verificar».'], $r['avisos']));
                    exit(2);
                case 'revertida':
                    echo "  FALHA  a versão nova não passou na verificação; a anterior foi reposta.\n";
                    naval_act_avisar('actualização revertida', array_merge(['A versão ' . $r['versao'] . ' falhou a verificação e a versão anterior foi reposta automaticamente.',
                        'Falhas: ' . implode('; ', $r['falhas']) . '.'], $r['erros_reposicao'] ? ['ATENÇÃO, reposição com erros: ' . implode('; ', $r['erros_reposicao'])] : [], $r['avisos']));
                    exit(1);
                default:
                    if ($auto && !empty($r['avisos'])) naval_act_avisar('aviso das actualizações', $r['avisos']);
                    exit(0);
            }
            // no break

        case 'reverter':
            $r = naval_act_reverter(naval_raiz());
            echo '  ' . ($r['erros'] ? 'FALHA  reposição com erros: ' . implode('; ', $r['erros']) : 'ok     versão anterior reposta' . ($r['versao'] !== '' ? ' (' . $r['versao'] . ')' : '')) . "\n";
            exit($r['erros'] ? 1 : 0);

        default:
            fwrite(STDERR, "Comandos: configurar, tarefa, estado, verificar, aplicar, reverter, automatico\n");
            exit(1);
    }
} catch (Throwable $e) {
    $m = $e instanceof NavalActualizacaoErro || $e instanceof NavalErro ? $e->getMessage() : get_class($e) . ': ' . $e->getMessage();
    if (in_array($comando, ['aplicar', 'automatico'], true)) {
        naval_act_registar('erro', ['erro' => $m]);
        naval_act_avisar('actualização com erro', [$m]);
    }
    $falha($m);
}
