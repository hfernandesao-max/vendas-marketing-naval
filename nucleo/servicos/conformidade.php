<?php
/**
 * Conformidade (ISO/IEC 27001 e 27002, ISO/IEC 27701, ISO 22301, ISO 15489, Lei n.º 22/11): os controlos que o portal
 * cumpre sozinho, todos os dias, com prova para a auditoria. Corre na tarefa de 5 em 5 minutos (naval_periodicas),
 * uma vez por dia a partir de CONFORMIDADE_HORA (02:00):
 *
 *  1. Registos invioláveis (27001 8.15): verifica a cadeia SHA-256 de todos os registos, guarda o selo do dia e
 *     copia os ficheiros para REGISTOS_COPIA_PASTA (fora do servidor), quando definida.
 *  2. Contas sem uso (27001 5.18): contas activas sem entrada há CONTAS_DIAS_SEM_USO dias (90) desligam-se sozinhas
 *     (nunca o super administrador); as que vão desligar nos 14 dias seguintes aparecem na página.
 *  3. Revisão de acessos trimestral (27001 5.18): no primeiro dia de cada trimestre, a lista de quem tem acesso a
 *     quê vai ao super administrador, que a confirma na página Conformidade (fica o registo de quem e quando).
 *  4. Cópia diária dos dados privados (27001 8.13, 22301), cifrada com AES-256-GCM (COPIAS_CHAVE), guardada
 *     COPIAS_DIAS dias (14) e copiada para COPIAS_PASTA_EXTERNA; logo a seguir, teste de recuperação: a cópia é
 *     decifrada e lida de novo, cada ficheiro confere com o SHA-256 do manifesto, os JSON lêem-se e a cadeia dos
 *     registos verifica-se dentro da cópia.
 *  5. Retenção (27701, 15489, Lei 22/11): cada tipo de dado tem um prazo; o que passou do prazo é apagado e contado.
 *
 * Problemas (cadeia partida, cópia ou recuperação falhada) vão por correio a ACTUALIZACAO_EMAIL e ficam no registo.
 * Mascaramento dos dados pessoais na cópia do ensaio (27001 8.33): naval_conf_mascarar_pasta.
 */
defined('NAVAL_NUCLEO') || exit;

/** Pastas que não entram na cópia diária (temporárias, reconstruíveis ou com cópia própria). */
const NAVAL_CONF_SEM_COPIA = ['ensaio', 'sessoes', 'cache', 'limites', 'bloqueios-ficheiros', 'copias-dados', 'geoip', 'actualizacoes'];

/**
 * Prazos de retenção: id => [pasta (na pasta privada), padrão, chave do .env, dias por omissão, descrição].
 * Pastas com a data no nome (AAAA-MM-DD) contam pela data do nome; ficheiros, pela data da última alteração.
 */
const NAVAL_RETENCAO = [
    'registos' => ['registos', 'acessos-*.jsonl', 'RETENCAO_REGISTOS_DIAS', 1095, 'Registos de actividade e auditoria'],
    'desempenho' => ['registos', 'desempenho-*.jsonl', 'RETENCAO_DESEMPENHO_DIAS', 180, 'Registos de desempenho (pedidos lentos e erros)'],
    'carrinha' => ['carrinha', '*', 'RETENCAO_CARRINHA_DIAS', 1825, 'Conciliações das carrinhas'],
    'email' => ['email-saida', '*', 'RETENCAO_EMAIL_DIAS', 90, 'Cópias dos correios enviados'],
    'recuperacao' => ['recuperacao', '*', 'RETENCAO_RECUPERACAO_DIAS', 30, 'Pedidos de recuperação da palavra-passe'],
    'tentativas' => ['geoip-tentativas', '*', 'RETENCAO_TENTATIVAS_DIAS', 90, 'Tentativas de entrada por país'],
    'operacao' => ['operacao', '*', 'RETENCAO_OPERACAO_DIAS', 90, 'Marcas da rotina diária'],
    'resumo' => ['resumo', '*', 'RETENCAO_RESUMO_DIAS', 365, 'Resumos diários enviados'],
    'conformidade' => ['conformidade', 'dia-*.json', 'RETENCAO_CONFORMIDADE_DIAS', 1095, 'Prova diária da conformidade'],
];

