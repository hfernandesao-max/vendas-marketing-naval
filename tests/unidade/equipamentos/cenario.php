<?php
/**
 * Cenário do módulo Equipamentos numa base de dados SQLite de teste (tests/dados/equipamentos-sqlite.sql),
 * corrido num processo próprio por perfil (a conta da sessão fica em memória durante o processo):
 *   php cenario.php <pasta> preparar | log | pro | sup
 * Escreve uma linha por caso: «ok <nome>» ou «FALHA <nome> :: <mensagem>».
 */
[$_, $amb, $fase] = $argv + [null, null, null];
if (!$amb || !$fase) exit(2);

if ($fase === 'preparar') {
    @mkdir($amb . '/privado', 0770, true);
    // Base de dados: SQLite de teste ou, na CI do SQL Server, a indicada em ATV_TESTE_DSN (já com a carga de teste)
    $dsn = (string) getenv('ATV_TESTE_DSN');
    $bdLinhas = ['ATV_DB_DSN=' . $dsn, 'ATV_DB_USER=' . getenv('ATV_TESTE_USER'), 'ATV_DB_PASS=' . getenv('ATV_TESTE_PASS')];
    if ($dsn === '') {
        $bd = $amb . '/equipamentos.sqlite';
        @unlink($bd);
        $pdo = new PDO('sqlite:' . $bd);
        $pdo->exec((string) file_get_contents(dirname(__DIR__, 2) . '/dados/equipamentos-sqlite.sql'));
        $bdLinhas = ['ATV_DB_DSN=sqlite:' . $bd];
    }
    file_put_contents($amb . '/.env', implode("\n", array_merge([
        'DADOS_PRIVADOS=' . $amb . '/privado', 'EMAIL_MODO=ficheiro', 'FUSO_HORARIO=Africa/Luanda', 'PORTAL_URL=https://portal.teste',
        'JWT_SEGREDO=' . bin2hex(random_bytes(32)), 'MDM_URL_EQUIPAMENTO=https://mdm.teste/dispositivo/{id}',
    ], $bdLinhas)) . "\n");
    $contas = ['log' => ['LOG', []], 'pro' => ['PRO', ['1002']], 'sup' => ['SUP', ['1001']]];
    $d = [];
    foreach ($contas as $login => [$perfil, $rotas]) {
        $d[$login] = ['nome' => 'Teste ' . $perfil, 'email' => $login . '@naval.co.ao', 'senha' => password_hash('x', PASSWORD_DEFAULT),
            'perfil' => $perfil, 'rotas' => $rotas, 'activo' => true, 'criado' => date('Y-m-d')];
    }
    file_put_contents($amb . '/privado/utilizadores.json', json_encode($d));
    echo "ok preparar\n";
    exit(0);
}

putenv('NAVAL_ENV=' . $amb . '/.env');
require dirname(__DIR__, 3) . '/nucleo/arranque.php';
$_SESSION = ['usuario_autenticado' => true, 'usuario_login' => $fase];
$_SERVER['REMOTE_ADDR'] = '10.0.0.' . ['log' => 1, 'pro' => 2, 'sup' => 3][$fase];
$_SERVER['HTTP_USER_AGENT'] = 'Teste/1.0 (' . $fase . ')';
naval_servico('equipamentos');

