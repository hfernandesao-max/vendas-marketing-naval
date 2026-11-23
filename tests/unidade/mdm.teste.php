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
$windows = DIRECTORY_SEPARATOR === '\\';
$abrirProcesso = function ($comando, $descritores, &$tubos, $ambiente = null) use ($windows) {
    if ($windows && PHP_VERSION_ID >= 70400) {
        // nosemgrep: exec-use -- testes automáticos: argumentos fixos ou com escapeshellarg
        return proc_open($comando, $descritores, $tubos, null, $ambiente, ['bypass_shell' => true]);
    }
    // nosemgrep: exec-use -- testes automáticos: argumentos fixos ou com escapeshellarg
    return proc_open($comando, $descritores, $tubos, null, $ambiente);
};
$nulo = $windows ? 'NUL' : '/dev/null';
$comandoServidor = escapeshellarg(PHP_BINARY) . ' -S 127.0.0.1:' . $mdmPorta . ' ' . escapeshellarg(dirname(__DIR__) . '/dados/mdm-simulado.php');
if (!$windows) $comandoServidor = 'exec ' . $comandoServidor;
$mdmSrv = $abrirProcesso($comandoServidor, [1 => ['file', $nulo, 'w'], 2 => ['file', $nulo, 'w']], $mdmTubos);
verdade(is_resource($mdmSrv), 'não foi possível iniciar o servidor MDM simulado');
for ($i = 0; $i < 50; $i++) {
    $socket = @fsockopen('127.0.0.1', $mdmPorta);
    if ($socket) {
        fclose($socket);
        break;
    }
    usleep(100000);
}
$bdMdm = $mdmAmb . '/atv.sqlite';
(new PDO('sqlite:' . $bdMdm))->exec((string) file_get_contents(dirname(__DIR__) . '/dados/equipamentos-sqlite.sql'));
$executarPhp = function (string $script, array $variaveis) use ($abrirProcesso): array {
    $ambiente = getenv();
    foreach ($variaveis as $nome => $valor) $ambiente[$nome] = $valor;
    $comando = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script);
    $processo = $abrirProcesso($comando, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tubos, $ambiente);
    verdade(is_resource($processo), 'não foi possível executar ' . basename($script));
    fclose($tubos[0]);
    $saida = stream_get_contents($tubos[1]);
    $erro = stream_get_contents($tubos[2]);
    fclose($tubos[1]);
    fclose($tubos[2]);
    return [proc_close($processo), (string) $saida . (string) $erro];
};
$correrMdm = function (string $refresh, ?string $tokenGuardado = null) use ($mdmAmb, $mdmPorta, $bdMdm, $executarPhp): array {
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
    return $executarPhp(dirname(__DIR__, 2) . '/ferramentas/verificar-mdm.php', ['NAVAL_ENV' => $mdmAmb . '/.env']);
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

teste('MDM: configurar troca o código pelo refresh token, grava o .env com cópia e verifica, sem mostrar segredos', function () use ($mdmAmb, $mdmPorta, $bdMdm, $executarPhp) {
    $env = $mdmAmb . '/.env';
    file_put_contents($env, implode("\n", ['DADOS_PRIVADOS=' . $mdmAmb . '/privado', 'JWT_SEGREDO=x', 'ATV_DB_DSN=sqlite:' . $bdMdm,
        'MDM_API_BASE=http://127.0.0.1:' . $mdmPorta, 'MDM_ACCOUNTS_BASE=http://127.0.0.1:' . $mdmPorta, '# MDM_OAUTH_CLIENT_ID=', 'OUTRA=1']) . "\n");
    $correr = function (string $codigo) use ($env, $executarPhp) {
        return $executarPhp(dirname(__DIR__, 2) . '/ferramentas/configurar-mdm.php', [
            'NAVAL_ENV' => $env,
            'MDM_CONFIG_CLIENT_ID' => 'cliente-teste',
            'MDM_CONFIG_CLIENT_SECRET' => 'segredo-xyz',
            'MDM_CONFIG_CODIGO' => $codigo,
        ]);
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
    if (is_resource($mdmSrv)) proc_terminate($mdmSrv);
    foreach (['/privado/mdm/token.json', '/.env', '/atv.sqlite'] as $f) @unlink($mdmAmb . $f);
    verdade(true);
}];

teste('MDM: última localização do equipamento (tempos em milissegundos), sem coordenadas inválidas', function () {
    $j = ['locations' => [
        ['latitude' => '-8.8391', 'longitude' => '13.2894', 'located_time' => 1763622000000],
        ['latitude' => '-8.8401234', 'longitude' => '13.2904567', 'located_time' => 1763625600000],
        ['latitude' => 'x', 'longitude' => '13.2', 'located_time' => 1763629200000],
    ]];
    $l = naval_mdm_ultima_localizacao($j);
    igual([-8.840123, 13.290457], [$l['lat'], $l['lng']], 'a mais recente válida');
    igual(date('Y-m-d H:i:s', 1763625600), $l['em']);
    igual(null, naval_mdm_ultima_localizacao(['locations' => []]));
    lanca(0, function () { try { naval_mdm_localizacao('1; DROP'); } catch (InvalidArgumentException $e) { throw new NavalErro('id', 0); } });
});