function naval_conf_pasta(): string
{
    return naval_pasta('conformidade');
}

// ── Entradas (para as contas sem uso) ────────────────────────────────────────────────────────────

/** Regista a última entrada de cada conta (chamado ao abrir a sessão). */
function naval_conf_marcar_entrada(string $login): void
{
    try {
        naval_exclusivo('conformidade-entradas', function () use ($login) {
            $f = naval_conf_pasta() . '/entradas.json';
            $d = naval_json_ler($f);
            $d[$login] = date('Y-m-d H:i:s');
            naval_json_gravar($f, $d);
        });
    } catch (Throwable $e) {
        error_log('[conformidade] ' . $e->getMessage());
    }
}

/**
 * Contas sem uso: [desligadas => [...], a_desligar => [...]]. Última entrada = a registada; sem ela, a data de criação;
 * sem esta, o primeiro dia em que a verificação viu a conta. Nunca o super administrador.
 */
function naval_conf_contas_sem_uso(int $agora, bool $aplicar = true): array
{
    $dias = max(30, (int) naval_config('CONTAS_DIAS_SEM_USO', '90'));
    $fEnt = naval_conf_pasta() . '/entradas.json';
    $fVis = naval_conf_pasta() . '/vistas.json';
    $out = ['dias' => $dias, 'desligadas' => [], 'a_desligar' => []];
    naval_exclusivo('utilizadores', function () use ($agora, $aplicar, $dias, $fEnt, $fVis, &$out) {
        $us = naval_utilizadores();
        $ent = naval_json_ler($fEnt);
        $vis = naval_json_ler($fVis);
        $mudou = false;
        foreach ($us as $login => $u) {
            if (empty($u['activo']) || ($u['perfil'] ?? '') === 'SADM' || !empty($u['compatibilidade'])) continue;
            if (!isset($vis[$login])) $vis[$login] = date('Y-m-d', $agora);
            $ult = (string) ($ent[$login] ?? ($u['criado'] ?? $vis[$login]));
            $t = strtotime($ult) ?: $agora;
            $semUso = (int) floor(($agora - $t) / 86400);
            $linha = ['login' => $login, 'nome' => (string) ($u['nome'] ?? $login), 'perfil' => (string) ($u['perfil'] ?? ''), 'ultima_entrada' => substr($ult, 0, 10), 'dias' => $semUso];
            if ($semUso >= $dias) {
                if ($aplicar) {
                    $us[$login]['activo'] = false;
                    $us[$login]['desactivado'] = ['em' => date('Y-m-d H:i:s', $agora), 'motivo' => 'sem_uso', 'dias' => $semUso];
                    $mudou = true;
                    naval_registar('conta_desligada_sem_uso', ['conta' => $login, 'dias' => $semUso], 'conformidade');
                }
                $out['desligadas'][] = $linha;
            } elseif ($semUso >= $dias - 14) {
                $out['a_desligar'][] = $linha;
            }
        }
        if ($mudou) naval_gravar_utilizadores($us);
        naval_json_gravar($fVis, $vis);
    });
    return $out;
}

// ── Revisão trimestral de acessos ──────────────────────────────────────────────────────────────────

function naval_conf_trimestre(int $t): string
{
    return date('Y', $t) . '-T' . (int) ceil((int) date('n', $t) / 3);
}

/** Lista de acessos para a revisão: cada conta activa, perfil, rotas, dois passos e última entrada. */
function naval_conf_lista_acessos(): array
{
    $ent = naval_json_ler(naval_conf_pasta() . '/entradas.json');
    $perfis = naval_perfis();
    $out = [];
    foreach (naval_utilizadores() as $login => $u) {
        if (empty($u['activo'])) continue;
        $out[] = ['login' => $login, 'nome' => (string) ($u['nome'] ?? $login), 'perfil' => (string) ($u['perfil'] ?? ''),
            'perfil_nome' => (string) ($perfis[$u['perfil'] ?? ''] ?? ''), 'rotas' => array_values((array) ($u['rotas'] ?? [])),
            'dois_passos' => !empty($u['mfa']), 'ultima_entrada' => substr((string) ($ent[$login] ?? ''), 0, 16),
            'valido_ate' => (string) ($u['valido_ate'] ?? '')];
    }
    return $out;
}

