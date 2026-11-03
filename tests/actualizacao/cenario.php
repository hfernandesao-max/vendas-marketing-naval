<?php
/**
 * Cenário completo do actualizador (ferramentas/actualizar.php), em Linux e em Windows:
 *   php tests/actualizacao/cenario.php
 * Monta um «servidor» numa pasta temporária a partir do pacote do repositório (scripts/construir-pacote.php), com o
 * portal servido pelo PHP (php -S) e uma API do GitHub simulada, e verifica: versão igual sem alterações, versão nova
 * aplicada e verificada, ficheiro alterado à mão (paragem e --forcar), versão com erro (reposta sozinha e recusada
 * nas noites seguintes), erro só visível por HTTP, erro de sintaxe, pacotes adulterados ou com ficheiros protegidos,
 * migrações da base de dados (aplicada e falhada), descarga pelo GitHub (token só na API), Phar sem extensão zip,
 * bloqueio de execuções simultâneas, reversão manual e avisos por correio electrónico.
 */
if (PHP_SAPI !== 'cli') exit;
$repo = dirname(__DIR__, 2);
$win = DIRECTORY_SEPARATOR === '\\';
$tmp = str_replace('\\', '/', sys_get_temp_dir()) . '/naval-act-' . getmypid();
$srv = $tmp . '/servidor';
$priv = $tmp . '/privado';
$pac = $tmp . '/pacotes';
$gh = $tmp . '/github';
foreach ([$srv, $priv, $pac, $gh] as $d) @mkdir($d, 0770, true);

$falhas = 0;
$n = 0;
function caso(string $nome, callable $f): void
{
    global $falhas, $n;
    $n++;
    try {
        $f();
        echo "  ok    $nome\n";
    } catch (Throwable $e) {
        $falhas++;
        echo "  FALHA $nome\n        " . $e->getMessage() . ' (linha ' . $e->getLine() . ")\n";
    }
}
function confirma(bool $v, string $m): void
{
    if (!$v) throw new RuntimeException($m);
}

// ── Pacote de partida (o do repositório) e «servidor» instalado à mão a partir dele ──
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($repo . '/scripts/construir-pacote.php') . ' ' . escapeshellarg($pac) . ' v1 aaaaaaa 2>&1', $o, $r);
if ($r !== 0) {
    echo implode("\n", $o), "\n";
    exit(1);
}
$z = new ZipArchive();
$z->open($pac . '/portal-rtm-v1.zip');
$base = json_decode((string) $z->getFromName('manifesto.json'), true);
for ($i = 0; $i < $z->numFiles; $i++) {
    $nome = (string) $z->getNameIndex($i);
    if (strpos($nome, 'ficheiros/') !== 0) continue;
    $f = $srv . '/' . substr($nome, 10);
    @mkdir(dirname($f), 0775, true);
    file_put_contents($f, (string) $z->getFromIndex($i));
}
$z->close();
// dados reais do servidor que nenhuma actualização pode tocar
@mkdir($srv . '/reports/data', 0775, true);
file_put_contents($srv . '/reports/data/vendas.json', '{"real":true}');
$dadosReais = hash_file('sha256', $srv . '/reports/data/vendas.json');

$bd = $tmp . '/atv.sqlite';
(new PDO('sqlite:' . $bd))->exec((string) file_get_contents($repo . '/tests/dados/equipamentos-sqlite.sql'));

$portaWeb = 19100 + getmypid() % 400;
$portaGh = $portaWeb + 500;
$env = $tmp . '/.env';
file_put_contents($env, implode("\n", [
    'DADOS_PRIVADOS=' . $priv, 'JWT_SEGREDO=' . bin2hex(random_bytes(32)), 'ATV_DB_DSN=sqlite:' . $bd, 'EMAIL_MODO=ficheiro',
    'ACTUALIZACAO_EMAIL=ti@naval.co.ao', 'ACTUALIZACAO_AUTOMATICA=1', 'ACTUALIZACAO_EXIGE_APROVACAO=0', 'ACTUALIZACAO_REQUISITOS_IGNORAR=pdo_odbc|pdo_sqlsrv,gd', 'ACTUALIZACAO_URL_VERIFICACAO=http://127.0.0.1:' . $portaWeb,
    'ACTUALIZACAO_GITHUB_API=http://127.0.0.1:' . $portaGh, 'ACTUALIZACAO_REPOSITORIO=naval/portal',
    'ACTUALIZACAO_GITHUB_TOKEN=github_pat_teste_0123456789abcdefghij',
    // a janela nocturna liga-se só no caso dela (a hora real do teste não pode mudar os outros)
    'ACTUALIZACAO_APROVACAO_AUTOMATICA=0', 'ACTUALIZACAO_INSTALACAO_NOCTURNA=0',
]) . "\n");
putenv('NAVAL_ENV=' . $env);
putenv('GITHUB_SIMULADO_DIR=' . $gh);

