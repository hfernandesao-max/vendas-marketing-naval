<?php
// Ligação ao MDM Plus (só leitura) contra uma API simulada: token OAuth, 401 com nova tentativa, paginação,
// cruzamento com os smartphones do portal e instruções UPDATE do ID do MDM. Corre ferramentas/verificar-mdm.php.
naval_servico('mdm');

teste('MDM: cruzamento pelo ID do MDM, pelo IMEI e pelo n.º de série', function () {
    $portal = [
        ['IdActivo' => 'NAV-SMT-0001', 'MdmDeviceId' => '10', 'Imei1' => null, 'Imei2' => null, 'NumSerie' => null],
        ['IdActivo' => 'NAV-SMT-0002', 'MdmDeviceId' => null, 'Imei1' => '353326512164429', 'Imei2' => null, 'NumSerie' => null],
        ['IdActivo' => 'NAV-SMT-0003', 'MdmDeviceId' => '99', 'Imei1' => null, 'Imei2' => null, 'NumSerie' => 'abc'],
        ['IdActivo' => 'NAV-SMT-0004', 'MdmDeviceId' => null, 'Imei1' => '000000000000000', 'Imei2' => null, 'NumSerie' => null],
    ];
    $mdm = [['device_id' => '10', 'imei' => ['1']], ['device_id' => '20', 'imei' => ['353326512164429']], ['device_id' => '30', 'serial_number' => 'ABC', 'imei' => '7']];
    $r = naval_mdm_cruzar($portal, $mdm);
    igual(['ID do MDM', true], [$r[0]['como'], $r[0]['id_certo']]);
    igual(['IMEI', '20', false], [$r[1]['como'], $r[1]['mdm']['device_id'], $r[1]['id_certo']]);
    igual(['n.º de série', '30', false], [$r[2]['como'], $r[2]['mdm']['device_id'], $r[2]['id_certo']]);
    igual(null, $r[3]['mdm']);
});

if (!function_exists('curl_init') || !function_exists('proc_open')) return;
$mdmAmb = sys_get_temp_dir() . '/naval-mdm-' . getmypid();
@mkdir($mdmAmb . '/privado', 0770, true);
$mdmPorta = 18000 + getmypid() % 1000;
// comando em texto (o proc_open com lista só existe a partir do PHP 7.4); exec para o processo ser o próprio servidor
$mdmSrv = proc_open('exec ' . escapeshellarg(PHP_BINARY) . ' -S 127.0.0.1:' . $mdmPorta . ' ' . escapeshellarg(dirname(__DIR__) . '/dados/mdm-simulado.php'), [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $mdmTubos);
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $mdmPorta); $i++) usleep(100000);
$bdMdm = $mdmAmb . '/atv.sqlite';
(new PDO('sqlite:' . $bdMdm))->exec((string) file_get_contents(dirname(__DIR__) . '/dados/equipamentos-sqlite.sql'));
$correrMdm = function (string $refresh, ?string $tokenGuardado = null) use ($mdmAmb, $mdmPorta, $bdMdm): array {
    @unlink($mdmAmb . '/privado/mdm/token.json');
    if ($tokenGuardado !== null) {
        @mkdir($mdmAmb . '/privado/mdm', 0770, true);
        file_put_contents($mdmAmb . '/privado/mdm/token.json', json_encode(['access_token' => $tokenGuardado, 'expira' => time() + 3000]));
    }
    file_put_contents($mdmAmb . '/.env', implode("\n", [
        'DADOS_PRIVADOS=' . $mdmAmb . '/privado', 'JWT_SEGREDO=' . bin2hex(random_bytes(32)), 'ATV_DB_DSN=sqlite:' . $bdMdm,
        'MDM_API_BASE=http://127.0.0.1:' . $mdmPorta, 'MDM_ACCOUNTS_BASE=http://127.0.0.1:' . $mdmPorta,
        'MDM_OAUTH_CLIENT_ID=cliente-teste', 'MDM_OAUTH_CLIENT_SECRET=segredo-teste', 'MDM_OAUTH_REFRESH_TOKEN=' . $refresh,
        'MDM_URL_EQUIPAMENTO=https://mdm.manageengine.com/webclient#/dispositivo/{id}',
    ]) . "\n");
    $saida = [];
    exec('NAVAL_ENV=' . escapeshellarg($mdmAmb . '/.env') . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__, 2) . '/ferramentas/verificar-mdm.php') . ' 2>&1', $saida, $codigo);
    return [$codigo, implode("\n", $saida)];
};