function caso(string $nome, callable $f): void
{
    try {
        $f();
        echo "ok $nome\n";
    } catch (Throwable $e) {
        echo "FALHA $nome :: " . str_replace("\n", ' ', $e->getMessage()) . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ")\n";
    }
}
/** @param mixed $a @param mixed $b */
function igual($a, $b, string $m = ''): void
{
    if ($a !== $b) throw new RuntimeException(($m ? $m . ': ' : '') . 'esperado ' . var_export($a, true) . ', obtido ' . var_export($b, true));
}
function verdade(bool $v, string $m = 'condição falsa'): void
{
    if (!$v) throw new RuntimeException($m);
}
function lanca(int $estado, callable $f, string $texto = ''): void
{
    try {
        $f();
    } catch (NavalErro $e) {
        igual($estado, $e->estado(), 'código HTTP (' . $e->getMessage() . ')');
        if ($texto !== '') verdade(stripos($e->getMessage(), $texto) !== false, 'mensagem: ' . $e->getMessage());
        return;
    }
    throw new RuntimeException('esperava NavalErro ' . $estado);
}
/** Assinatura desenhada de teste: PNG e JPEG reais (GD) ou mínimos, e traços. */
function assinatura(): array
{
    $im = imagecreatetruecolor(300, 120);
    imagefill($im, 0, 0, imagecolorallocate($im, 255, 255, 255));
    $tinta = imagecolorallocate($im, 28, 35, 64);
    for ($x = 20; $x < 280; $x += 4) imagesetpixel($im, $x, (int) (60 + 30 * sin($x / 20)), $tinta);
    ob_start();
    imagepng($im);
    $png = (string) ob_get_clean();
    ob_start();
    imagejpeg($im, null, 85);
    $jpg = (string) ob_get_clean();
    $tr = [];
    for ($i = 0; $i < 30; $i++) $tr[] = ['x' => $i * 9, 'y' => 60, 't' => $i * 16, 'p' => 0.5];
    return ['aceito' => true, 'imagem_png' => 'data:image/png;base64,' . base64_encode($png), 'imagem_jpeg' => 'data:image/jpeg;base64,' . base64_encode($jpg), 'tracos' => [$tr]];
}
function estado(string $id): string
{
    return naval_atv_obter($id)['estado'];
}
$guardar = function (string $k, string $v) use ($amb) { file_put_contents($amb . '/' . $k . '.txt', $v); };
$ler = function (string $k) use ($amb) { return (string) @file_get_contents($amb . '/' . $k . '.txt'); };