/** Processo em segundo plano (servidores de teste), sem passar pela linha de comandos do sistema. */
function arrancar(string $cmd)
{
    global $win;
    $nulo = $win ? 'NUL' : '/dev/null';
    $p = proc_open(($win ? '' : 'exec ') . $cmd, [1 => ['file', $nulo, 'w'], 2 => ['file', $nulo, 'w']], $t, null, null, ['bypass_shell' => true]);
    return $p;
}
$web = arrancar(escapeshellarg(PHP_BINARY) . ' -d display_errors=0 -S 127.0.0.1:' . $portaWeb . ' -t ' . escapeshellarg($srv));
$api = arrancar(escapeshellarg(PHP_BINARY) . ' -S 127.0.0.1:' . $portaGh . ' ' . escapeshellarg($repo . '/tests/dados/github-simulado.php'));
foreach ([$portaWeb, $portaGh] as $p) for ($i = 0; $i < 80 && !@fsockopen('127.0.0.1', $p); $i++) usleep(100000);

/** Corre o actualizador do servidor; devolve [código de saída, texto]. */
function act(string $args): array
{
    global $srv;
    $o = [];
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($srv . '/ferramentas/actualizar.php') . ' ' . $args . ' 2>&1', $o, $r);
    return [$r, implode("\n", $o)];
}
/** Pacote derivado do de partida: $mudar = [caminho => conteúdo] (null retira do pacote); $extra = entradas em bruto. */
function pacote(string $versao, array $mudar, array $extra = [], array $manifestoExtra = []): string
{
    global $pac, $base;
    $m = $base;
    $m['versao'] = $versao;
    $conteudos = [];
    foreach ($mudar as $c => $t) {
        if ($t === null) {
            unset($m['ficheiros'][$c]);
            continue;
        }
        $conteudos[$c] = $t;
        $m['ficheiros'][$c] = hash('sha256', $t);
    }
    $m = array_replace_recursive($m, $manifestoExtra);
    $f = $pac . '/portal-rtm-' . $versao . '.zip';
    @unlink($f);
    $z = new ZipArchive();
    $z->open($f, ZipArchive::CREATE);
    $z->addFromString('manifesto.json', (string) json_encode($m));
    $orig = new ZipArchive();
    $orig->open($pac . '/portal-rtm-v1.zip');
    foreach (array_keys($m['ficheiros']) as $c) {
        $z->addFromString('ficheiros/' . $c, $conteudos[$c] ?? (string) $orig->getFromName('ficheiros/' . $c));
    }
    $orig->close();
    foreach ($extra as $nome => $t) $z->addFromString($nome, $t);
    $z->close();
    file_put_contents($f . '.sha256', hash_file('sha256', $f) . '  ' . basename($f) . "\n");
    return $f;
}
function estado(): array
{
    global $priv;
    return json_decode((string) @file_get_contents($priv . '/actualizacoes/estado.json'), true) ?: [];
}
function h(string $c): ?string
{
    global $srv;
    return is_file($srv . '/' . $c) ? hash_file('sha256', $srv . '/' . $c) : null;
}
function emails(): array
{
    global $priv;
    return array_map(function ($f) { return json_decode((string) file_get_contents($f), true)['assunto'] ?? ''; }, glob($priv . '/email-saida/*.json') ?: []);
}
$texto = (string) file_get_contents($srv . '/nucleo/texto.php');
$util = (string) file_get_contents($srv . '/reports/js/util.js');

caso('versão igual à instalada: nada a copiar, estado gravado', function () use ($pac) {
    [$r, $t] = act('aplicar --pacote=' . escapeshellarg($pac . '/portal-rtm-v1.zip'));
    confirma($r === 0 && strpos($t, 'Nenhum ficheiro a alterar') !== false, $t);
    confirma((estado()['versao'] ?? '') === 'v1', 'estado sem a versão v1');
});

caso('versão nova: só o que mudou, cópia de segurança e verificação HTTP', function () use ($texto, $util, $srv, $dadosReais) {
    // inclui o próprio actualizador (no Windows, o ficheiro em execução fica bloqueado)
    $act = (string) file_get_contents($srv . '/ferramentas/actualizar.php');
    $f = pacote('v2', ['nucleo/texto.php' => $texto . "\n// v2\n", 'reports/js/util.js' => $util . "\n// v2\n", 'assets/novo-v2.txt' => 'novo', 'ferramentas/actualizar.php' => $act . "\n// v2\n"]);
    [$r, $t] = act('verificar --pacote=' . escapeshellarg($f));
    confirma($r === 0 && strpos($t, '3 a substituir, 1 novos') !== false && h('assets/novo-v2.txt') === null, 'ensaio alterou ou plano errado: ' . $t);
    [$r, $t] = act('aplicar --pacote=' . escapeshellarg($f));
    confirma($r === 0 && strpos($t, '(linha de comandos e HTTP)') !== false, $t);
    confirma(h('nucleo/texto.php') === hash('sha256', $texto . "\n// v2\n") && h('assets/novo-v2.txt') === hash('sha256', 'novo'), 'ficheiros não copiados');
    confirma(h('ferramentas/actualizar.php') === hash('sha256', $act . "\n// v2\n"), 'o actualizador não se actualizou a si próprio');
    confirma(hash_file('sha256', $srv . '/reports/data/vendas.json') === $dadosReais, 'dados reais alterados');
    confirma((estado()['versao'] ?? '') === 'v2', 'estado sem v2');
    confirma(in_array('Portal RTM · actualizado para v2', emails(), true), 'sem aviso por correio');
    confirma(!glob($srv . '/nucleo/*.actualizacao-*'), 'ficheiros temporários deixados');
});