teste('MDM: ligação, lista, cruzamento com o portal e UPDATE do ID em falta (API simulada)', function () use ($correrMdm) {
    [$codigo, $txt] = $correrMdm('rt-teste');
    verdade(strpos($txt, 'ok     token OAuth renovado na Zoho') !== false, $txt);
    verdade(strpos($txt, 'equipamentos Android no MDM: 3') !== false, $txt);
    verdade(strpos($txt, 'encontrados no MDM: 2 de 3') !== false, $txt);
    verdade(strpos($txt, "UPDATE dbo.ATV_ACTIVO SET MdmDeviceId = '236708000000175300' WHERE IdActivo = 'NAV-SMT-0002';") !== false, $txt);
    verdade(strpos($txt, "IdActivo = 'NAV-SMT-0001'") === false, 'NAV-SMT-0001 já tem o ID certo');
    verdade(strpos($txt, 'NAV-SMT-0003: não encontrado no MDM') !== false, $txt);
    verdade(strpos($txt, 'segredo-teste') === false && strpos($txt, 'rt-teste') === false, 'não mostra segredos');
    igual(1, $codigo, 'falha porque um smartphone não está no MDM');
});

teste('MDM: token guardado e recusado (401) → renova e repete; refresh token errado → falha clara', function () use ($correrMdm) {
    [, $txt] = $correrMdm('rt-teste', 'at-velho');
    verdade(strpos($txt, 'equipamentos Android no MDM: 3') !== false, $txt);
    [$codigo, $txt] = $correrMdm('rt-errado');
    igual(1, $codigo);
    verdade(strpos($txt, 'invalid_code') !== false, $txt);
});

teste('MDM: configurar troca o código pelo refresh token, grava o .env com cópia e verifica, sem mostrar segredos', function () use ($mdmAmb, $mdmPorta, $bdMdm) {
    $env = $mdmAmb . '/.env';
    file_put_contents($env, implode("\n", ['DADOS_PRIVADOS=' . $mdmAmb . '/privado', 'JWT_SEGREDO=x', 'ATV_DB_DSN=sqlite:' . $bdMdm,
        'MDM_API_BASE=http://127.0.0.1:' . $mdmPorta, 'MDM_ACCOUNTS_BASE=http://127.0.0.1:' . $mdmPorta, '# MDM_OAUTH_CLIENT_ID=', 'OUTRA=1']) . "\n");
    $correr = function (string $codigo) use ($env) {
        $saida = [];
        exec('NAVAL_ENV=' . escapeshellarg($env) . ' MDM_CONFIG_CLIENT_ID=cliente-teste MDM_CONFIG_CLIENT_SECRET=segredo-xyz MDM_CONFIG_CODIGO=' . escapeshellarg($codigo)
            . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__, 2) . '/ferramentas/configurar-mdm.php') . ' 2>&1', $saida, $c);
        return [$c, implode("\n", $saida)];
    };
    [$c, $txt] = $correr('codigo-velho');
    igual(1, $c);
    verdade(strpos($txt, 'invalid_code') !== false && strpos($txt, 'gere outro') !== false, $txt);
    verdade(strpos((string) file_get_contents($env), 'segredo-xyz') === false, 'código recusado: .env sem alterações');
    [, $txt] = $correr('codigo-teste');
    verdade(strpos($txt, 'código trocado pelo refresh token') !== false, $txt);
    verdade(strpos($txt, 'token OAuth renovado na Zoho') !== false, 'verificação corrida a seguir');
    verdade(strpos($txt, 'segredo-xyz') === false && strpos($txt, 'rt-teste') === false, 'sem segredos no ecrã');
    $novo = (string) file_get_contents($env);
    verdade(strpos($novo, "MDM_OAUTH_CLIENT_ID=cliente-teste\n") !== false && strpos($novo, "MDM_OAUTH_REFRESH_TOKEN=rt-teste\n") !== false && strpos($novo, "OUTRA=1\n") !== false, $novo);
    igual(1, substr_count($novo, 'MDM_OAUTH_CLIENT_ID'), 'a linha comentada passou a ser a chave');
    verdade(count(glob($env . '.antes-mdm-*') ?: []) >= 1, 'cópia de segurança do .env');
    foreach (glob($env . '.antes-mdm-*') ?: [] as $f) unlink($f);
});

$GLOBALS['casos'][] = ['MDM: fim do servidor simulado', function () use ($mdmSrv, $mdmAmb) {
    proc_terminate($mdmSrv);
    foreach (['/privado/mdm/token.json', '/.env', '/atv.sqlite'] as $f) @unlink($mdmAmb . $f);
    verdade(true);
}];