/** Abre a revisão do trimestre (uma vez) e avisa o super administrador. Devolve o trimestre, ou null. */
function naval_conf_revisao_abrir(int $agora): ?string
{
    $tri = naval_conf_trimestre($agora);
    $f = naval_conf_pasta() . '/revisao-' . $tri . '.json';
    if (is_file($f)) return null;
    $lista = naval_conf_lista_acessos();
    naval_json_gravar($f, ['trimestre' => $tri, 'aberta_em' => date('Y-m-d H:i:s', $agora), 'contas' => $lista, 'confirmada' => null]);
    naval_registar('revisao_acessos_aberta', ['trimestre' => $tri, 'contas' => count($lista)], 'conformidade');
    naval_conf_avisar('revisão de acessos do trimestre ' . $tri, [
        'Está aberta a revisão de acessos do trimestre ' . $tri . ' (ISO/IEC 27001, controlo 5.18): ' . count($lista) . ' contas activas.',
        'Confirme em Portal RTM › Administração › Conformidade quem deve manter cada acesso; o que retirar faz-se em Acessos.',
    ]);
    return $tri;
}

/** Confirma a revisão do trimestre (só o super administrador). */
function naval_conf_revisao_confirmar(string $tri, array $eu, string $notas): array
{
    if (!preg_match('/^\d{4}-T[1-4]$/', $tri)) throw new NavalErro('Trimestre inválido.', 400);
    $f = naval_conf_pasta() . '/revisao-' . $tri . '.json';
    $r = naval_json_ler($f);
    if (!$r) throw new NavalErro('Não há revisão aberta para ' . $tri . '.', 404);
    if (!empty($r['confirmada'])) throw new NavalErro('A revisão de ' . $tri . ' já foi confirmada.', 409);
    $r['confirmada'] = ['por' => (string) $eu['login'], 'em' => date('Y-m-d H:i:s'), 'notas' => mb_substr($notas, 0, 1000)];
    naval_json_gravar($f, $r);
    naval_registar('revisao_acessos_confirmada', ['trimestre' => $tri]);
    return $r;
}

// ── Cópia diária e teste de recuperação ────────────────────────────────────────────────────────────

/** Chave das cópias (32 bytes) a partir de COPIAS_CHAVE; '' = cópias sem cifra (assinalado na página). */
function naval_conf_chave(): string
{
    $k = naval_config('COPIAS_CHAVE');
    return $k === '' ? '' : hash('sha256', 'portal-rtm-copias|' . $k, true);
}

/** Lista os ficheiros da pasta privada que entram na cópia: [caminho relativo => caminho completo]. */
function naval_conf_ficheiros_copia(string $priv): array
{
    $out = [];
    $priv = rtrim(str_replace('\\', '/', $priv), '/');
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($priv, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!$f->isFile()) continue;
        $rel = substr(str_replace('\\', '/', $f->getPathname()), strlen($priv) + 1);
        if (in_array(explode('/', $rel)[0], NAVAL_CONF_SEM_COPIA, true)) continue;
        $out[$rel] = $f->getPathname();
    }
    ksort($out);
    return $out;
}

