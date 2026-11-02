<?php
// Apache simulado para os testes do actualizador (ACTUALIZACAO_APACHE_CMD): «-t» valida a configuração do portal (e o
// httpd.conf de ACTUALIZACAO_APACHE_CONF), recusando-a se tiver ERRO_DE_TESTE; «-k restart» regista o reinício.
$raiz = (string) getenv('HTTPD_SIMULADO_RAIZ');
$args = array_slice($argv, 1);
if (in_array('-t', $args, true)) {
    foreach ([$raiz . '/ferramentas/apache/portal-rtm.conf', (string) getenv('HTTPD_SIMULADO_CONF')] as $f) {
        if ($f !== '' && is_file($f) && strpos((string) file_get_contents($f), 'ERRO_DE_TESTE') !== false) {
            fwrite(STDERR, "AH00526: Syntax error on line 1 of $f\n");
            exit(1);
        }
    }
    echo "Syntax OK\n";
    exit(0);
}
if (in_array('restart', $args, true)) {
    file_put_contents(dirname($raiz) . '/httpd-reinicios.log', date('c') . ' ' . implode(' ', $args) . "\n", FILE_APPEND);
    exit(0);
}
exit(2);
