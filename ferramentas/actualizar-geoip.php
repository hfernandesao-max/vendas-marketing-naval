<?php
/**
 * Actualiza a base de dados de localização dos IP (registos de actividade: país e cidade de cada entrada).
 *
 * Fonte por omissão: DB-IP «IP to City Lite» (formato MaxMind DB, gratuita, sem conta, licença CC BY 4.0 —
 * obriga a indicar «IP Geolocation by DB-IP», o que o portal faz na página Acessos). Publicada no início
 * de cada mês. Alternativa: MaxMind GeoLite2 City (conta gratuita): descarregar o .mmdb e usar --ficheiro.
 *
 * Uso (na pasta do site, como administrador do servidor):
 *   php ferramentas/actualizar-geoip.php                       (descarrega a edição do mês, ou a do mês anterior)
 *   php ferramentas/actualizar-geoip.php --ficheiro C:\caminho\GeoLite2-City.mmdb   (instala um ficheiro local)
 *
 * Agendar no Windows (Agendador de Tarefas), no dia 5 de cada mês:
 *   schtasks /Create /TN "Portal RTM - GeoIP" /SC MONTHLY /D 5 /ST 03:15 /RU SYSTEM ^
 *     /TR "C:\php\php.exe C:\Apache24\htdocs\geo\ferramentas\actualizar-geoip.php"
 *
 * O ficheiro novo só substitui o actual depois de ser validado (abre, tem registos e localiza um IP de teste).
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Acesso proibido.');
}
require_once __DIR__ . '/../nucleo/arranque.php';
require_once __DIR__ . '/../nucleo/seguranca/geoip.php';

$destino = naval_geoip_ficheiro();
$pasta = dirname($destino);
if (!is_dir($pasta) && !mkdir($pasta, 0750, true)) {
    fwrite(STDERR, "Não foi possível criar a pasta {$pasta}\n");
    exit(1);
}
$tmp = $destino . '.novo';

$i = array_search('--ficheiro', $argv, true);
if ($i !== false) {
    $orig = (string) ($argv[$i + 1] ?? '');
    if (!is_file($orig) || !copy($orig, $tmp)) {
        fwrite(STDERR, "Ficheiro não encontrado: {$orig}\n");
        exit(1);
    }
    $origem = basename($orig);
} else {
    $origem = '';
    foreach ([date('Y-m'), date('Y-m', strtotime('first day of last month'))] as $mes) {
        $url = "https://download.db-ip.com/free/dbip-city-lite-{$mes}.mmdb.gz";
        echo "A descarregar {$url}\n";
        $ctx = stream_context_create(['http' => ['timeout' => 300, 'user_agent' => 'PortalRTM-GeoIP/1.0', 'follow_location' => 1],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $in = @fopen('compress.zlib://' . $url, 'rb', false, $ctx);
        if (!$in) continue;
        $out = fopen($tmp, 'wb');
        $n = stream_copy_to_stream($in, $out);
        fclose($in);
        fclose($out);
        if ($n > 1000000) {
            $origem = basename($url);
            break;
        }
    }
    if ($origem === '') {
        @unlink($tmp);
        fwrite(STDERR, "Não foi possível descarregar a base de dados (rede, proxy ou edição ainda não publicada).\n");
        exit(2);
    }
}

// Validação antes de substituir
try {
    $bd = new NavalMmdb($tmp);
    $ok = (int) ($bd->meta['node_count'] ?? 0) > 1000 && $bd->procurar('8.8.8.8') !== null;
    $tipo = (string) ($bd->meta['database_type'] ?? '?');
    $data = date('d-m-Y', (int) ($bd->meta['build_epoch'] ?? 0));
    unset($bd);
} catch (Throwable $e) {
    $ok = false;
    $tipo = $data = '';
}
if (!$ok) {
    @unlink($tmp);
    fwrite(STDERR, "O ficheiro descarregado não é uma base de dados de localização válida. A actual mantém-se.\n");
    exit(3);
}
if (is_file($destino)) {
    if (is_file($destino . '.anterior')) unlink($destino . '.anterior');
    rename($destino, $destino . '.anterior');
}
if (!rename($tmp, $destino)) {
    fwrite(STDERR, "Não foi possível instalar {$destino}\n");
    exit(4);
}
naval_registar('geoip_actualizado', ['origem' => $origem, 'tipo' => $tipo, 'edicao' => $data], 'sistema');
echo "Instalado: {$destino} ({$tipo}, edição de {$data})\n";