/** Faz a cópia: pacote JSON comprimido com manifesto SHA-256, cifrado com AES-256-GCM. Devolve o resumo. */
function naval_conf_copiar(int $agora): array
{
    $priv = naval_pasta_privada();
    $dir = naval_pasta('copias-dados');
    // fotografia coerente: a lista e o conteúdo lêem-se com os registos parados (a cadeia não muda a meio)
    $pacote = naval_exclusivo('registos-cadeia', function () use ($priv) {
        $fich = [];
        $man = [];
        foreach (naval_conf_ficheiros_copia($priv) as $rel => $abs) {
            $c = @file_get_contents($abs);
            if ($c === false) continue;
            $fich[$rel] = base64_encode($c);
            $man[$rel] = hash('sha256', $c);
        }
        return ['formato' => 1, 'criada_em' => date('Y-m-d H:i:s'), 'servidor' => (string) gethostname(), 'manifesto' => $man, 'ficheiros' => $fich];
    });
    $bruto = (string) gzencode((string) json_encode($pacote, JSON_UNESCAPED_SLASHES), 6);
    $chave = naval_conf_chave();
    if ($chave !== '') {
        $iv = random_bytes(12);
        $tag = '';
        $ct = (string) openssl_encrypt($bruto, 'aes-256-gcm', $chave, OPENSSL_RAW_DATA, $iv, $tag);
        $conteudo = 'NAVALC1' . $iv . $tag . $ct;
    } else {
        $conteudo = 'NAVALC0' . $bruto;
    }
    $nome = 'dados-' . date('Ymd-His', $agora) . '.copia';
    if (file_put_contents($dir . '/' . $nome, $conteudo) === false) throw new RuntimeException('Não foi possível gravar a cópia.');
    // retenção das cópias
    $dias = max(3, (int) naval_config('COPIAS_DIAS', '14'));
    foreach (glob($dir . '/dados-*.copia') ?: [] as $f) {
        if (filemtime($f) < $agora - $dias * 86400) @unlink($f);
    }
    // fora do servidor (pasta de rede ou disco externo)
    $ext = rtrim(naval_config('COPIAS_PASTA_EXTERNA'), '/\\');
    $externa = null;
    if ($ext !== '') $externa = @copy($dir . '/' . $nome, $ext . '/' . $nome) && hash_file('sha256', $ext . '/' . $nome) === hash_file('sha256', $dir . '/' . $nome);
    return ['ficheiro' => $nome, 'bytes' => strlen($conteudo), 'ficheiros' => count($pacote['manifesto']), 'cifrada' => $chave !== '', 'externa' => $externa];
}

/** Lê uma cópia (decifra e descomprime). Lança excepção se estiver danificada ou a chave não servir. */
function naval_conf_ler_copia(string $f): array
{
    $c = (string) file_get_contents($f);
    $tipo = substr($c, 0, 7);
    if ($tipo === 'NAVALC1') {
        $chave = naval_conf_chave();
        if ($chave === '') throw new RuntimeException('Cópia cifrada e COPIAS_CHAVE não definida.');
        $bruto = openssl_decrypt(substr($c, 35), 'aes-256-gcm', $chave, OPENSSL_RAW_DATA, substr($c, 7, 12), substr($c, 19, 16));
        if ($bruto === false) throw new RuntimeException('A cópia não se decifra (danificada ou chave errada).');
    } elseif ($tipo === 'NAVALC0') {
        $bruto = substr($c, 7);
    } else {
        throw new RuntimeException('Formato de cópia desconhecido.');
    }
    $j = json_decode((string) @gzdecode($bruto), true);
    if (!is_array($j) || !isset($j['manifesto'], $j['ficheiros'])) throw new RuntimeException('Cópia ilegível.');
    return $j;
}

/**
 * Teste de recuperação: repõe a cópia numa pasta temporária, confere cada ficheiro com o manifesto, lê os JSON e
 * verifica a cadeia dos registos dentro da cópia. Nada toca nos dados verdadeiros.
 */