if ($fase === 'log') {
    caso('lista: equipamentos de exemplo, todos visíveis para a Logística', function () {
        $l = naval_atv_lista();
        igual(6, count($l));
        $r = naval_atv_resumo($l);
        igual(6, $r['por_estado']['ATRIBUIDO']);
        igual(6, $r['sem_termo'], 'atribuídos sem termo');
    });
    caso('registar: ID seguinte do tipo, em stock, com evento ENTRADA', function () {
        $s = naval_atv_registar(['tipo' => 'SMT', 'marca' => 'Blackview', 'modelo' => 'BV6200 Pro', 'num_serie' => 'SERIE-T4', 'imei1' => '353326512160004']);
        igual('NAV-SMT-0004', $s['id']);
        igual('EM_STOCK', $s['estado']);
        $i = naval_atv_registar(['tipo' => 'IMP', 'marca' => 'Datecs', 'modelo' => 'DPP-250', 'mac' => '00-12-5b-04-2e-ff']);
        igual('NAV-IMP-0004', $i['id']);
        igual('00:12:5B:04:2E:FF', $i['mac'], 'MAC normalizado');
        igual('ENTRADA', naval_atv_ficha('NAV-SMT-0004')['eventos'][0]['tipo']);
    });
    caso('registar: dados inválidos e repetidos recusados', function () {
        lanca(400, function () { naval_atv_registar(['tipo' => 'IMP', 'mac' => '00:12']); }, 'MAC');
        lanca(400, function () { naval_atv_registar(['tipo' => 'SMT', 'imei1' => '123']); }, 'IMEI');
        lanca(400, function () { naval_atv_registar(['tipo' => 'XXX']); });
        lanca(409, function () { naval_atv_registar(['tipo' => 'SMT', 'imei1' => '353326512160004']); }, 'IMEI');
        lanca(409, function () { naval_atv_registar(['tipo' => 'SMT', 'num_serie' => 'SERIE-T4']); }, 'série');
        lanca(400, function () { naval_atv_ficha("NAV-SMT-0001' OR 1=1"); });
        lanca(404, function () { naval_atv_ficha('nav-smt-0999'); });
        igual('NAV-SMT-0001', naval_atv_ficha('nav-smt-0001')['equipamento']['id'], 'ID em minúsculas');
    });
    caso('eventos: transições válidas e inválidas', function () {
        lanca(409, function () { naval_atv_registar_evento('NAV-SMT-0004', ['tipo' => 'DEVOLUCAO']); });
        lanca(400, function () { naval_atv_registar_evento('NAV-SMT-0004', ['tipo' => 'SUBSTITUICAO']); });
        naval_atv_registar_evento('NAV-SMT-0004', ['tipo' => 'ETIQUETAGEM', 'observacoes' => 'Etiqueta 45 × 25 mm']);
        igual('EM_STOCK', estado('NAV-SMT-0004'));
    });
    caso('associação: um smartphone com uma impressora; a troca fecha a anterior', function () {
        naval_atv_registar_evento('NAV-SMT-0004', ['tipo' => 'ASSOCIACAO', 'outro' => 'NAV-IMP-0004']);
        igual('NAV-IMP-0004', naval_atv_obter('NAV-SMT-0004')['associado']);
        igual('NAV-SMT-0004', naval_atv_obter('NAV-IMP-0004')['associado']);
        lanca(400, function () { naval_atv_registar_evento('NAV-SMT-0004', ['tipo' => 'ASSOCIACAO', 'outro' => 'NAV-SMT-0001']); });
        // NAV-IMP-0001 estava com NAV-SMT-0001: associar ao 0004 fecha as duas associações anteriores
        naval_atv_registar_evento('NAV-SMT-0004', ['tipo' => 'ASSOCIACAO', 'outro' => 'NAV-IMP-0001']);
        igual(null, naval_atv_obter('NAV-SMT-0001')['associado']);
        igual(null, naval_atv_obter('NAV-IMP-0004')['associado']);
        naval_atv_registar_evento('NAV-SMT-0004', ['tipo' => 'ASSOCIACAO', 'outro' => 'NAV-IMP-0004']);
        naval_atv_registar_evento('NAV-SMT-0001', ['tipo' => 'ASSOCIACAO', 'outro' => 'NAV-IMP-0001']);
        $n = (int) naval_atv_q('SELECT COUNT(*) AS N FROM ATV_ASSOCIACAO WHERE DataFim IS NULL')[0]['N'];
        igual(4, $n, 'associações em vigor');
    });
    caso('atribuição ao colaborador, com o equipamento associado', function () {
        lanca(400, function () { naval_atv_registar_evento('NAV-SMT-0004', ['tipo' => 'ATRIBUICAO']); });
        naval_atv_registar_evento('NAV-SMT-0004', ['tipo' => 'ATRIBUICAO', 'id_colaborador' => 1, 'rota' => '1001', 'incluir_associado' => true]);
        igual('ATRIBUIDO', estado('NAV-SMT-0004'));
        igual('ATRIBUIDO', estado('NAV-IMP-0004'));
        igual('1001', naval_atv_obter('NAV-IMP-0004')['rota']);
        verdade(naval_atv_obter('NAV-SMT-0004')['sem_termo'], 'atribuído sem termo');
    });
    caso('histórico inalterável: a base de dados recusa alterar e apagar eventos', function () {
        lanca(409, function () { naval_atv_x("UPDATE ATV_EVENTO SET Observacoes = 'x'"); }, 'histórico');
        lanca(409, function () { naval_atv_x('DELETE FROM ATV_EVENTO'); }, 'histórico');
    });
    caso('termo de entrega exige a convenção de prova assinada', function () {
        lanca(409, function () {
            naval_atv_emitir(['tipo' => 'TERMO_ENTREGA', 'id_colaborador' => 1, 'equipamentos' => [['id' => 'NAV-SMT-0004']]]);
        }, 'Convenção');
    });
    caso('convenção de prova: emissão, assinatura presencial e do responsável, conclusão', function () use ($guardar) {
        $d = naval_atv_emitir(['tipo' => 'CONVENCAO_PROVA', 'id_colaborador' => 1]);
        verdade((bool) preg_match('/^CPE-\d{4}-000001$/', $d['numero']), 'número ' . $d['numero']);
        igual('A_ASSINAR', $d['estado']);
        $r = naval_atv_assinar($d['numero'], assinatura());
        igual('PRESENCIAL', $r['assinaturas'][0]['metodo']);
        igual('log', $r['assinaturas'][0]['testemunha']);
        igual('A_ASSINAR', $r['documento']['estado']);
        lanca(400, function () use ($d) { naval_atv_assinar($d['numero'], ['aceito' => false] + assinatura()); }, 'Li e aceito');
        lanca(400, function () use ($d) { naval_atv_assinar($d['numero'], ['imagem_png' => 'data:image/png;base64,AAAA'] + assinatura()); });
        $r = naval_atv_assinar($d['numero'], assinatura());
        igual('ASSINADO', $r['documento']['estado']);
        igual(2, count($r['assinaturas']));
        verdade((bool) $r['documento']['hash_final']);
        verdade((bool) naval_atv_q('SELECT IdDocConvencao FROM ATV_COLABORADOR WHERE IdColaborador = 1')[0]['IdDocConvencao'], 'convenção no colaborador');
        lanca(409, function () use ($d) { naval_atv_assinar($d['numero'], assinatura()); });
        $guardar('cpe', $d['numero']);
    });
    caso('termo de entrega: PDF assinado contém o original intacto; eventos nos equipamentos', function () use ($guardar) {
        $d = naval_atv_emitir(['tipo' => 'TERMO_ENTREGA', 'id_colaborador' => 1, 'observacoes' => 'Entrega inicial',
            'equipamentos' => [['id' => 'NAV-SMT-0004', 'acessorios' => 'Carregador', 'estado_fisico' => 'Novo'], ['id' => 'NAV-IMP-0004', 'acessorios' => 'Cabo']]]);
        verdade((bool) preg_match('/^TER-\d{4}-000001$/', $d['numero']));
        $orig = naval_atv_ficheiro($d['numero']);
        $original = (string) file_get_contents($orig['caminho']);
        verdade(strpos($original, '%PDF-1.4') === 0, 'PDF');
        naval_atv_assinar($d['numero'], assinatura());
        $r = naval_atv_assinar($d['numero'], assinatura());
        igual('ASSINADO', $r['documento']['estado']);
        $f = naval_atv_ficheiro($d['numero']);
        igual($d['numero'] . '-assinado.pdf', $f['nome']);
        $assinado = (string) file_get_contents($f['caminho']);
        igual($original, substr($assinado, 0, strlen($original)), 'original no início do assinado');
        verdade(substr_count($assinado, '%%EOF') === 2 && strpos($assinado, '/Prev ') !== false, 'actualização incremental');
        verdade(strpos($assinado, '/Count 2') !== false || strpos($assinado, '/Count 3') !== false, 'páginas acrescentadas');
        $v = naval_atv_verificar($d['numero']);
        verdade($v['original_integro'] && $v['assinado_integro'], 'integridade');
        $tipos = array_column(naval_atv_ficha('NAV-IMP-0004')['eventos'], 'tipo');
        verdade(in_array('DOCUMENTO_ASSINADO', $tipos, true), 'evento DOCUMENTO_ASSINADO');
        verdade(!naval_atv_obter('NAV-SMT-0004')['sem_termo'], 'já com termo');
        // ficheiro alterado depois de assinado: a verificação detecta
        file_put_contents($f['caminho'], $assinado . ' ');
        verdade(!naval_atv_verificar($d['numero'])['assinado_integro'], 'alteração detectada');
        file_put_contents($f['caminho'], $assinado);
        lanca(409, function () use ($d) { naval_atv_x("UPDATE ATV_DOCUMENTO SET Estado = 'A_ASSINAR' WHERE NumDocumento = :n", [':n' => $d['numero']]); });
        $guardar('ter', $d['numero']);
    });
    caso('documento alterado depois de emitido não pode ser assinado', function () {
        $d = naval_atv_emitir(['tipo' => 'AUTO_DEVOLUCAO', 'id_colaborador' => 1, 'equipamentos' => [['id' => 'NAV-SMT-0004']]]);
        $f = naval_atv_ficheiro($d['numero']);
        $c = (string) file_get_contents($f['caminho']);
        file_put_contents($f['caminho'], $c . '%');
        lanca(409, function () use ($d) { naval_atv_assinar($d['numero'], assinatura()); }, 'alterado');
        file_put_contents($f['caminho'], $c);
        lanca(400, function () use ($d) { naval_atv_anular($d['numero'], ''); });
        igual('ANULADO', naval_atv_anular($d['numero'], 'Emitido por engano')['estado']);
        lanca(409, function () use ($d) { naval_atv_anular($d['numero'], 'De novo'); });
    });
    caso('auto de substituição: o devolvido vai para reparação, o entregue herda rota, colaborador e associação', function () {
        naval_atv_registar(['tipo' => 'SMT', 'marca' => 'Blackview', 'modelo' => 'BV6200 Pro', 'num_serie' => 'SERIE-T5']);
        lanca(400, function () {
            naval_atv_emitir(['tipo' => 'AUTO_SUBSTITUICAO', 'id_colaborador' => 1, 'equipamentos' => [['id' => 'NAV-SMT-0004', 'papel' => 'DEVOLVIDO']]]);
        });
        $d = naval_atv_emitir(['tipo' => 'AUTO_SUBSTITUICAO', 'id_colaborador' => 1,
            'equipamentos' => [['id' => 'NAV-SMT-0004', 'papel' => 'DEVOLVIDO', 'estado_fisico' => 'Ecrã partido'], ['id' => 'NAV-SMT-0005', 'papel' => 'ENTREGUE']]]);
        naval_atv_assinar($d['numero'], assinatura());
        naval_atv_assinar($d['numero'], assinatura());
        $velho = naval_atv_obter('NAV-SMT-0004');
        $novo = naval_atv_obter('NAV-SMT-0005');
        igual('EM_REPARACAO', $velho['estado']);
        igual(null, $velho['associado']);
        igual('ATRIBUIDO', $novo['estado']);
        igual('1001', $novo['rota']);
        igual(1, $novo['id_colaborador']);
        igual('NAV-IMP-0004', $novo['associado']);
    });
    caso('auto de substituição: se um equipamento mudou de estado, a última assinatura é recusada e nada muda', function () {
        $n = naval_atv_registar(['tipo' => 'SMT', 'num_serie' => 'SERIE-T7'])['id'];
        $d = naval_atv_emitir(['tipo' => 'AUTO_SUBSTITUICAO', 'id_colaborador' => 1,
            'equipamentos' => [['id' => 'NAV-SMT-0005', 'papel' => 'DEVOLVIDO'], ['id' => $n, 'papel' => 'ENTREGUE']]]);
        naval_atv_assinar($d['numero'], assinatura());
        naval_atv_registar_evento($n, ['tipo' => 'ABATE', 'observacoes' => 'Avariado antes da entrega']);
        lanca(409, function () use ($d) { naval_atv_assinar($d['numero'], assinatura()); }, 'mudou de estado');
        $doc = naval_atv_documento($d['numero']);
        igual('A_ASSINAR', $doc['documento']['estado']);
        igual(1, count($doc['assinaturas']), 'a segunda assinatura não ficou');
        igual('ABATIDO', estado($n));
        igual('ATRIBUIDO', estado('NAV-SMT-0005'));
        verdade(!is_file(naval_pasta('equipamentos/assinaturas') . '/' . $d['numero'] . '-2.png'), 'evidências da assinatura recusada apagadas');
        verdade(!is_file(naval_pasta('equipamentos/documentos') . '/' . $d['numero'] . '-assinado.pdf'), 'sem PDF assinado');
        igual('ANULADO', naval_atv_x("UPDATE ATV_DOCUMENTO SET Estado = 'ANULADO', MotivoAnulacao = 'teste' WHERE NumDocumento = :n", [':n' => $d['numero']]) === 1 ? 'ANULADO' : '');
    });
    caso('assinatura: coordenadas fora dos limites ignoradas; válidas com 6 casas', function () {
        $d = naval_atv_emitir(['tipo' => 'AUTO_DEVOLUCAO', 'id_colaborador' => 1, 'equipamentos' => [['id' => 'NAV-IMP-0004']]]);
        naval_atv_assinar($d['numero'], ['lat' => 5000, 'lon' => 13.2] + assinatura());
        $r = naval_atv_assinar($d['numero'], ['lat' => -8.838333, 'lon' => 0.00001] + assinatura());
        $l = naval_atv_q('SELECT Ordem, Latitude, Longitude FROM ATV_ASSINATURA WHERE IdDocumento = :d ORDER BY Ordem', [':d' => $r['documento']['id']]);
        igual(null, $l[0]['Latitude']);
        verdade(abs((float) $l[1]['Latitude'] + 8.838333) < 1e-6 && abs((float) $l[1]['Longitude'] - 0.00001) < 1e-6, 'coordenadas gravadas');
        $pdf = (string) file_get_contents(naval_atv_ficheiro($d['numero'])['caminho']);
        verdade(strpos($pdf, '\\(assinado\\)') !== false, 'título «(assinado)» nas propriedades do PDF assinado');
        verdade(strpos($pdf, mb_convert_encoding('Página de assinaturas 1 de 1', 'Windows-1252', 'UTF-8')) !== false, 'página de assinaturas numerada à parte');
    });
    caso('abate: só pela permissão Administrar; o ID não se reutiliza', function () {
        naval_atv_registar_evento('NAV-SMT-0004', ['tipo' => 'ABATE', 'observacoes' => 'Sem reparação']);
        igual('ABATIDO', estado('NAV-SMT-0004'));
        lanca(409, function () { naval_atv_registar_evento('NAV-SMT-0004', ['tipo' => 'ASSOCIACAO', 'outro' => 'NAV-IMP-0002']); });
        $n = naval_atv_registar(['tipo' => 'SMT', 'num_serie' => 'SERIE-T6']);
        igual('NAV-SMT-0007', $n['id'], 'a seguir ao 0006 (abatido no caso anterior): nunca reutiliza');
    });
    caso('ficha: ligação ao MDM e leitura do QR registada', function () {
        $f = naval_atv_ficha('NAV-SMT-0001', true);
        igual('LEITURA_QR', $f['eventos'][0]['tipo']);
        verdade(strpos((string) $f['mdm_url'], 'https://mdm.teste/dispositivo/') === 0, 'mdm_url');
    });
    caso('colaborador com conta do portal e convenção por assinar (para o perfil PRO)', function () use ($guardar) {
        $c = naval_atv_guardar_colaborador(['nome' => 'Promotor Teste', 'funcao' => 'Comercial', 'rota' => '1002', 'utilizador' => 'pro', 'email' => 'pro@naval.co.ao']);
        lanca(400, function () { naval_atv_guardar_colaborador(['nome' => 'X', 'email' => 'invalido']); });
        $d = naval_atv_emitir(['tipo' => 'CONVENCAO_PROVA', 'id_colaborador' => $c['id']]);
        $guardar('cpe-pro', $d['numero']);
    });
}

