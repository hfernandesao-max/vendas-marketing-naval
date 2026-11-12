<?php
// Notificações no telemóvel (nucleo/servicos/notificacoes.php): cifra RFC 8291, VAPID RFC 8292, destinatários pela
// matriz e pelas rotas, preferências, silêncio, nunca repetir e os acontecimentos do dia.
naval_servico('notificacoes');

function nt_limpar(): void
{
    foreach (['subscricoes.json', 'preferencias.json', 'enviadas.json', 'ciclo.json'] as $f) @unlink(naval_notif_pasta() . '/' . $f);
    foreach (glob(naval_notif_pasta('caixa') . '/*.json') ?: [] as $f) @unlink($f);
    naval_gravar_utilizadores([
        'chefe' => ['nome' => 'Chefe', 'email' => 'chefe@naval.co.ao', 'perfil' => 'SADM', 'rotas' => [], 'activo' => true],
        'dir' => ['nome' => 'Direcção', 'email' => 'dir@naval.co.ao', 'perfil' => 'DIR', 'rotas' => [], 'activo' => true],
        'sup' => ['nome' => 'Supervisor', 'email' => 'sup@naval.co.ao', 'perfil' => 'SUP', 'rotas' => ['1011'], 'activo' => true],
        'log' => ['nome' => 'Logística', 'email' => 'log@naval.co.ao', 'perfil' => 'LOG', 'rotas' => [], 'activo' => true],
        'off' => ['nome' => 'Inactiva', 'email' => 'off@naval.co.ao', 'perfil' => 'SADM', 'rotas' => [], 'activo' => false],
    ]);
    $GLOBALS['nt_entregas'] = [];
    $GLOBALS['naval_notif_entregar'] = function ($sub, $msg) {
        $GLOBALS['nt_entregas'][] = [$sub['login'], $msg['titulo']];
        return $sub['login'] === 'dir' ? 410 : 201;   // o telefone da Direcção retirou a subscrição
    };
}

/** Subscrição de um «telefone» de teste: [subscrição, chave privada, segredo]. */
function nt_telefone(string $nome = 'a'): array
{
    $k = naval_ec_nova();
    $auth = random_bytes(16);
    return [['endpoint' => 'https://fcm.googleapis.com/fcm/send/' . $nome . bin2hex(random_bytes(4)), 'keys' => ['p256dh' => naval_b64u(naval_ec_ponto($k)), 'auth' => naval_b64u($auth)]], $k, $auth];
}

teste('notificações: mensagem cifrada (RFC 8291) que só o telefone decifra', function () {
    igual(bin2hex(hash_hmac('sha256', 'x', 'y', true)) !== '', true);
    // HKDF (RFC 5869, caso de teste 1, 32 primeiros bytes)
    igual('3cb25f25faacd57a90434f64d0362f2a2d2d0a90cf1a5a4c5db02d56ecc4c5bf', bin2hex(naval_hkdf(hex2bin(str_repeat('0b', 22)), 32, hex2bin('f0f1f2f3f4f5f6f7f8f9'), hex2bin('000102030405060708090a0b0c'))));
    if (!naval_notif_push_disponivel()) return;   // PHP 7.1/7.2 (verificação de compatibilidade): sem cifra
    [$sub, $k, $auth] = nt_telefone();
    $corpo = naval_webpush_cifrar('{"titulo":"Rota 1011 parada"}', $sub['keys']['p256dh'], $sub['keys']['auth']);
    // cabeçalho RFC 8188: sal (16), tamanho do registo (4), comprimento da chave (1), chave do servidor (65)
    $sal = substr($corpo, 0, 16);
    igual(4096, unpack('N', substr($corpo, 16, 4))[1]);
    igual(65, ord($corpo[20]));
    $as = substr($corpo, 21, 65);
    $ua = naval_ec_ponto($k);
    $ecdh = naval_ecdh(naval_ec_publica($as), $k);
    $ikm = naval_hkdf($ecdh, 32, "WebPush: info\0" . $ua . $as, $auth);
    $cek = naval_hkdf($ikm, 16, "Content-Encoding: aes128gcm\0", $sal);
    $nonce = naval_hkdf($ikm, 12, "Content-Encoding: nonce\0", $sal);
    $resto = substr($corpo, 86);
    $claro = openssl_decrypt(substr($resto, 0, -16), 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, substr($resto, -16));
    igual("{\"titulo\":\"Rota 1011 parada\"}\x02", $claro);
    // outro telefone não consegue decifrar
    [, $k2] = nt_telefone('b');
    $ecdh2 = naval_ecdh(naval_ec_publica($as), $k2);
    $ikm2 = naval_hkdf($ecdh2, 32, "WebPush: info\0" . naval_ec_ponto($k2) . $as, $auth);
    $cek2 = naval_hkdf($ikm2, 16, "Content-Encoding: aes128gcm\0", $sal);
    igual(false, openssl_decrypt(substr($resto, 0, -16), 'aes-128-gcm', $cek2, OPENSSL_RAW_DATA, $nonce, substr($resto, -16)));
});