function naval_conf_testar_recuperacao(string $f): array
{
    $t0 = microtime(true);
    $j = naval_conf_ler_copia($f);
    $tmp = sys_get_temp_dir() . '/portal-rtm-recuperacao-' . bin2hex(random_bytes(4));
    $falhas = [];
    try {
        foreach ($j['manifesto'] as $rel => $sha) {
            if (!preg_match('#^[\w.\-/]+$#u', $rel) || strpos($rel, '..') !== false) {
                $falhas[] = 'nome inválido: ' . $rel;
                continue;
            }
            $c = base64_decode((string) ($j['ficheiros'][$rel] ?? ''), true);
            if ($c === false || !hash_equals((string) $sha, hash('sha256', $c))) {
                $falhas[] = 'não confere: ' . $rel;
                continue;
            }
            $destino = $tmp . '/' . $rel;
            if (!is_dir(dirname($destino))) @mkdir(dirname($destino), 0700, true);
            file_put_contents($destino, $c);
            if (substr($rel, -5) === '.json' && json_decode($c, true) === null && trim($c) !== 'null') $falhas[] = 'JSON ilegível: ' . $rel;
        }
        $reg = is_dir($tmp . '/registos') ? naval_registos_verificar($tmp . '/registos', $tmp . '/registos/cadeia.json') : ['ok' => true, 'encadeadas' => 0];
        if (!$reg['ok']) $falhas[] = 'cadeia dos registos partida dentro da cópia';
    } finally {
        if (is_dir($tmp)) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $x) $x->isDir() ? @rmdir($x->getPathname()) : @unlink($x->getPathname());
            @rmdir($tmp);
        }
    }
    return ['ok' => !$falhas, 'ficheiros' => count($j['manifesto']), 'registos_encadeados' => $reg['encadeadas'] ?? 0,
        'falhas' => array_slice($falhas, 0, 20), 'seg' => round(microtime(true) - $t0, 2)];
}

// ── Retenção ──────────────────────────────────────────────────────────────────────────────────────