caso('ficheiro alterado à mão no servidor: pára sem alterar nada; --forcar guarda-o na cópia', function () use ($texto, $util, $srv, $priv) {
    file_put_contents($srv . '/nucleo/texto.php', $texto . "\n// alteração feita no servidor\n");
    $f = pacote('v3', ['nucleo/texto.php' => $texto . "\n// v3\n", 'reports/js/util.js' => $util . "\n// v3\n"]);
    [$r, $t] = act('aplicar --pacote=' . escapeshellarg($f));
    confirma($r === 2 && strpos($t, 'nucleo/texto.php') !== false, 'devia parar: ' . $t);
    confirma(h('reports/js/util.js') === hash('sha256', $util . "\n// v2\n"), 'alterou ficheiros apesar do conflito');
    [$r, $t] = act('aplicar --forcar --pacote=' . escapeshellarg($f));
    confirma($r === 0 && h('nucleo/texto.php') === hash('sha256', $texto . "\n// v3\n"), $t);
    $copias = glob($priv . '/actualizacoes/copias/*v3/ficheiros/nucleo/texto.php') ?: [];
    confirma($copias && strpos((string) file_get_contents(end($copias)), 'alteração feita no servidor') !== false, 'alteração do servidor não guardada');
});

caso('erro só ao executar: versão anterior reposta sozinha, novos retirados, versão recusada', function () use ($texto, $util) {
    $f = pacote('v4', ['nucleo/texto.php' => $texto . "\nnaval_funcao_que_nao_existe();\n", 'reports/js/util.js' => $util . "\n// v4\n", 'assets/novo-v4.txt' => 'x']);
    [$r, $t] = act('aplicar --pacote=' . escapeshellarg($f));
    confirma($r === 1 && strpos($t, 'reposta') !== false, $t);
    confirma(h('nucleo/texto.php') === hash('sha256', $texto . "\n// v3\n") && h('reports/js/util.js') === hash('sha256', $util . "\n// v3\n"), 'não repôs');
    confirma(h('assets/novo-v4.txt') === null, 'ficheiro novo ficou');
    confirma((estado()['versao'] ?? '') === 'v3' && (estado()['recusada'] ?? '') === 'v4', 'estado errado');
    confirma(in_array('Portal RTM · actualização revertida', emails(), true), 'sem aviso da reversão');
});

caso('erro só visível por HTTP (página de entrada): reposta', function () use ($srv) {
    $login = (string) file_get_contents($srv . '/geo/login.php');
    $f = pacote('v4b', ['geo/login.php' => str_replace('naval_iniciar_sessao();', "naval_iniciar_sessao();\nnaval_outra_que_nao_existe();", $login)]);
    [$r, $t] = act('aplicar --pacote=' . escapeshellarg($f));
    confirma($r === 1 && strpos($t, 'geo/login.php → HTTP 500') !== false, $t);
    confirma(h('geo/login.php') === hash('sha256', $login), 'não repôs a página de entrada');
});

caso('erro de sintaxe: recusado antes de alterar o servidor', function () use ($texto) {
    $f = pacote('v4c', ['nucleo/texto.php' => $texto . "\nfunction (\n"]);
    [$r, $t] = act('aplicar --pacote=' . escapeshellarg($f));
    confirma($r === 1 && strpos($t, 'sintaxe') !== false && h('nucleo/texto.php') === hash('sha256', $texto . "\n// v3\n"), $t);
});

caso('pacotes adulterados ou com ficheiros protegidos: recusados', function () use ($pac, $texto) {
    $casos = [
        'dados' => pacote('v5a', ['reports/data/vendas.json' => '{}']),
        'env' => pacote('v5b', ['.env' => 'X=1']),
        'fora' => pacote('v5c', [], ['ficheiros/../../fora.php' => '<?php']),
        'extra' => pacote('v5d', [], ['ficheiros/assets/escondido.php' => '<?php']),
    ];
    $f = pacote('v5e', ['nucleo/texto.php' => $texto]);
    $z = new ZipArchive();
    $z->open($f);
    $z->addFromString('ficheiros/nucleo/texto.php', $texto . '// adulterado');
    $z->close();
    file_put_contents($f . '.sha256', hash_file('sha256', $f));
    $casos['hash'] = $f;
    $g = pacote('v5f', []);
    file_put_contents($g . '.sha256', str_repeat('0', 64));
    $casos['sha256'] = $g;
    foreach ($casos as $nome => $f) {
        [$r, $t] = act('aplicar --pacote=' . escapeshellarg($f));
        confirma($r === 1 && (strpos($t, 'recusado') !== false || strpos($t, 'não confere') !== false || strpos($t, 'inválido') !== false), $nome . ': ' . $t);
    }
    confirma(!is_file(dirname($pac) . '/fora.php'), 'escreveu fora da pasta');
    confirma((estado()['versao'] ?? '') === 'v3', 'estado alterado');
});

