<?php
// Ficheiro para o SAP gerado no servidor (nucleo/servicos/sap-ficheiro.php) e envio automático (sap-envio.php).
naval_servico('sap-envio');

/** Corre $f com a pasta do SAP vazia e repõe-na no fim (os outros testes contam os lotes). */
function sap_isolado(callable $f): void
{
    $d = naval_sap_pasta();
    $guardar = [];
    foreach (glob($d . '/*') ?: [] as $x) $guardar[$x] = file_get_contents($x);
    foreach (array_keys($guardar) as $x) unlink($x);
    try {
        $f();
    } finally {
        foreach (glob($d . '/*') ?: [] as $x) unlink($x);
        foreach ($guardar as $x => $c) file_put_contents($x, $c);
    }
}

teste('ficheiro para o SAP no servidor: a folha é igual à do navegador (referência partilhada com o teste do portal)', function () {
    $c = json_decode((string) file_get_contents(dirname(__DIR__) . '/dados/sap-ficheiro/caso.json'), true);
    $L = naval_sap_linhas($c['vendas'], $c['artigos'], $c['config'], $c['origens'], $c['exportados'], [], $c['de'], $c['ate']);
    igual([], $L['erros']);
    igual(1, $L['repetidos'], 'o documento já emitido fica de fora');
    igual(['1003'], $L['omissao'], 'rota sem abastecimento: depósito por omissão');
    igual(6, count($L['docs']));
    igual(str_replace("\r\n", "\n", (string) file_get_contents(dirname(__DIR__) . '/dados/sap-ficheiro/folha.xml')), naval_sap_folha($L['linhas']));
    // pagamentos noutro meio (transferência, sem rodapé) ficam nos avisos
    verdade((bool) preg_grep('/FR 1003\/2026\/4.*Transferência/u', $L['avisos']), 'aviso da transferência');
});

teste('ficheiro para o SAP no servidor: documento com erro fica de fora e é listado; empregado por omissão com o nome do promotor', function () {
    $cfg = ['prefixo' => 'FHR PIV', 'moeda' => 'AOA', 'unidade' => 'CAR', 'local_omissao' => '', 'rotas' => []];
    $v = function ($doc, $rota) { return ['documento' => $doc, 'data' => '2026-11-24', 'hora' => '10:00', 'rota' => $rota, 'tipo' => 'Factura-recibo', 'total' => 100, 'pagamentos' => [['codigo' => 'NU', 'meio' => 'Numerário', 'valor' => 100]]]; };
    $a = function ($doc, $codigo) { return ['documento' => $doc, 'codigo' => $codigo, 'artigo' => 'X', 'quantidade' => 1, 'valor_sem_iva' => 87.72, 'iva' => 12.28, 'unidade' => 'CAR']; };
    $origens = ['rotas' => ['1009' => ['local' => 'RTM1', 'artigos' => []]], 'artigos' => []];
    $L = naval_sap_linhas([$v('FR 1', '1009'), $v('FR 2', '1009'), $v('FR 3', '1010'), $v('FR 4', '1009')], [$a('FR 1', '10000332'), $a('FR 2', ''), $a('FR 3', '10000332')],
        $cfg, $origens, [], ['1009' => 'Robel'], '2026-11-24', '2026-11-24');
    igual(['FR 1'], array_column($L['docs'], 'documento'));
    igual(['FR 2', 'FR 3', 'FR 4'], array_column($L['erros'], 'documento'), 'sem código, sem depósito SAP, sem linhas');
    igual(['1010'], $L['sem_local']);
    igual('FHR PIV - ROTA 1009 - Robel', $L['linhas'][0]['empregado']);
    igual('1009 - FHR PIV', $L['linhas'][0]['loja']);
});