/** Apaga o que passou do prazo de cada tipo de dado. Devolve [id => apagados]. */
function naval_conf_retencao(int $agora): array
{
    $priv = naval_pasta_privada();
    $out = [];
    foreach (NAVAL_RETENCAO as $id => [$pasta, $padrao, $chave, $omissao]) {
        $dias = max(1, (int) naval_config($chave, (string) $omissao));
        $limite = $agora - $dias * 86400;
        $n = 0;
        foreach (glob($priv . '/' . $pasta . '/' . $padrao) ?: [] as $f) {
            if (basename($f) === 'cadeia.json' || basename($f) === 'entradas.json' || basename($f) === 'vistas.json') continue;
            $t = preg_match('/(\d{4}-\d{2}-\d{2})/', basename($f), $m) ? (int) strtotime($m[1] . ' 23:59:59') : (int) @filemtime($f);
            if ($t === 0 || $t >= $limite) continue;
            if (is_dir($f)) {
                $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($f, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
                foreach ($it as $x) $x->isDir() ? @rmdir($x->getPathname()) : @unlink($x->getPathname());
                @rmdir($f);
            } else {
                @unlink($f);
            }
            $n++;
        }
        if ($n) naval_registar('retencao_eliminados', ['tipo' => $id, 'apagados' => $n, 'dias' => $dias], 'conformidade');
        $out[$id] = $n;
    }
    return $out;
}

/** Regras de retenção para a página: tipo, descrição e prazo em dias. */
function naval_conf_regras_retencao(): array
{
    $out = [];
    foreach (NAVAL_RETENCAO as $id => [, , $chave, $omissao, $desc]) $out[] = ['id' => $id, 'descricao' => $desc, 'dias' => max(1, (int) naval_config($chave, (string) $omissao))];
    return $out;
}

// ── Ensaio: dados pessoais mascarados ──────────────────────────────────────────────────────────────

/** Campos com dados pessoais que se mascaram na cópia do ensaio. */
const NAVAL_CONF_CAMPOS_PESSOAIS = '/^(nif|nif_cliente|cliente_nif|contribuinte|entity_taxnum|telefone|telemovel|telem|tel|phone|contacto|email|e_mail|morada|endereco)$/i';

/**
 * Mascara os dados pessoais dos JSON de uma pasta (a cópia do ensaio): NIF, telefones, e-mails e moradas passam a um
 * pseudónimo fixo (as ligações entre ficheiros mantêm-se, o valor real não se recupera). Os e-mails das contas dos
 * administradores de sistema ficam (o ensaio só lhes envia correio a eles). Devolve o número de valores mascarados.
 */
function naval_conf_mascarar_pasta(string $dir): int
{
    if (!is_dir($dir)) return 0;
    $sal = bin2hex(random_bytes(16));
    $n = 0;
    $masc = function ($k, $v) use ($sal, &$n) {
        if (!is_string($v) || trim($v) === '') return $v;
        $n++;
        $h = substr(hash('sha256', $sal . '|' . $v), 0, 10);
        if (preg_match('/mail/i', (string) $k)) return 'm-' . $h . '@ensaio.invalid';
        if (preg_match('/tel|phone|contacto/i', (string) $k)) return '+000' . substr(preg_replace('/\D/', '', hexdec(substr($h, 0, 7)) . ''), 0, 9);
        return 'MASC-' . strtoupper($h);
    };
    $andar = function ($x, bool $admin = false) use (&$andar, $masc) {
        if (!is_array($x)) return $x;
        foreach ($x as $k => $v) {
            if (is_array($v)) {
                $x[$k] = $andar($v, in_array((string) ($v['perfil'] ?? ''), NAVAL_PERFIS_ADMIN_SISTEMA, true));
            } elseif (is_string($k) && preg_match(NAVAL_CONF_CAMPOS_PESSOAIS, $k) && !($admin && preg_match('/mail/i', $k))) {
                $x[$k] = $masc($k, $v);
            }
        }
        return $x;
    };
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        $p = $f->getPathname();
        if (!$f->isFile() || substr($p, -5) !== '.json') continue;
        $j = json_decode((string) file_get_contents($p), true);
        if (!is_array($j)) continue;
        $antes = $n;
        $j = $andar($j);
        if ($n !== $antes) file_put_contents($p, json_encode($j, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    }
    return $n;
}

// ── Rotina diária ────────────────────────────────────────────────────────────────────────────────

function naval_conf_avisar(string $assunto, array $linhas): void
{
    if (!function_exists('naval_act_avisar')) naval_servico('actualizacao');
    naval_act_avisar($assunto, $linhas);
}

/** Último estado da rotina (para a página). */
function naval_conf_estado(): array
{
    return naval_json_ler(naval_conf_pasta() . '/estado.json');
}

/** Rotina diária da conformidade (uma vez por dia, a partir de CONFORMIDADE_HORA). Devolve o resumo, ou null. */
function naval_conf_noite(int $agora): ?array
{
    if (naval_ensaio() || naval_config('CONFORMIDADE_ACTIVA', '1') === '0') return null;
    if (date('H:i', $agora) < naval_config('CONFORMIDADE_HORA', '02:00')) return null;
    $marca = naval_conf_pasta() . '/dia-' . date('Y-m-d', $agora) . '.json';
    if (is_file($marca)) return null;
    naval_json_gravar($marca, ['inicio' => date('Y-m-d H:i:s')]);
    $r = ['dia' => date('Y-m-d', $agora), 'inicio' => date('Y-m-d H:i:s')];
    $problemas = [];
    $passo = function (string $k, callable $f) use (&$r, &$problemas) {
        try {
            $r[$k] = $f();
        } catch (Throwable $e) {
            $r[$k] = ['ok' => false, 'erro' => $e->getMessage()];
            $problemas[] = $k . ': ' . $e->getMessage();
        }
    };
    $passo('registos', function () use ($agora, &$problemas) {
        $v = naval_registos_verificar();
        if (!$v['ok']) {
            $problemas[] = 'cadeia dos registos partida (' . count($v['falhas']) . ' falhas, a primeira em ' . implode(' linha ', array_slice($v['falhas'][0], 0, 2)) . ')';
            naval_registar('registos_adulterados', ['falhas' => array_slice($v['falhas'], 0, 5)], 'conformidade');
        }
        naval_jsonl_acrescentar(naval_conf_pasta() . '/selos.jsonl', ['dia' => date('Y-m-d', $agora), 'ultimo' => $v['ultimo'], 'linhas' => $v['linhas'], 'ok' => $v['ok']]);
        $ext = rtrim(naval_config('REGISTOS_COPIA_PASTA'), '/\\');
        if ($ext !== '') {
            foreach (array_merge(glob(naval_registos_pasta() . '/acessos-*.jsonl') ?: [], [naval_registos_cadeia_ficheiro(), naval_conf_pasta() . '/selos.jsonl']) as $f) {
                if (is_file($f)) @copy($f, $ext . '/' . basename($f));
            }
        }
        return ['ok' => $v['ok'], 'linhas' => $v['linhas'], 'encadeadas' => $v['encadeadas'], 'selo' => $v['ultimo'], 'falhas' => count($v['falhas'])];
    });
    $passo('contas', function () use ($agora) {
        $c = naval_conf_contas_sem_uso($agora);
        return ['ok' => true, 'dias' => $c['dias'], 'desligadas' => $c['desligadas'], 'a_desligar' => $c['a_desligar']];
    });
    $passo('revisao', function () use ($agora) {
        return ['ok' => true, 'aberta' => naval_conf_revisao_abrir($agora)];
    });
    $passo('copia', function () use ($agora, &$problemas) {
        $c = naval_conf_copiar($agora);
        $t = naval_conf_testar_recuperacao(naval_pasta('copias-dados') . '/' . $c['ficheiro']);
        if (!$t['ok']) $problemas[] = 'teste de recuperação da cópia falhou: ' . implode('; ', $t['falhas']);
        if ($c['externa'] === false) $problemas[] = 'a cópia não chegou à pasta externa (COPIAS_PASTA_EXTERNA)';
        return ['ok' => $t['ok'], 'copia' => $c, 'recuperacao' => $t];
    });
    $passo('suporte', function () use ($agora) {
        naval_servico('suporte');
        return ['ok' => true, 'fechados' => naval_sup_fechar_antigos($agora)];
    });
    $passo('retencao', function () use ($agora) {
        return ['ok' => true, 'apagados' => naval_conf_retencao($agora)];
    });
    $r['fim'] = date('Y-m-d H:i:s');
    $r['problemas'] = $problemas;
    naval_json_gravar(naval_conf_pasta() . '/estado.json', $r);
    naval_json_gravar($marca, ['inicio' => $r['inicio'], 'fim' => $r['fim'], 'problemas' => $problemas]);
    if ($problemas) naval_conf_avisar('problemas na verificação diária da conformidade', array_merge(['A verificação diária encontrou:'], $problemas));
    return ['problemas' => count($problemas)];
}

/** Vista da página Administração › Conformidade. */
function naval_conf_vista(): array
{
    $revs = [];
    foreach (glob(naval_conf_pasta() . '/revisao-*.json') ?: [] as $f) {
        $j = naval_json_ler($f);
        $revs[] = ['trimestre' => $j['trimestre'] ?? '', 'aberta_em' => $j['aberta_em'] ?? '', 'contas' => count($j['contas'] ?? []), 'confirmada' => $j['confirmada'] ?? null];
    }
    usort($revs, function ($a, $b) { return strcmp($b['trimestre'], $a['trimestre']); });
    $copias = [];
    foreach (glob(naval_pasta('copias-dados') . '/dados-*.copia') ?: [] as $f) $copias[] = ['ficheiro' => basename($f), 'bytes' => (int) filesize($f), 'em' => date('Y-m-d H:i', (int) filemtime($f))];
    rsort($copias);
    return [
        'estado' => naval_conf_estado(),
        'revisoes' => array_slice($revs, 0, 8),
        'revisao_actual' => naval_conf_trimestre(time()),
        'copias' => $copias,
        'cifra' => naval_conf_chave() !== '',
        'copia_externa' => naval_config('COPIAS_PASTA_EXTERNA') !== '',
        'registos_externos' => naval_config('REGISTOS_COPIA_PASTA') !== '',
        'retencao' => naval_conf_regras_retencao(),
        'contas_dias' => max(30, (int) naval_config('CONTAS_DIAS_SEM_USO', '90')),
    ];
}