caso('migrações: aplicada antes do código; a que falha pára sem alterar ficheiros', function () use ($bd, $util) {
    $f = pacote('v6', ['ferramentas/sql/migracoes/0002-teste.sql' => "CREATE TABLE ATV_TESTE_MIG (Id INTEGER);\nGO\nINSERT INTO ATV_TESTE_MIG VALUES (1);\n", 'reports/js/util.js' => $util . "\n// v6\n"]);
    [$r, $t] = act('aplicar --pacote=' . escapeshellarg($f));
    $pdo = new PDO('sqlite:' . $bd);
    confirma($r === 0 && (int) $pdo->query('SELECT MAX(Versao) FROM ATV_MIGRACAO')->fetchColumn() === 2 && (int) $pdo->query('SELECT COUNT(*) FROM ATV_TESTE_MIG')->fetchColumn() === 1, $t);
    $f = pacote('v7', ['ferramentas/sql/migracoes/0002-teste.sql' => "CREATE TABLE ATV_TESTE_MIG (Id INTEGER);\nGO\nINSERT INTO ATV_TESTE_MIG VALUES (1);\n",
        'ferramentas/sql/migracoes/0003-erro.sql' => "CREATE TABLE ATV_OUTRA (Id INTEGER);\nGO\nISTO NAO E SQL;\n", 'reports/js/util.js' => $util . "\n// v7\n"]);
    [$r, $t] = act('aplicar --pacote=' . escapeshellarg($f));
    confirma($r === 1 && strpos($t, '0003-erro.sql falhou') !== false, $t);
    confirma((int) $pdo->query('SELECT MAX(Versao) FROM ATV_MIGRACAO')->fetchColumn() === 2, 'migração falhada registada');
    confirma((int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE name = 'ATV_OUTRA'")->fetchColumn() === 0, 'migração falhada não foi desfeita');
    confirma(h('reports/js/util.js') === hash('sha256', $util . "\n// v6\n"), 'alterou ficheiros com a migração falhada');
});

caso('GitHub: versão publicada descarregada (token só na API), aviso de token a expirar, depois «já actualizado»', function () use ($pac, $gh, $util) {
    $f = pacote('v8', ['ferramentas/sql/migracoes/0002-teste.sql' => "CREATE TABLE ATV_TESTE_MIG (Id INTEGER);\nGO\nINSERT INTO ATV_TESTE_MIG VALUES (1);\n", 'reports/js/util.js' => $util . "\n// v8\n"]);
    copy($f, $gh . '/' . basename($f));
    copy($f . '.sha256', $gh . '/' . basename($f) . '.sha256');
    file_put_contents($gh . '/versao.txt', 'v8');
    [$r, $t] = act('estado');
    confirma($r === 0 && strpos($t, 'Publicada:  v8') !== false && strpos($t, 'expira dentro de') !== false, $t);
    [$r, $t] = act('automatico');
    confirma($r === 0 && h('reports/js/util.js') === hash('sha256', $util . "\n// v8\n") && strpos($t, 'expira dentro de') !== false, $t);
    [$r, $t] = act('automatico');
    confirma($r === 0 && strpos($t, 'Já está na versão mais recente (v8)') !== false, $t);
});

caso('versão revertida não volta a ser aplicada sozinha', function () use ($gh, $texto) {
    $f = pacote('v9', ['nucleo/texto.php' => $texto . "\nnaval_funcao_que_nao_existe();\n", 'ferramentas/sql/migracoes/0002-teste.sql' => "CREATE TABLE ATV_TESTE_MIG (Id INTEGER);\n"]);
    copy($f, $gh . '/' . basename($f));
    copy($f . '.sha256', $gh . '/' . basename($f) . '.sha256');
    file_put_contents($gh . '/versao.txt', 'v9');
    [$r] = act('automatico');
    confirma($r === 1, 'v9 devia ser revertida');
    [$r, $t] = act('automatico');
    confirma($r === 0 && strpos($t, 'fica de fora') !== false, $t);
});

caso('sem a extensão zip (Phar) e com outra execução em curso', function () use ($priv, $util) {
    $f = pacote('v10', ['ferramentas/sql/migracoes/0002-teste.sql' => "CREATE TABLE ATV_TESTE_MIG (Id INTEGER);\nGO\nINSERT INTO ATV_TESTE_MIG VALUES (1);\n", 'reports/js/util.js' => $util . "\n// v10\n"]);
    $h = fopen($priv . '/actualizacoes/.bloqueio', 'c');
    flock($h, LOCK_EX);
    [$r, $t] = act('aplicar --pacote=' . escapeshellarg($f));
    flock($h, LOCK_UN);
    fclose($h);
    confirma($r === 1 && strpos($t, 'outra actualização') !== false, $t);
    [$r, $t] = act('aplicar --sem-ziparchive --pacote=' . escapeshellarg($f));
    confirma($r === 0 && h('reports/js/util.js') === hash('sha256', $util . "\n// v10\n"), $t);
});

caso('reverter: repõe a versão anterior e recusa a revertida', function () use ($util) {
    [$r, $t] = act('reverter');
    confirma($r === 0 && h('reports/js/util.js') === hash('sha256', $util . "\n// v8\n"), $t);
    confirma((estado()['versao'] ?? '') === 'v8' && (estado()['recusada'] ?? '') === 'v10', 'estado depois de reverter');
});

/** Publica versões no GitHub simulado: [tag => [conteúdos a mudar]] (da mais antiga para a mais recente). */
function publicar(array $versoes): void
{
    global $gh;
    $lista = [];
    foreach ($versoes as $tag => $mudar) {
        $f = pacote($tag, $mudar);
        copy($f, $gh . '/' . basename($f));
        copy($f . '.sha256', $gh . '/' . basename($f) . '.sha256');
        $lista[] = ['tag' => $tag, 'notas' => "## 03-11-2026\n\n### Novidade $tag\n- **Ponto:** descrição da $tag.\n"];
    }
    @unlink($gh . '/versao.txt');
    file_put_contents($gh . '/versoes.json', json_encode($lista));
    // a página do portal guarda a lista de versões 2 minutos: nova publicação, lista nova
    global $priv;
    foreach (glob($priv . '/cache/*') ?: [] as $c) @unlink($c);
}
/** Corre código PHP com o núcleo do servidor (o que o portal faz quando o super administrador decide). */
function svc(string $codigo): array
{
    global $srv, $tmp;
    $o = [];
    // ficheiro temporário (no Windows, as aspas de um «php -r» não sobrevivem à linha de comandos)
    $f = $tmp . '/svc-' . mt_rand() . '.php';
    file_put_contents($f, '<?php require ' . var_export($srv . '/nucleo/arranque.php', true) . '; naval_servico("actualizacao"); try { $r = ' . $codigo . '; echo json_encode(["ok" => true, "r" => $r]); } catch (Throwable $e) { echo json_encode(["ok" => false, "erro" => $e->getMessage()]); }');
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($f) . ' 2>&1', $o);
    @unlink($f);
    return json_decode(implode("\n", $o), true) ?: ['ok' => false, 'erro' => implode("\n", $o)];
}

caso('aprovação: sem aprovação nada é instalado; aviso por correio uma só vez', function () use ($env, $util, $texto) {
    file_put_contents($env, str_replace('ACTUALIZACAO_EXIGE_APROVACAO=0', 'ACTUALIZACAO_EXIGE_APROVACAO=1', (string) file_get_contents($env)));
    publicar(['v11' => ['reports/js/util.js' => $util . "\n// v11\n", 'nucleo/texto.php' => $texto], 'v12' => ['reports/js/util.js' => $util . "\n// v12\n", 'nucleo/texto.php' => $texto]]);
    $antes = count(emails());
    [$r, $t] = act('automatico');
    confirma($r === 0 && strpos($t, '2 versão(ões) publicada(s) à espera de aprovação') !== false, $t);
    confirma(h('reports/js/util.js') === hash('sha256', $util . "\n// v8\n"), 'instalou sem aprovação');
    confirma(in_array('Portal RTM · versão nova à espera de aprovação', emails(), true) && count(emails()) === $antes + 1, 'sem aviso de versão à espera');
    act('automatico');
    confirma(count(emails()) === $antes + 1, 'avisou duas vezes');
    $v = svc('naval_act_vista_portal()');
    confirma($v['ok'] && array_column($v['r']['pendentes'], 'versao') === ['v12', 'v11'], 'vista do portal: ' . json_encode($v));
    confirma($v['r']['pendentes'][0]['seccoes'][0]['titulo'] === 'Novidade v12', 'resumo da versão');
});

caso('aprovação: só a mais recente (a não ser que esta seja reprovada); reprovar exige motivo', function () use ($util) {
    $d = svc('naval_act_decidir("v11", "aprovar", "henrique")');
    confirma(!$d['ok'] && strpos($d['erro'], 'Aprove a versão mais recente, v12') !== false, 'aprovou uma versão mais antiga do que a mais recente: ' . json_encode($d));
    confirma(!svc('naval_act_decidir("v12", "reprovar", "henrique", "mau")')['ok'], 'reprovou sem motivo válido');
    confirma(svc('naval_act_decidir("v12", "reprovar", "henrique", "Falta validar com a Logística")')['ok'], 'não reprovou');
    $d = svc('naval_act_decidir("v11", "aprovar", "henrique")');
    confirma($d['ok'] && $d['r']['decisao'] === 'aprovada' && strlen($d['r']['sha256']) === 64, json_encode($d));
    $v = svc('naval_act_vista_portal()')['r'];
    confirma(($v['pendentes'][1]['incluida'] ?? null) === true && ($v['pendentes'][1]['fila'] ?? '') === 'esta_noite', 'vista: anterior recolhida e na fila desta noite: ' . json_encode($v['pendentes']));
    [$r, $t] = act('automatico');
    confirma($r === 0 && h('reports/js/util.js') === hash('sha256', $util . "\n// v11\n"), $t);
    [$r, $t] = act('automatico');
    confirma($r === 0 && strpos($t, 'Sem versões aprovadas por instalar') !== false && h('reports/js/util.js') === hash('sha256', $util . "\n// v11\n"), 'instalou a reprovada: ' . $t);
    $v = svc('naval_act_vista_portal()')['r'];
    $h = array_column($v['historico'], null, 'versao');
    confirma(($h['v11']['resultado']['accao'] ?? '') === 'actualizada' && ($h['v11']['decisao']['por'] ?? '') === 'henrique' && !isset($h['v11']['decisao']['sha256']), 'histórico: ' . json_encode($v['historico']));
});

caso('aprovação presa ao pacote: se mudar depois de aprovado, não é instalado', function () use ($util, $gh) {
    publicar(['v11' => ['reports/js/util.js' => $util . "\n// v11\n"], 'v12' => ['reports/js/util.js' => $util . "\n// v12\n"], 'v13' => ['reports/js/util.js' => $util . "\n// v13\n"]]);
    confirma(svc('naval_act_decidir("v13", "aprovar", "henrique")')['ok'], 'não aprovou');
    $f = pacote('v13', ['reports/js/util.js' => $util . "\n// v13 trocado\n"]);
    copy($f, $gh . '/' . basename($f));
    copy($f . '.sha256', $gh . '/' . basename($f) . '.sha256');
    [$r, $t] = act('automatico');
    confirma($r === 1 && strpos($t, 'mudou depois de aprovado') !== false && h('reports/js/util.js') === hash('sha256', $util . "\n// v11\n"), $t);
});

caso('«instalar já» pela tarefa de 5 em 5 minutos; reposição pedida no portal', function () use ($util) {
    publicar(['v11' => ['reports/js/util.js' => $util . "\n// v11\n"], 'v12' => ['reports/js/util.js' => $util . "\n// v12\n"], 'v13' => ['reports/js/util.js' => $util . "\n// v13\n"], 'v14' => ['reports/js/util.js' => $util . "\n// v14\n"]]);
    [$r, $t] = act('urgente');
    confirma($r === 0 && trim($t) === '', 'sem pedidos devia ficar calada: ' . $t);
    confirma(svc('naval_act_decidir("v14", "aprovar", "henrique", "", true)')['ok'], 'não aprovou');
    [$r, $t] = act('urgente');
    confirma($r === 0 && h('reports/js/util.js') === hash('sha256', $util . "\n// v14\n"), $t);
    [$r, $t] = act('urgente');
    confirma($r === 0 && trim($t) === '', 'repetiu a instalação: ' . $t);
    confirma(svc('naval_act_pedir_reversao("henrique")')['ok'] !== false, 'pedido de reposição');
    [$r, $t] = act('urgente');
    confirma($r === 0 && strpos($t, 'reposta a pedido do portal') !== false && h('reports/js/util.js') === hash('sha256', $util . "\n// v11\n"), $t);
    confirma((estado()['recusada'] ?? '') === 'v14', 'versão reposta devia ficar recusada');
});

caso('ficheiros alterados à mão: «Aplicar mesmo assim» decidido no portal', function () use ($util, $texto, $srv) {
    file_put_contents($srv . '/nucleo/texto.php', (string) file_get_contents($srv . '/nucleo/texto.php') . "\n// mudança feita no servidor\n");
    publicar(['v11' => ['reports/js/util.js' => $util . "\n// v11\n"], 'v15' => ['reports/js/util.js' => $util . "\n// v15\n", 'nucleo/texto.php' => $texto . "\n// v15\n"]]);
    confirma(svc('naval_act_decidir("v15", "aprovar", "henrique")')['ok'], 'não aprovou');
    [$r, $t] = act('automatico');
    confirma($r === 2 && strpos($t, 'nucleo/texto.php') !== false, 'devia parar: ' . $t);
    $v = svc('naval_act_vista_portal()')['r'];
    confirma(($v['pendentes'][0]['resultado']['accao'] ?? '') === 'conflito' && in_array('nucleo/texto.php', $v['pendentes'][0]['resultado']['ficheiros'] ?? [], true), 'vista sem os ficheiros: ' . json_encode($v['pendentes']));
    confirma(svc('naval_act_decidir("v15", "forcar", "henrique")')['ok'], 'não decidiu aplicar mesmo assim');
    [$r, $t] = act('urgente');
    confirma($r === 0 && h('nucleo/texto.php') === hash('sha256', $texto . "\n// v15\n") && h('reports/js/util.js') === hash('sha256', $util . "\n// v15\n"), $t);
});

caso('janela nocturna: não decidida aprovada às 02:45 e instalada às 03:00; disjuntor depois de uma falha', function () use ($env, $util) {
    $antes = (string) file_get_contents($env);
    file_put_contents($env, str_replace(['ACTUALIZACAO_APROVACAO_AUTOMATICA=0', 'ACTUALIZACAO_INSTALACAO_NOCTURNA=0'], ['ACTUALIZACAO_APROVACAO_AUTOMATICA=02:45', 'ACTUALIZACAO_INSTALACAO_NOCTURNA=03:00'], $antes));
    publicar(['v15' => ['reports/js/util.js' => $util . "\n// v15\n"], 'v21' => ['reports/js/util.js' => $util . "\n// v21\n"], 'v22' => ['reports/js/util.js' => $util . "\n// v22\n"]]);
    $quando = function (string $h, int $dias = 1): string { return 'naval_act_noite(["agora" => strtotime("+' . $dias . ' day ' . $h . '"), "escrever" => function ($s) {}])'; };
    [$r, $t] = act('automatico');
    confirma($r === 0 && strpos($t, 'instaladas às 03:00') !== false && h('reports/js/util.js') === hash('sha256', $util . "\n// v15\n"), 'a tarefa das 02:15 não instala: ' . $t);
    $d = svc($quando('02:20'));
    confirma($d['ok'] && $d['r'] === null && !isset(svc('naval_act_aprovacoes()')['r']['v22']), 'aprovou antes da hora: ' . json_encode($d));
    $antesEmails = count(emails());
    confirma(svc($quando('02:50'))['ok'], 'aprovação automática');
    $a = svc('naval_act_aprovacoes()')['r'];
    confirma(($a['v22']['decisao'] ?? '') === 'aprovada' && ($a['v22']['por'] ?? '') === 'automatico' && !empty($a['v22']['automatica']) && empty($a['v22']['urgente']), json_encode($a['v22'] ?? null));
    confirma(!isset($a['v21']), 'só a mais recente é aprovada');
    confirma(in_array('Portal RTM · versão v22 aprovada automaticamente', emails(), true) && count(emails()) === $antesEmails + 1, 'sem correio da aprovação automática');
    svc($quando('02:55'));
    confirma(count(emails()) === $antesEmails + 1, 'aprovou duas vezes na mesma noite');
    $d = svc($quando('03:05'));
    confirma($d['ok'] && ($d['r']['resultado'] ?? '') === 'actualizada' && h('reports/js/util.js') === hash('sha256', $util . "\n// v22\n"), 'instalação às 03:00: ' . json_encode($d));
    $d = svc($quando('03:10'));
    confirma($d['ok'] && $d['r'] === null, 'instalou duas vezes na mesma noite');
    // disjuntor: depois de uma instalação que correu mal, nada é aprovado sozinho
    publicar(['v22' => ['reports/js/util.js' => $util . "\n// v22\n"], 'v23' => ['reports/js/util.js' => $util . "\n// v23\n"]]);
    svc('naval_act_registar("revertida", ["versao" => "v22", "falhas" => ["teste"]])');
    svc($quando('02:50', 2));
    $a = svc('naval_act_aprovacoes()')['r'];
    confirma(!isset($a['v23']) && in_array('Portal RTM · aprovação automática suspensa', emails(), true), 'disjuntor: ' . json_encode($a['v23'] ?? null));
    // recém-publicada (menos de uma hora) fica para a noite seguinte
    svc('naval_act_registar("actualizada", ["versao" => "v22"])');
    $d = svc('naval_act_a_aprovar_sozinha([["versao" => "v24", "publicada_em" => gmdate("Y-m-d\\TH:i:s\\Z", time() - 600)]], ["versao" => "v22"], [], time(), 60)');
    confirma($d['ok'] && $d['r'] === null, 'aprovou uma recém-publicada: ' . json_encode($d));
    file_put_contents($env, $antes);
});

caso('GitHub App: credencial de instalação de 1 hora, sem código pessoal nem renovação anual', function () use ($env, $gh, $tmp, $priv) {
    $k = @openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    if (!$k) {
        echo "        (sem openssl_pkey_new neste PHP: caso não verificado)\n";
        return;
    }
    openssl_pkey_export($k, $pem);
    file_put_contents($tmp . '/app.pem', $pem);
    file_put_contents($gh . '/app-publica.pem', openssl_pkey_get_details($k)['key']);
    $antes = (string) file_get_contents($env);
    putenv('ACTUALIZACAO_CONFIG_APP_ID=12345');
    putenv('ACTUALIZACAO_CONFIG_APP_CHAVE=' . $tmp . '/app.pem');
    putenv('ACTUALIZACAO_CONFIG_EMAIL=ti@naval.co.ao');
    [$r, $t] = act('configurar');
    putenv('ACTUALIZACAO_CONFIG_APP_ID');
    putenv('ACTUALIZACAO_CONFIG_APP_CHAVE');
    putenv('ACTUALIZACAO_CONFIG_EMAIL');
    $depois = (string) file_get_contents($env);
    confirma($r === 0 && strpos($t, 'ligação ao GitHub a funcionar') !== false && strpos($t, 'expira dentro') === false, $t);
    confirma(strpos($depois, 'ACTUALIZACAO_GITHUB_APP_ID=12345') !== false && is_file($priv . '/actualizacoes/github-app.pem') && strpos($t, 'BEGIN') === false, 'configuração da App');
    file_put_contents($env, str_replace('ACTUALIZACAO_GITHUB_APP_ID=12345', 'ACTUALIZACAO_GITHUB_APP_ID=999', $depois));
    [$r, $t] = act('estado');
    confirma($r === 1 && strpos($t, 'o ID da App está errado') !== false, 'ID errado: ' . $t);
    file_put_contents($env, $antes);
    @unlink($gh . '/app-publica.pem');
});

caso('requisitos do servidor: PHP mínimo e extensão em falta param antes de alterar o servidor', function () use ($env, $tmp, $util) {
    $f = pacote('v16', ['reports/js/util.js' => $util . "\n// v16\n", 'ferramentas/requisitos.json' => '{"php_minimo": "99.0", "extensoes": []}']);
    [$r, $t] = act('aplicar --pacote=' . escapeshellarg($f));
    confirma($r === 1 && strpos($t, 'precisa do PHP 99.0') !== false && h('reports/js/util.js') !== hash('sha256', $util . "\n// v16\n"), $t);
    $ini = $tmp . '/php-teste.ini';
    file_put_contents($ini, ini_get('extension_dir') ? "extension_dir=\"" . ini_get('extension_dir') . "\"\n" : "\n");
    $antes = (string) file_get_contents($env);
    file_put_contents($env, $antes . 'ACTUALIZACAO_PHP_INI=' . $ini . "\n");
    $f = pacote('v17', ['reports/js/util.js' => $util . "\n// v17\n", 'ferramentas/requisitos.json' => '{"php_minimo": "7.1", "extensoes": ["extensao_inexistente_naval"]}']);
    $iniAntes = (string) file_get_contents($ini);
    [$r, $t] = act('aplicar --pacote=' . escapeshellarg($f));
    file_put_contents($env, $antes);
    confirma($r === 1 && strpos($t, 'Não foi possível activar no PHP: extensao_inexistente_naval') !== false, $t);
    confirma((string) file_get_contents($ini) === $iniAntes && h('reports/js/util.js') !== hash('sha256', $util . "\n// v17\n"), 'alterou o php.ini ou os ficheiros');
});

caso('configuração do Apache: validada e Apache reiniciado; inválida → reposta, sem reiniciar', function () use ($env, $srv, $repo, $tmp) {
    putenv('HTTPD_SIMULADO_RAIZ=' . $srv);
    $antes = (string) file_get_contents($env);
    // sem aspas: o .env retira as das pontas
    file_put_contents($env, $antes . 'ACTUALIZACAO_APACHE_CMD=' . PHP_BINARY . ' ' . $repo . '/tests/dados/httpd-simulado.php' . "\n");
    $conf = (string) file_get_contents($srv . '/ferramentas/apache/portal-rtm.conf');
    @unlink($tmp . '/httpd-reinicios.log');
    $f = pacote('v18', ['ferramentas/apache/portal-rtm.conf' => $conf . "\n# v18\n"]);
    [$r, $t] = act('aplicar --pacote=' . escapeshellarg($f));
    confirma($r === 0 && strpos($t, 'Apache reiniciado') !== false && substr_count((string) @file_get_contents($tmp . '/httpd-reinicios.log'), 'restart') === 1, $t);
    $f = pacote('v19', ['ferramentas/apache/portal-rtm.conf' => $conf . "\nERRO_DE_TESTE\n"]);
    [$r, $t] = act('aplicar --pacote=' . escapeshellarg($f));
    confirma($r === 1 && strpos($t, 'httpd -t') !== false && strpos((string) file_get_contents($srv . '/ferramentas/apache/portal-rtm.conf'), 'ERRO_DE_TESTE') === false, $t);
    confirma(substr_count((string) @file_get_contents($tmp . '/httpd-reinicios.log'), 'restart') === 1, 'reiniciou com a configuração inválida');
    // inclusão no httpd.conf (uma vez), com validação
    $httpd = $tmp . '/httpd.conf';
    file_put_contents($httpd, "ServerRoot \"C:/Apache24\"\r\nListen 2051\r\n");
    file_put_contents($env, (string) file_get_contents($env) . 'ACTUALIZACAO_APACHE_CONF=' . $httpd . "\n");
    [$r, $t] = act('apache');
    confirma($r === 0 && strpos((string) file_get_contents($httpd), "/ferramentas/apache/portal-rtm.conf\"\r\n") !== false && glob($httpd . '.antes-portal-*'), $t);
    [$r, $t] = act('apache');
    confirma($r === 0 && strpos($t, 'já incluída') !== false && substr_count((string) file_get_contents($httpd), 'portal-rtm.conf') === 1, $t);
    file_put_contents($env, $antes);
});

caso('desligado: a tarefa agendada não faz nada', function () use ($env) {
    $antes = (string) file_get_contents($env);
    file_put_contents($env, str_replace('ACTUALIZACAO_AUTOMATICA=1', 'ACTUALIZACAO_AUTOMATICA=0', $antes));
    [$r, $t] = act('automatico');
    file_put_contents($env, $antes);
    confirma($r === 0 && strpos($t, 'desligadas') !== false, $t);
});

caso('token recusado: mensagem clara', function () use ($env) {
    $antes = (string) file_get_contents($env);
    file_put_contents($env, str_replace('github_pat_teste_0123456789abcdefghij', 'github_pat_errado_0123456789abcdefghij', $antes));
    [$r, $t] = act('estado');
    file_put_contents($env, $antes);
    confirma($r === 1 && strpos($t, 'recusou o código de acesso') !== false && strpos($t, 'github_pat_errado') === false, $t);
});

foreach ([$web, $api] as $p) {
    proc_terminate($p);
    proc_close($p);
}
$apagar = function (string $d) use (&$apagar) {
    foreach (array_diff(scandir($d) ?: [], ['.', '..']) as $x) is_dir("$d/$x") && !is_link("$d/$x") ? $apagar("$d/$x") : @unlink("$d/$x");
    @rmdir($d);
};
$apagar($tmp);
echo "\n" . ($n - $falhas) . " de $n casos do actualizador passaram.\n";
exit($falhas ? 1 : 0);
