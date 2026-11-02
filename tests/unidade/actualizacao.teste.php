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