teste('notificações: VAPID (RFC 8292) com assinatura ES256 válida para o serviço de push', function () {
    $c = naval_vapid_cabecalho('https://web.push.apple.com/QGuQ', 1794500000);
    verdade((bool) preg_match('/^vapid t=([^.]+)\.([^.]+)\.([^,]+), k=([A-Za-z0-9_-]+)$/', $c, $m), $c);
    igual('https://web.push.apple.com', json_decode(naval_b64u_dec($m[2]), true)['aud']);
    $raw = naval_b64u_dec($m[3]);
    igual(64, strlen($raw));
    // r‖s → DER para o OpenSSL verificar
    $int = function (string $x): string {
        $x = ltrim($x, "\0");
        if (ord($x[0]) & 0x80) $x = "\0" . $x;
        return "\x02" . chr(strlen($x)) . $x;
    };
    $seq = $int(substr($raw, 0, 32)) . $int(substr($raw, 32));
    $der = "\x30" . chr(strlen($seq)) . $seq;
    igual(1, openssl_verify($m[1] . '.' . $m[2], $der, naval_ec_publica(naval_b64u_dec($m[4])), OPENSSL_ALGO_SHA256));
    igual($m[4], naval_vapid()[1], 'a mesma chave pública que a app recebe');
});

teste('notificações: só serviços de push conhecidos, em HTTPS (nada de pedidos a outros servidores)', function () {
    foreach (['https://fcm.googleapis.com/fcm/send/x', 'https://web.push.apple.com/Q', 'https://updates.push.services.mozilla.com/wpush/v2/x', 'https://wns2-par02p.notify.windows.com/w/?token=x'] as $ok) verdade(naval_notif_endpoint_ok($ok), $ok);
    foreach (['http://fcm.googleapis.com/x', 'https://geo.naval.co.ao:2051/api', 'https://169.254.169.254/latest', 'https://fcm.googleapis.com.mau.com/x', 'https://user@fcm.googleapis.com/x', 'https://fcm.googleapis.com:8443/x'] as $mau) verdade(!naval_notif_endpoint_ok($mau), $mau);
});

