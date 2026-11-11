<?php
// Actualizador: avisos de certificados a expirar, activação de extensões no php.ini e endereços vigiados.
naval_servico('actualizacao');

teste('Certificados: um aviso por limite (30, 14, 7, 3, 1 dias), sem repetir; expirado', function () {
    $avisados = [];
    $c = ['endereco' => 'geo.naval.co.ao:2051', 'dias' => 25, 'expira' => '2026-11-27 10:00', 'emitente' => "Let's Encrypt", 'erro' => ''];
    igual(1, count(naval_act_avisos_certificados([$c], $avisados)), 'aviso a 30 dias');
    igual(0, count(naval_act_avisos_certificados([$c], $avisados)), 'repetido');
    $c['dias'] = 6;
    $a = naval_act_avisos_certificados([$c], $avisados);
    igual(1, count($a), 'aviso a 7 dias');
    verdade(strpos($a[0], '6 dia(s)') !== false, $a[0]);
    $c['dias'] = -1;
    $a = naval_act_avisos_certificados([$c], $avisados);
    verdade(strpos($a[0], 'EXPIROU') !== false, 'expirado');
    // certificado renovado (outra data de fim): os avisos voltam a contar do zero
    $c = ['dias' => 85, 'expira' => '2027-01-26 10:00'] + $c;
    igual(0, count(naval_act_avisos_certificados([$c], $avisados)), 'renovado sem aviso');
    igual(0, count(naval_act_avisos_certificados([['dias' => null] + $c], $avisados)), 'sem ligação: sem aviso de validade');
});

teste('php.ini: activa descomentando (Windows e Linux) ou acrescentando, mantendo as quebras de linha', function () {
    igual("a=1\r\nextension=gd\r\nb=2", naval_act_ini_activar("a=1\r\n;extension=gd\r\nb=2", 'gd'));
    igual("extension=php_curl.dll\n", naval_act_ini_activar(";extension=php_curl.dll\n", 'curl'));
    $n = naval_act_ini_activar("x=1\n;extension=gd2\n", 'gd');
    verdade(strpos($n, ";extension=gd2\n") !== false && substr($n, -13) === "extension=gd\n", 'gd2 não é gd: ' . $n);
});

teste('Certificados: endereços vigiados (só HTTPS, porta por omissão 443, sem repetidos)', function () {
    igual(['geo.naval.co.ao:2051', 'id.naval.co.ao:443', 'boapp.naval.co.ao:3186'],
        naval_act_enderecos_tls(['https://geo.naval.co.ao:2051', 'https://id.naval.co.ao/a/', ' id.naval.co.ao ', 'http://x.pt', '', 'boapp.naval.co.ao:3186', 'HTTPS://GEO.naval.co.ao:2051/reports/']));
});

teste('Actualizações: estado da instalação depois da decisão (na fila, a instalar, esta noite, com resultado)', function () {
    $agora = strtotime('2026-11-03 11:42:10');
    igual('11:45', date('H:i', naval_act_proximo_ciclo($agora)), 'próximo ciclo da tarefa de 5 em 5 minutos');
    $dec = ['decisao' => 'aprovada', 'em' => '2026-11-03 11:41:00', 'urgente' => true];
    igual('na_fila', naval_act_fila($dec, null, $agora));
    igual('a_instalar', naval_act_fila($dec + ['tentada_em' => '2026-11-03 11:40:00'], null, $agora + 120));
    igual(null, naval_act_fila($dec + ['tentada_em' => '2026-11-03 11:40:00'], ['accao' => 'actualizada', 'data' => '2026-11-03 11:45:20'], $agora + 300));
    igual('na_fila', naval_act_fila($dec, ['accao' => 'conflito', 'data' => '2026-11-03 11:35:00'], $agora), 'decisão nova depois da paragem');
    igual('esta_noite', naval_act_fila(['decisao' => 'aprovada', 'em' => '2026-11-03 11:41:00'], null, $agora));
    igual(null, naval_act_fila(['decisao' => 'reprovada', 'em' => '2026-11-03 11:41:00'], null, $agora));
});

teste('GitHub App: desvio de hora medido pela resposta do GitHub (só acima de 30 s)', function () {
    naval_act_desvio(0);
    igual(false, naval_act_medir_desvio(['date' => gmdate('D, d M Y H:i:s', time() + 10) . ' GMT']), '10 s não conta');
    igual(true, naval_act_medir_desvio(['date' => gmdate('D, d M Y H:i:s', time() - 169) . ' GMT']), 'servidor 2 min 49 s adiantado');
    verdade(abs(naval_act_desvio() + 169) <= 2, 'desvio guardado');
    igual(false, naval_act_medir_desvio([]), 'sem data');
    naval_act_desvio(0);
});

teste('ensaio no Windows: mudar o nome da pasta tenta de novo com espera; se nunca der, diz o erro do sistema', function () {
    $b = sys_get_temp_dir() . '/ren-' . getmypid();
    @mkdir($b . '/a/sub', 0770, true);
    file_put_contents($b . '/a/sub/x.txt', 'x');
    $esperas = [];
    $esperar = function (float $s) use (&$esperas) { $esperas[] = $s; };
    naval_act_renomear_pasta($b . '/a', $b . '/b', $esperar);
    verdade(is_file($b . '/b/sub/x.txt') && !is_dir($b . '/a'), 'mudou');
    igual([], $esperas, 'sem espera quando corre bem');
    // destino ocupado (pasta com ficheiros): o rename falha sempre; espera 0,5 + 1 + 2 + 4 + 8 + 15 s e explica
    @mkdir($b . '/c', 0770, true);
    file_put_contents($b . '/c/y.txt', 'y');
    $msg = '';
    try {
        naval_act_renomear_pasta($b . '/b', $b . '/c', $esperar);
    } catch (NavalActualizacaoErro $e) {
        $msg = $e->getMessage();
    }
    igual([0.5, 1.0, 2.0, 4.0, 8.0, 15.0], $esperas);
    verdade(strpos($msg, 'Não foi possível mudar b para c (') === 0, $msg);
    verdade(is_file($b . '/b/sub/x.txt') && is_file($b . '/c/y.txt'), 'nada se perdeu');
    naval_act_limpar_pasta($b);
});

teste('ensaio: «Repetir no ensaio» só depois de uma falha; recomeça as tentativas', function () {
    $f = naval_act_pasta() . '/ensaio.json';
    naval_json_gravar($f, ['versao' => 'v9', 'sha256' => 'x', 'instalada_em' => '2026-11-11 21:10:00', 'resultado' => 'ok', 'falhas' => []]);
    lanca(409, function () { naval_act_ensaio_repetir('henrique'); });
    naval_json_gravar($f, ['versao' => 'v9', 'sha256' => '', 'instalada_em' => '2026-11-11 21:10:00', 'resultado' => 'falhou', 'falhas' => ['Não foi possível activar a pasta nova do ensaio.'], 'tentativas' => 3]);
    naval_act_ensaio_repetir('henrique');
    $e = naval_act_ensaio_estado();
    igual(['v9', '', 0], [$e['versao'], $e['resultado'], $e['tentativas']]);
    @unlink($f);
});
