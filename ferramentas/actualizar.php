<?php
/**
 * Actualizações automáticas do portal (na pasta do portal, no servidor):
 *
 *   php ferramentas/actualizar.php configurar        token do GitHub (só leitura) e correio para os avisos; uma vez
 *   php ferramentas/actualizar.php tarefa [HH:MM]    cria as tarefas agendadas do Windows: a nocturna (02:15 por omissão)
 *                                                     e a de 5 em 5 minutos («instalar já» e reposições pedidas no portal)
 *   php ferramentas/actualizar.php apache            inclui no httpd.conf a configuração gerida pelo portal (uma vez)
 *   php ferramentas/actualizar.php certificados      validade dos certificados HTTPS do portal (também todas as noites)
 *   php ferramentas/actualizar.php estado            versão instalada e versão mais recente publicada
 *   php ferramentas/actualizar.php verificar         mostra o que mudaria, sem alterar nada
 *   php ferramentas/actualizar.php aplicar           aplica já a versão mais recente
 *         [--pacote=ficheiro.zip] [--forcar] [--sem-http]
 *   php ferramentas/actualizar.php reverter          repõe a versão anterior (última cópia de segurança)
 *   php ferramentas/actualizar.php automatico        o que a tarefa nocturna corre (respeita ACTUALIZACAO_AUTOMATICA)
 *   php ferramentas/actualizar.php urgente           o que a tarefa de 5 em 5 minutos corre (pedidos do portal; às 02:45 aprova
 *                                                    as versões não decididas e às 03:00 instala as aprovadas)
 *
 * Com ACTUALIZACAO_EXIGE_APROVACAO=1 (omissão) só se instalam versões aprovadas pelo super administrador na página
 * «Actualizações» do portal; «aplicar --pacote=» (instalação manual de um pacote) não depende da aprovação.
 *
 * Como funciona e o que nunca toca: nucleo/servicos/actualizacao.php. Os segredos nunca são mostrados no ecrã.
 * Saída: 0 = bem; 1 = falha ou versão revertida; 2 = parado por ficheiros alterados no servidor.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . '/nucleo/arranque.php';
naval_servico('actualizacao');

$args = array_slice($argv, 1);
$comando = $args[0] ?? 'estado';
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
            echo "Recomendado: GitHub App instalada só no repositório " . naval_config('ACTUALIZACAO_REPOSITORIO', NAVAL_ACT_REPOSITORIO)
                . " com «Contents: Read-only» (não expira).\nDeixe o ID vazio para usar um código de acesso pessoal (expira).\n";
            $app = $pergunta('ID da GitHub App («App ID»)', 'ACTUALIZACAO_CONFIG_APP_ID');
            $chaves = ['ACTUALIZACAO_AUTOMATICA' => '1'];
            if ($app !== '') {
                if (!preg_match('/^\d{1,12}$/', $app)) $falha('O «App ID» é um número.');
                $pem = $pergunta('Caminho do ficheiro .pem da chave privada da App', 'ACTUALIZACAO_CONFIG_APP_CHAVE');
                $conteudo = is_file($pem) ? (string) file_get_contents($pem) : '';
                if (!$conteudo || !function_exists('openssl_pkey_get_private') || !openssl_pkey_get_private($conteudo)) $falha('Ficheiro de chave privada inválido: ' . $pem);
                $destino = naval_act_pasta() . '/github-app.pem';
                if (file_put_contents($destino, $conteudo, LOCK_EX) === false) $falha('Não foi possível guardar a chave na pasta privada.');
                // só a conta SYSTEM e os administradores lêem a chave (Windows)
                if (DIRECTORY_SEPARATOR === '\\') exec('icacls ' . escapeshellarg($destino) . ' /inheritance:r /grant:r *S-1-5-18:R *S-1-5-32-544:F 2>&1');
                else @chmod($destino, 0600);
                echo "  ok     chave privada guardada na pasta privada (pode apagar o ficheiro original)\n";
                $chaves += ['ACTUALIZACAO_GITHUB_APP_ID' => $app, 'ACTUALIZACAO_GITHUB_TOKEN' => ''];
            } else {
                $token = $pergunta('Código de acesso pessoal («Fine-grained token», só «Contents: Read-only»)', 'ACTUALIZACAO_CONFIG_TOKEN');
                if (!preg_match('/^(github_pat_|ghp_)[A-Za-z0-9_]{20,}$/', $token)) $falha('Isto não parece um código de acesso do GitHub (começa por github_pat_).');
                $chaves += ['ACTUALIZACAO_GITHUB_TOKEN' => $token, 'ACTUALIZACAO_GITHUB_APP_ID' => ''];
            }
            $email = $pergunta('Correio electrónico para os avisos (vazio = sem avisos; vários separados por vírgula)', 'ACTUALIZACAO_CONFIG_EMAIL');
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
            $tr = '/TR "\\"' . PHP_BINARY . '\\" \\"' . __FILE__ . '\\" ';
            foreach ([['Portal RTM - Actualizacao', '/SC DAILY /ST ' . $hora, 'automatico', 'todos os dias às ' . $hora],
                      ['Portal RTM - Actualizacao urgente', '/SC MINUTE /MO 5', 'urgente', 'de 5 em 5 minutos (pedidos do portal)']] as [$nome, $quando, $cmdAct, $texto]) {
                passthru('schtasks /Create /F /TN "' . $nome . '" ' . $quando . ' /RU SYSTEM /RL HIGHEST ' . $tr . $cmdAct . '"', $r);
                if ($r !== 0) $falha('Não foi possível criar a tarefa (abrir a linha de comandos como administrador).');
                echo "  ok     tarefa «{$nome}» {$texto} (conta SYSTEM)\n";
            }
            exit(0);

        case 'apache':
            // uma vez: inclui no httpd.conf a configuração gerida pelo portal (ferramentas/apache/portal-rtm.conf)
            $r = naval_act_apache_incluir(naval_raiz());
            echo '  ok     configuração do portal no Apache: ' . $r . "\n";
            if ($r !== 'já incluída') {
                $aviso = naval_act_apache_reiniciar();
                echo $aviso === '' ? "  ok     Apache reiniciado\n" : '  aviso  ' . $aviso . "\n";
            }
            exit(0);

        case 'certificados':
            foreach (naval_act_vigiar_certificados() as $c) {
                echo '  ' . ($c['dias'] === null ? 'FALHA  ' . $c['endereco'] . ': ' . $c['erro'] : str_pad($c['dias'] < 30 ? 'aviso' : 'ok', 7) . $c['endereco'] . ': expira em ' . $c['expira'] . ' (' . $c['dias'] . ' dias, ' . $c['emitente'] . ')') . "\n";
            }
            exit(0);

        case 'estado':
            $e = naval_act_estado();
            echo 'Instalada:  ' . ($e['versao'] !== '' ? $e['versao'] . ' (' . substr((string) $e['commit'], 0, 7) . ', ' . $e['aplicada_em'] . ')' : 'ainda nenhuma actualização automática') . "\n";
            if (!empty($e['recusada'])) echo 'Recusada:   ' . $e['recusada'] . " (revertida; só volta com «aplicar --forcar»)\n";
            echo 'Automática: ' . (naval_config('ACTUALIZACAO_AUTOMATICA') === '1' ? 'ligada' : 'desligada (ACTUALIZACAO_AUTOMATICA=1 para ligar)') . "\n";
            $u = naval_act_ultima_versao();
            echo 'Publicada:  ' . $u['versao'] . ' (' . substr($u['commit'], 0, 7) . ")\n";
            if ($u['dias_token'] !== null) echo 'Código:     o código de acesso expira dentro de ' . $u['dias_token'] . " dias\n";
            echo "  ok     ligação ao GitHub a funcionar\n";
            exit(0);

        case 'verificar':
        case 'aplicar':
        case 'automatico':
        case 'urgente':
            if ($comando === 'urgente') {
                // tarefas periódicas do portal (resumo diário às 07:00, agregados de análise de madrugada): correm de 5 em 5
                // minutos com esta tarefa agendada, mesmo com as actualizações automáticas desligadas
                try {
                    if (is_file(naval_raiz() . '/nucleo/servicos/resumo.php')) {
                        naval_servico('resumo');
                        naval_periodicas();   // em silêncio: cada tarefa regista o que fez (a tarefa de 5 em 5 minutos não escreve sem pedidos)
                    }
                } catch (Throwable $e) {
                    error_log('[periódicas] ' . $e->getMessage());
                }
            }
            $auto = in_array($comando, ['automatico', 'urgente'], true);
            if ($auto && naval_config('ACTUALIZACAO_AUTOMATICA') !== '1') {
                echo "Actualizações automáticas desligadas (ACTUALIZACAO_AUTOMATICA).\n";
                exit(0);
            }
            if ($comando === 'automatico') {
                try {
                    naval_act_vigiar_certificados();   // validade dos certificados HTTPS (avisos a 30, 14, 7, 3 e 1 dias)
                } catch (Throwable $e) {
                    naval_act_registar('certificados_erro', ['erro' => $e->getMessage()]);
                }
            }
            if ($comando === 'urgente') {
                $r = naval_act_urgente();
                // sem pedidos do portal: janela nocturna (aprovação automática às 02:45, instalação às 03:00)
                if ($r === null) $r = naval_act_noite();
                // fora disso: a versão nova mais recente instala-se no ensaio (uma vez por versão)
                if ($r === null) {
                    try {
                        naval_act_ensaio_ciclo();
                    } catch (Throwable $e) {
                        naval_act_registar('ensaio_erro', ['erro' => $e->getMessage()]);
                    }
                    exit(0);
                }
                if ($r['resultado'] === 'revertida_pedido') {
                    echo '  ok     versão anterior reposta a pedido do portal' . ($r['versao'] !== '' ? ' (' . $r['versao'] . ')' : '') . "\n";
                    naval_act_avisar('versão anterior reposta', ['A pedido feito no portal, foi reposta a versão anterior' . ($r['versao'] !== '' ? ' (' . $r['versao'] . ')' : '') . '.']);
                    exit(0);
                }
                if ($r['resultado'] === 'erro') {
                    naval_act_avisar('reposição com erros', ['A reposição pedida no portal teve erros: ' . implode('; ', $r['erros'])]);
                    $falha('reposição com erros: ' . implode('; ', $r['erros']));
                }
            } else {
            if ($comando === 'automatico' && naval_act_exige_aprovacao() && !$opcao('pacote') && naval_act_horas_noite()['instalar'] !== '') {
                // a instalação das aprovadas faz-se às 03:00 na tarefa de 5 em 5 minutos (naval_act_noite): aqui só se avisa
                try {
                    naval_act_avisar_pendentes(naval_act_versoes(), naval_act_estado());
                } catch (Throwable $e) {
                    naval_act_registar('pendentes_erro', ['erro' => $e->getMessage()]);
                }
                $h = naval_act_horas_noite();
                echo '  ok     versões aprovadas instaladas às ' . $h['instalar'] . ($h['aprovar'] !== '' ? ' (as não decididas são aprovadas às ' . $h['aprovar'] . ')' : '') . "\n";
                exit(0);
            }
            $r = naval_act_aplicar([
                'pacote' => is_string($opcao('pacote')) ? $opcao('pacote') : null,
                'ensaio' => $comando === 'verificar',
                'forcar' => $opcao('forcar') === true,
                'sem_http' => $opcao('sem-http') === true,
                'sem_ziparchive' => $opcao('sem-ziparchive') === true,
                'avisar_pendentes' => $comando === 'automatico',
            ]);
            }
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