teste('notificações: destinatários pela matriz e pelas rotas; inactivas de fora; 410 retira a subscrição', function () {
    nt_limpar();
    foreach (['chefe', 'dir', 'sup', 'log'] as $l) naval_notif_subscrever($l, nt_telefone($l)[0], 'teste');
    // rota parada (alertas Vr): SADM, DIR e o SUP da 1011; a LOG não vê alertas
    $r = naval_notificar('rota_parada', 'Rota 1011 parada', 'Sem vendas há 1 h 10 min.', ['rota' => '1011', 'chave' => 'p1'], strtotime('2026-11-12 11:00'));
    igual(['chefe', 'dir', 'sup'], array_column($GLOBALS['nt_entregas'], 0));
    igual(3, $r['destinatarios']);
    igual(2, $r['entregues'], 'a da Direcção respondeu 410');
    igual(3, count(naval_notif_subscricoes()), 'a subscrição da Direcção foi retirada');
    // outra rota: o SUP da 1011 não recebe
    $GLOBALS['nt_entregas'] = [];
    naval_notificar('rota_parada', 'Rota 1002 parada', '…', ['rota' => '1002', 'chave' => 'p2'], strtotime('2026-11-12 11:00'));
    igual(['chefe'], array_column($GLOBALS['nt_entregas'], 0));
    // carrinha por aprovar (conciliação G): SADM e LOG
    $GLOBALS['nt_entregas'] = [];
    naval_notificar('carrinha_aprovar', 'Carrinha 1011 por aprovar', '…', ['rota' => '1011', 'chave' => 'c1'], strtotime('2026-11-12 15:00'));
    igual(['chefe', 'log'], array_column($GLOBALS['nt_entregas'], 0));
    // a mesma chave nunca repete
    $GLOBALS['nt_entregas'] = [];
    igual(true, naval_notificar('carrinha_aprovar', 'Carrinha 1011 por aprovar', '…', ['rota' => '1011', 'chave' => 'c1'])['repetida']);
    igual([], $GLOBALS['nt_entregas']);
});

teste('notificações: preferências por tipo, horas de silêncio (as críticas passam) e caixa com por ler', function () {
    nt_limpar();
    naval_notif_subscrever('chefe', nt_telefone()[0], 'teste');
    naval_notif_gravar_preferencias('chefe', ['tipos' => ['resumo_dia' => false]]);
    naval_notificar('resumo_dia', 'Resumo', '…', ['chave' => 'r1'], strtotime('2026-11-12 18:30'));
    igual([], $GLOBALS['nt_entregas'], 'tipo desligado');
    // 22:00 (silêncio 20–07): o aviso fica só na caixa; o crítico chega
    naval_notificar('rota_parada', 'Rota parada', '…', ['chave' => 's1'], strtotime('2026-11-12 22:00'));
    igual([], $GLOBALS['nt_entregas']);
    naval_notificar('problema', 'Problema em produção', '…', ['chave' => 's2'], strtotime('2026-11-12 22:05'));
    igual([['chefe', 'Problema em produção']], $GLOBALS['nt_entregas']);
    $v = naval_notif_vista(naval_utilizadores()['chefe'] + ['login' => 'chefe']);
    igual(2, $v['nao_lidas']);
    igual('Problema em produção', $v['caixa'][0]['titulo']);
    verdade(in_array('resumo_dia', array_column($v['tipos'], 'id'), true));
    igual(false, array_column($v['tipos'], 'activo', 'id')['resumo_dia']);
    naval_notif_marcar_lidas('chefe');
    igual(0, naval_notif_vista(naval_utilizadores()['chefe'] + ['login' => 'chefe'])['nao_lidas']);
    // silêncio desligado: tudo chega a qualquer hora
    naval_notif_gravar_preferencias('chefe', ['silencio' => false]);
    $GLOBALS['nt_entregas'] = [];
    naval_notificar('rota_parada', 'Rota parada', '…', ['chave' => 's3'], strtotime('2026-11-12 23:00'));
    igual(1, count($GLOBALS['nt_entregas']));
    // a LOG não pode receber «rota parada»: não aparece nos tipos dela
    igual(false, in_array('rota_parada', array_column(naval_notif_vista(naval_utilizadores()['log'] + ['login' => 'log'])['tipos'], 'id'), true));
});

teste('notificações: subscrições validadas e cada pessoa só cancela as suas', function () {
    nt_limpar();
    $erro = '';
    try { naval_notif_subscrever('chefe', ['endpoint' => 'https://intranet.naval/x', 'keys' => nt_telefone()[0]['keys']], ''); } catch (NavalErro $e) { $erro = $e->getMessage(); }
    igual('Serviço de notificações não reconhecido.', $erro);
    $erro = '';
    try { naval_notif_subscrever('chefe', ['endpoint' => 'https://fcm.googleapis.com/x', 'keys' => ['p256dh' => 'abc', 'auth' => 'def']], ''); } catch (NavalErro $e) { $erro = $e->getMessage(); }
    igual('Chave de subscrição inválida.', $erro);
    [$s] = nt_telefone();
    naval_notif_subscrever('chefe', $s, 'iPhone');
    igual(false, naval_notif_cancelar('dir', $s['endpoint']));
    igual(true, naval_notif_cancelar('chefe', $s['endpoint']));
    igual([], naval_notif_subscricoes());
});