if ($fase === 'pro') {
    caso('perfil por rota: só vê os equipamentos e colaboradores da sua rota', function () {
        $l = naval_atv_lista();
        verdade(count($l) > 0, 'tem equipamentos');
        foreach ($l as $e) igual('1002', $e['rota']);
        foreach (naval_atv_colaboradores() as $c) igual('1002', $c['rota']);
        lanca(403, function () { naval_atv_ficha('NAV-SMT-0001'); });
    });
    caso('o colaborador assina com a sua conta (sem a permissão Movimentar); não assina pelo responsável', function () use ($ler) {
        $n = $ler('cpe-pro');
        $r = naval_atv_assinar($n, assinatura(), false);
        igual('CONTA_PORTAL', $r['assinaturas'][0]['metodo']);
        igual('A_ASSINAR', $r['documento']['estado']);
        lanca(403, function () use ($n) { naval_atv_assinar($n, assinatura(), false); });
    });
    caso('sem permissão Movimentar não assina documentos de outros', function () use ($ler) {
        lanca(403, function () use ($ler) { naval_atv_documento($ler('ter')); });
    });
}

if ($fase === 'sup') {
    caso('supervisor: documentos e fichas fora das suas rotas recusados', function () use ($ler) {
        lanca(403, function () { naval_atv_ficha('NAV-SMT-0002'); });
        naval_atv_documento($ler('ter'));   // rota 1001: permitido
        $docs = naval_atv_documentos();
        verdade(count($docs) >= 1, 'vê os documentos da rota 1001');
        foreach ($docs as $d) verdade($d['rota'] === '1001' || $d['rota'] === null, 'rota ' . var_export($d['rota'], true));
    });
    caso('supervisor: não associa nem desassocia equipamentos de outras rotas', function () {
        lanca(403, function () { naval_atv_registar_evento('NAV-SMT-0001', ['tipo' => 'ASSOCIACAO', 'outro' => 'NAV-IMP-0002']); });
        lanca(403, function () { naval_atv_registar_evento('NAV-SMT-0003', ['tipo' => 'DESASSOCIACAO']); });
        igual('NAV-IMP-0002', naval_atv_obter('NAV-SMT-0002')['associado'], 'nada mudou');
    });
    caso('abate exige Administrar', function () {
        lanca(403, function () { naval_atv_registar_evento('NAV-SMT-0005', ['tipo' => 'ABATE']); });
    });
}
