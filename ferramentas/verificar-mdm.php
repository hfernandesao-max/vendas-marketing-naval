<?php
/**
 * Verificação da ligação ao ManageEngine MDM Plus (SÓ LEITURA: não envia ordens ao MDM nem altera a base de dados):
 *
 *     php ferramentas/verificar-mdm.php
 *
 *  1. chaves do .env (só os nomes; nunca os valores);
 *  2. token OAuth renovado na Zoho com o refresh token;
 *  3. lista dos equipamentos Android no MDM;
 *  4. cruzamento com os smartphones do portal (ID do MDM, IMEI, n.º de série) e, para os que não têm o ID do MDM
 *     certo, as instruções UPDATE a executar no SQL Server (impressas, não executadas);
 *  5. ligação «Abrir no MDM» (MDM_URL_EQUIPAMENTO).
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . '/nucleo/arranque.php';
naval_servico('mdm');
naval_servico('equipamentos');

$falhas = 0;
$linha = function (bool $ok, string $txt) use (&$falhas) {
    if (!$ok) $falhas++;
    echo ($ok ? '  ok     ' : '  FALHA  ') . $txt . "\n";
};
echo "MDM Plus: verificação da ligação (só leitura)\n";

$falta = naval_config_em_falta(NAVAL_MDM_CHAVES);
$linha(!$falta, $falta ? '.env sem ' . implode(', ', $falta) : 'chaves do .env presentes');
if ($falta) exit(1);
echo '         API: ' . naval_config('MDM_API_BASE', 'https://mdm.manageengine.com') . ' · OAuth: ' . naval_config('MDM_ACCOUNTS_BASE', 'https://accounts.zoho.com') . "\n";

try {
    naval_mdm_token(true);
    $linha(true, 'token OAuth renovado na Zoho');
} catch (Throwable $e) {
    $linha(false, 'token OAuth: ' . $e->getMessage());
    echo "\n  Confirme o Client ID, o Client Secret e o refresh token (Self Client na consola da Zoho, centro de dados .com)\n  e os âmbitos MDMOnDemand.MDMInventory.READ e MDMOnDemand.MDMDeviceMgmt.READ.\n";
    exit(1);
}

try {
    $mdm = naval_mdm_dispositivos(['platform' => 2]);
    $linha(true, 'lista de equipamentos Android no MDM: ' . count($mdm));
} catch (Throwable $e) {
    $linha(false, 'lista de equipamentos: ' . $e->getMessage());
    exit(1);
}

if (!naval_atv_configurada()) {
    echo "  aviso  a base de dados do módulo Equipamentos não está configurada: sem cruzamento com o portal\n";
} else {
    try {
        $portal = naval_atv_q("SELECT IdActivo, NumSerie, Imei1, Imei2, MdmDeviceId FROM ATV_ACTIVO WHERE Tipo = 'SMT' AND Estado <> 'ABATIDO' ORDER BY IdActivo");
        $cruz = naval_mdm_cruzar($portal, $mdm);
        $achados = array_filter($cruz, function ($c) { return $c['mdm'] !== null; });
        $linha(count($achados) === count($cruz), 'smartphones do portal encontrados no MDM: ' . count($achados) . ' de ' . count($cruz));
        $updates = [];
        foreach ($cruz as $c) {
            if (!$c['mdm']) {
                echo '         ' . $c['id'] . ": não encontrado no MDM (nem pelo ID, nem pelo IMEI, nem pelo n.º de série)\n";
                continue;
            }
            $d = $c['mdm'];
            echo '         ' . $c['id'] . ' → ' . ($d['device_name'] ?? '') . ' (ID ' . $d['device_id'] . ', pelo ' . $c['como'] . ')' . ($c['id_certo'] ? '' : ' · ID do MDM por gravar no portal') . "\n";
            if (!$c['id_certo'] && preg_match('/^[0-9A-Za-z_-]{1,30}$/', (string) $d['device_id'])) {
                $updates[] = "UPDATE dbo.ATV_ACTIVO SET MdmDeviceId = '" . $d['device_id'] . "' WHERE IdActivo = '" . $c['id'] . "';";
            }
        }
        if ($updates) {
            echo "\n  Para gravar o ID do MDM no portal, executar no SQL Server (base de dados do módulo):\n\n" . implode("\n", $updates) . "\n\n";
        }
    } catch (Throwable $e) {
        $linha(false, 'cruzamento com o portal: ' . $e->getMessage());
    }
}

$url = naval_config('MDM_URL_EQUIPAMENTO');
if ($url === '') {
    echo "  aviso  MDM_URL_EQUIPAMENTO por preencher: a ficha não mostra «Abrir no MDM»\n";
} else {
    $linha(strpos($url, '{id}') !== false && strpos($url, 'https://') === 0, 'ligação «Abrir no MDM»: ' . ($mdm ? str_replace('{id}', rawurlencode((string) ($mdm[0]['device_id'] ?? '')), $url) : $url));
}

echo $falhas ? "\n$falhas verificação(ões) falharam.\n" : "\nLigação ao MDM Plus a funcionar.\n";
exit($falhas ? 1 : 0);