teste('notificações: ciclo do dia — rota parada, sem vendas às 10:30, venda anulada, resumo e carrinha por aprovar', function () {
    nt_limpar();
    naval_notif_subscrever('chefe', nt_telefone()[0], 'teste');
    naval_notif_gravar_preferencias('chefe', ['silencio' => false]);
    $dados = [
        'rotas' => ['1011' => ['docs' => 3, 'total' => 90000.0, 'ultima' => '09:40'], '1002' => ['docs' => 5, 'total' => 150000.0, 'ultima' => '10:50']],
        'anulados' => [['DOCUMENTO' => 'FR R1011/2026/88', 'ROTA' => '1011', 'TOTAL' => 12500.0]],
        'promotores' => ['1011' => 'Robel', '1002' => 'Cristina', '1005' => 'Olindo'],
    ];
    $agora = strtotime('2026-11-12 11:00');   // quinta-feira
    naval_notif_ciclo($agora, ['forcar' => true, 'dados' => $dados, 'carrinhas' => []]);
    $t = array_column($GLOBALS['nt_entregas'], 1);
    verdade(in_array('Rota 1011 · Robel parada', $t, true), json_encode($t, JSON_UNESCAPED_UNICODE));
    verdade(!in_array('Rota 1002 · Cristina parada', $t, true), 'a 1002 vendeu há 10 minutos');
    verdade(in_array('Rota 1005 · Olindo sem vendas', $t, true));
    verdade(in_array('Venda anulada · rota 1011 · Robel', $t, true));
    // a seguir, nada se repete; intervalo de 15 minutos respeitado
    $GLOBALS['nt_entregas'] = [];
    naval_notif_ciclo($agora + 60, ['forcar' => true, 'dados' => $dados, 'carrinhas' => []]);
    igual([], $GLOBALS['nt_entregas']);
    igual(null, naval_notif_ciclo($agora + 120, ['dados' => $dados, 'carrinhas' => []]));
    // 18:30: resumo do dia (uma vez) e carrinha com diferenças por aprovar
    $car = [['rota' => '1011', 'resumo' => ['estado' => 'por_aprovar', 'diferencas' => 2, 'falta_kz' => 3000.0]], ['rota' => '1002', 'resumo' => ['estado' => 'aprovada']]];
    naval_notif_ciclo(strtotime('2026-11-12 18:31'), ['forcar' => true, 'dados' => $dados, 'carrinhas' => $car]);
    $t = array_column($GLOBALS['nt_entregas'], 1);
    verdade(in_array('Resumo de hoje: 240 000 Kz', $t, true), json_encode($t, JSON_UNESCAPED_UNICODE));
    verdade(in_array('Carrinha 1011 por aprovar', $t, true));
    verdade(!in_array('Carrinha 1002 por aprovar', $t, true));
    // domingo: sem alertas de rotas
    $GLOBALS['nt_entregas'] = [];
    nt_limpar();
    naval_notif_subscrever('chefe', nt_telefone()[0], 'teste');
    naval_notif_ciclo(strtotime('2026-11-15 11:00'), ['forcar' => true, 'dados' => $dados, 'carrinhas' => []]);
    igual([], array_values(array_filter(array_column($GLOBALS['nt_entregas'], 1), function ($x) { return strpos($x, 'parada') !== false || strpos($x, 'sem vendas') !== false; })));
    unset($GLOBALS['naval_notif_entregar']);
    nt_limpar();
    unset($GLOBALS['naval_notif_entregar']);
});