teste('ficheiro para o SAP no servidor: o Excel é um ZIP válido com a folha e o arredondamento segue o JavaScript', function () {
    $x = naval_sap_xlsx([]);
    igual("PK\x03\x04", substr($x, 0, 4));
    if (class_exists('ZipArchive')) {
        $f = tempnam(sys_get_temp_dir(), 'sap');
        file_put_contents($f, $x);
        $z = new ZipArchive();
        verdade($z->open($f) === true, 'abre o ZIP');
        verdade(strpos((string) $z->getFromName('xl/worksheets/sheet1.xml'), '<dimension ref="A1:Q1"/>') !== false);
        igual(6, $z->numFiles);
        $z->close();
        unlink($f);
    }
    igual([1.01, -1.0, 2.68], [naval_sap_r2(1.005 + 1e-9), naval_sap_r2(-1.005), naval_sap_r2(2.675 + 1e-9)]);
    igual(46350, naval_sap_data_excel('2026-11-24'));
    igual(['1.5', '1000', '-570', '0.1'], [naval_sap_numero(1.5), naval_sap_numero(1000.0), naval_sap_numero(-570.0), naval_sap_numero(0.1)]);
});

teste('envio automático para o SAP: período desde o último lote automático (até 7 dias) e configuração validada', function () {
    $lotes = [['automatico' => true, 'estado' => 'carregado', 'ate' => '2026-11-23'], ['automatico' => true, 'estado' => 'anulado', 'ate' => '2026-11-20'], ['estado' => 'emitido', 'ate' => '2026-11-21']];
    igual(['2026-11-23', '2026-11-24'], naval_sap_envio_periodo($lotes, '2026-11-24'));
    igual(['2026-11-24', '2026-11-24'], naval_sap_envio_periodo([['automatico' => true, 'estado' => 'emitido', 'ate' => '2026-11-10']], '2026-11-24'), 'mais de 7 dias: só hoje');
    $e = naval_sap_envio_limpar(['activo' => true, 'hora' => '16:00', 'para' => 'Muzamil@Exemplo.com; outro@exemplo.com', 'avisar' => 'michael@exemplo.com']);
    igual(['muzamil@exemplo.com', 'outro@exemplo.com'], $e['para']);
    igual(['michael@exemplo.com'], $e['avisar']);
    lanca(400, function () { naval_sap_envio_limpar(['activo' => true, 'para' => '']); });
    lanca(400, function () { naval_sap_envio_limpar(['activo' => true, 'para' => 'nao-e-email']); });
    lanca(400, function () { naval_sap_envio_limpar(['activo' => false, 'hora' => '25:00']); });
});

