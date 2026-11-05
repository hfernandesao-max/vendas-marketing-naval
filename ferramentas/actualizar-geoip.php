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
 * Não é preciso agendar: a tarefa automática do portal instala-a quando falta e renova-a todos os meses, de
 * madrugada (naval_geoip_manter, GEOIP_AUTOMATICO=0 desliga). Esta ferramenta serve para forçar ou instalar à mão.
 *
 * O ficheiro novo só substitui o actual depois de ser validado (abre, tem registos e localiza um IP de teste).
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Acesso proibido.');
}
require_once __DIR__ . '/../nucleo/arranque.php';
require_once __DIR__ . '/../nucleo/seguranca/geoip.php';

$i = array_search('--ficheiro', $argv, true);
echo $i === false ? "A descarregar a base de dados de localização do mês (DB-IP)…\n" : '';
$r = naval_geoip_instalar($i !== false ? (string) ($argv[$i + 1] ?? '') : null);
if (!$r['ok']) {
    fwrite(STDERR, $r['mensagem'] . "\n");
    exit(2);
}
echo $r['mensagem'] . "\n";
