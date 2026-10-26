<?php
// Configuração, texto, armazenamento e cliente HTTP.

teste('o .env é lido uma vez, com comentários, aspas, vazios e «=» no valor', function () {
    igual('com aspas', naval_config('ASPAS'));
    igual('a=b', naval_config('IGUAL'));
    igual('omissão', naval_config('VAZIO', 'omissão'));
    igual('omissão', naval_config('NAO_EXISTE', 'omissão'));
    verdade(!isset(naval_env()['# comentário']));
    igual('Africa/Luanda', date_default_timezone_get());
});

teste('pasta privada e subpastas criadas a pedido', function () {
    verdade(is_dir(naval_pasta('sap')));
    igual(naval_pasta_privada() . '/sap', naval_pasta('sap'));
});

teste('texto: cortar, comparar sem acentos, datas e emails', function () {
    igual('Avô Cumbe', naval_texto("  Avô \n  Cumbe "));
    igual('abc', naval_texto('abcdef', 3));
    igual('AVO CUMBE', naval_chave_texto('Avô-cumbe!'));
    verdade(naval_data_valida('2026-02-28'));
    verdade(!naval_data_valida('2026-02-30'));
    verdade(!naval_data_valida('26-10-2026'));
    igual('26-10-2026', naval_data_pt('2026-10-26'));
    verdade(naval_email_valido(' ana@naval.co.ao '));
    verdade(!naval_email_valido('ana@'));
    verdade(!naval_email_valido(null));
});

teste('JSON: escrita atómica, leitura tolerante', function () {
    $f = naval_pasta('ensaio') . '/x.json';
    naval_json_gravar($f, ['a' => 'ç', 'b' => [1, 2]]);
    igual(['a' => 'ç', 'b' => [1, 2]], naval_json_ler($f));
    igual([], glob(dirname($f) . '/*.tmp') ?: []);
    file_put_contents($f, '{danificado');
    igual(['omissao' => true], naval_json_ler($f, ['omissao' => true]));
    igual([], naval_json_ler($f . '.nao-existe'));
});

teste('cliente HTTP sem cURL: endereço sem resposta devolve erro de ligação', function () {
    $r = naval_http_pedido('GET', 'http://127.0.0.1:9/', ['sem_curl' => true, 'ligacao' => 1]);
    verdade($r['errno'] !== 0);
    igual(false, $r['corpo']);
    verdade(strpos($r['erro'], '127.0.0.1:9') !== false, $r['erro']);
});

teste('NavalErro leva o código HTTP e os campos extra', function () {
    $e = lanca(409, function () { throw new NavalErro('Conflito', 409, ['conflito' => true]); });
    igual(['conflito' => true], $e->extra());
    igual('Conflito', $e->getMessage());
});