teste('envio automático para o SAP: às 16:00 emite o lote, envia o Excel com a ligação; a confirmação avisa no mesmo assunto', function () { sap_isolado(function () {
    $dia = '2026-11-25';   // quarta-feira
    $cfg = naval_sap_config();
    $cfg['rotas'] = ['1009' => ['local' => 'RTM1']];
    $cfg['envio'] = ['activo' => true, 'hora' => '16:00', 'para' => ['muzamil@exemplo.com'], 'avisar' => ['michael@exemplo.com']];
    naval_json_gravar(naval_sap_pasta() . '/config.json', $cfg);
    $enviados = [];
    $enviar = function ($para, $assunto, $html, $anexos, $o) use (&$enviados) {
        $enviados[] = ['para' => array_column($para, 'email'), 'assunto' => $assunto, 'html' => $html, 'anexos' => array_column($anexos, 'nome'), 'o' => $o];
        return ['ok' => true, 'erro' => ''];
    };
    $dados = ['vendas' => [['documento' => 'FR 900', 'data' => $dia, 'hora' => '10:00', 'rota' => '1009', 'tipo' => 'Factura-recibo', 'total' => 1140, 'pagamentos' => [['codigo' => 'NU', 'meio' => 'Numerário', 'valor' => 1140]]]],
        'artigos' => [['documento' => 'FR 900', 'codigo' => '10000332', 'artigo' => 'Fraldas', 'quantidade' => 1, 'valor_sem_iva' => 1000, 'iva' => 140, 'unidade' => 'CAR']], 'promotores' => []];
    $o = ['vendas' => $dados, 'origens' => ['rotas' => [], 'artigos' => []], 'por_sincronizar' => ['1011'], 'enviar' => $enviar];
    igual(null, naval_sap_envio_ciclo(strtotime($dia . ' 15:59'), $o), 'antes da hora não faz nada');
    $r = naval_sap_envio_ciclo(strtotime($dia . ' 16:00'), $o);
    verdade($r['email'] && $r['lote'] > 0, 'lote emitido e enviado');
    igual(null, naval_sap_envio_ciclo(strtotime($dia . ' 16:01'), $o), 'só uma vez por dia');
    igual(1, count($enviados));
    igual(['muzamil@exemplo.com'], $enviados[0]['para']);
    igual(['michael@exemplo.com'], array_values(array_filter([$enviados[0]['o']['responder_a']['email'] ?? null])));
    verdade((bool) preg_match('/RTM_Sales_SAP_Upload_2026-11-25_lote\d+\.xlsx/', $enviados[0]['anexos'][0]), 'Excel em anexo');
    verdade(strpos($enviados[0]['html'], 'Rota 1011') !== false, 'rotas por sincronizar no email');
    verdade((bool) preg_match('#app/sap-carregado\.php\?l=(\d+)&amp;c=([a-f0-9]{40})#', $enviados[0]['html'], $m), 'ligação no email');
    $lote = naval_sap_lotes()[naval_sap_procurar(naval_sap_lotes(), (int) $m[1])];
    verdade(!empty($lote['automatico']) && $lote['por'] === 'sistema' && !empty($lote['envio']['ok']));
    verdade(strpos((string) json_encode(naval_sap_lotes()), $m[2]) === false, 'o código da ligação não fica guardado');
    verdade(!isset(naval_sap_envio_estado()['codigo']), 'nem no estado, depois de enviado');
    // a ligação: código errado recusa; certo confirma com a referência e avisa o Michael no mesmo assunto
    lanca(403, function () use ($m) { naval_sap_envio_confirmar_ligacao((int) $m[1], str_repeat('0', 40), 'Muzamil', 'SAP-1'); });
    lanca(400, function () use ($m, $enviar) { naval_sap_envio_confirmar_ligacao((int) $m[1], $m[2], 'Muzamil', '', $enviar); });
    $c = naval_sap_envio_confirmar_ligacao((int) $m[1], $m[2], 'Muzamil', 'SAP-5100023', $enviar);
    igual(['carregado', 'SAP-5100023', 'Muzamil (email)'], [$c['estado'], $c['carregado']['referencia'], $c['carregado']['por_nome']]);
    igual(2, count($enviados));
    igual(['michael@exemplo.com', 'muzamil@exemplo.com'], $enviados[1]['para']);
    igual('RE: ' . $enviados[0]['assunto'], $enviados[1]['assunto']);
    lanca(403, function () use ($m) { naval_sap_envio_confirmar_ligacao((int) $m[1], $m[2], 'Muzamil', 'SAP-1'); });
    igual(null, naval_sap_envio_lote_da_ligacao((int) $m[1], $m[2]), 'ligação de uso único');
}); 
});

teste('envio automático para o SAP: email falhado tenta de novo sem novo lote; sem vendas num dia de trabalho segue a nota', function () { sap_isolado(function () {
    $dia = '2026-11-26';
    $cfg = naval_sap_config();
    $cfg['rotas'] = ['1009' => ['local' => 'RTM1']];
    $cfg['envio'] = ['activo' => true, 'hora' => '16:00', 'para' => ['muzamil@exemplo.com'], 'avisar' => []];
    naval_json_gravar(naval_sap_pasta() . '/config.json', $cfg);
    $n = count(naval_sap_lotes());
    $vez = 0;
    $enviados = [];
    $enviar = function ($para, $assunto, $html, $anexos) use (&$vez, &$enviados) {
        $vez++;
        $enviados[] = ['assunto' => $assunto, 'anexos' => count($anexos)];
        return $vez === 1 ? ['ok' => false, 'erro' => 'falha de rede'] : ['ok' => true, 'erro' => ''];
    };
    $dados = ['vendas' => [['documento' => 'FR 901', 'data' => $dia, 'hora' => '10:00', 'rota' => '1009', 'tipo' => 'Factura-recibo', 'total' => 114, 'pagamentos' => []]],
        'artigos' => [['documento' => 'FR 901', 'codigo' => '10000332', 'artigo' => 'Fraldas', 'quantidade' => 1, 'valor_sem_iva' => 100, 'iva' => 14, 'unidade' => 'CAR']], 'promotores' => []];
    $o = ['vendas' => $dados, 'origens' => ['rotas' => [], 'artigos' => []], 'por_sincronizar' => [], 'enviar' => $enviar];
    igual(false, naval_sap_envio_ciclo(strtotime($dia . ' 16:00'), $o)['email']);
    igual(true, naval_sap_envio_ciclo(strtotime($dia . ' 16:01'), $o)['email']);
    igual($n + 1, count(naval_sap_lotes()), 'um só lote');
    igual([1, 1], array_column($enviados, 'anexos'));
    // dia seguinte sem vendas por enviar (o FR 901 já foi): nota sem anexo
    $r = naval_sap_envio_ciclo(strtotime('2026-11-27 16:00'), $o + ['vendas' => ['vendas' => [], 'artigos' => [], 'promotores' => []]]);
    igual([null, true], [$r['lote'], $r['email']]);
    igual(0, end($enviados)['anexos']);
    verdade(strpos(end($enviados)['assunto'], 'no file / sem ficheiro') !== false);
}); 
});

teste('envio automático para o SAP: o email de teste segue para os dois endereços sem emitir lote nem marcar documentos', function () { sap_isolado(function () {
    $cfg = naval_sap_config();
    $cfg['rotas'] = ['1009' => ['local' => 'RTM1']];
    naval_json_gravar(naval_sap_pasta() . '/config.json', $cfg);
    $enviados = [];
    $enviar = function ($para, $assunto, $html, $anexos) use (&$enviados) {
        $enviados[] = ['para' => array_column($para, 'email'), 'assunto' => $assunto, 'html' => $html, 'anexos' => array_column($anexos, 'nome')];
        return ['ok' => true, 'erro' => ''];
    };
    $dia = date('Y-m-d');
    $dados = ['vendas' => [['documento' => 'FR 950', 'data' => $dia, 'hora' => '10:00', 'rota' => '1009', 'tipo' => 'Factura-recibo', 'total' => 114, 'pagamentos' => []]],
        'artigos' => [['documento' => 'FR 950', 'codigo' => '10000332', 'artigo' => 'Fraldas', 'quantidade' => 1, 'valor_sem_iva' => 100, 'iva' => 14, 'unidade' => 'CAR']], 'promotores' => []];
    $r = naval_sap_envio_teste(['para' => 'sap@exemplo.com', 'avisar' => 'equipa@exemplo.com'], ['login' => 'fin'], ['vendas' => $dados, 'origens' => ['rotas' => [], 'artigos' => []], 'enviar' => $enviar]);
    igual([true, true, 1], [$r['para']['ok'], $r['avisar']['ok'], $r['ficheiro']['documentos']]);
    igual([['sap@exemplo.com'], ['equipa@exemplo.com']], array_column($enviados, 'para'));
    igual(['TESTE_NAO_CARREGAR_RTM_Sales_SAP_Upload_' . $dia . '.xlsx'], $enviados[0]['anexos']);
    verdade(strpos($enviados[0]['assunto'], '[TEST / TESTE]') === 0 && strpos($enviados[0]['html'], 'sap-carregado.php') === false, 'teste sem ligação de confirmação');
    igual([], naval_sap_lotes(), 'nenhum lote emitido');
    // sem ligação ao Pinguim BO o email segue na mesma (testa o correio)
    $r = naval_sap_envio_teste(['para' => 'sap@exemplo.com'], ['login' => 'fin'], ['vendas' => null, 'enviar' => $enviar]);
    igual([true, null, true], [$r['para']['ok'], $r['avisar'], $r['sem_ligacao']]);
    lanca(400, function () { naval_sap_envio_teste(['para' => ''], ['login' => 'fin']); });
}); });
