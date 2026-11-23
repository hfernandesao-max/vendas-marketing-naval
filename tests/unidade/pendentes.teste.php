<?php
// «À sua espera» (nucleo/servicos/pendentes.php) e os avisos da caixa no telemóvel: só o que a pessoa pode fazer, ao
// nível que falta e com a separação de funções (quem lança, conta ou requisita não aprova).
naval_servico('pendentes');
naval_servico('caixa');
naval_servico('notificacoes');

function pd_folhas(): array
{
    return [
        '2026-11-12' => ['dia' => '2026-11-12', 'estado' => 'fechada', 'fechada_por' => 'josefa', 'fechada_em' => '2026-11-12 16:00:00', 'alterado_por' => 'josefa',
            'saidas' => [], 'diferenca' => 0],
        '2026-11-13' => ['dia' => '2026-11-13', 'estado' => 'aberta', 'saidas' => [['tipo' => 'deposito_banco', 'id' => 'DP-1', 'banco' => 'BAI', 'lacre' => 'L77',
            'valor' => 150000, 'requisitante' => 'josefa', 'requisitado_em' => '2026-11-13 15:40:00', 'aprovado_por' => null]]],
    ];
}

teste('pendentes: folha de caixa e depósitos ao nível que falta, sem quem lançou ou requisitou', function () {
    $agora = strtotime('2026-11-13 16:10');
    $fs = [];
    foreach (pd_folhas() as $d => $c) {
        $fs[] = naval_caixa_ficheiro($d);
        naval_json_gravar(naval_caixa_ficheiro($d), $c);
    }
    try {
        $pode = ['caixa2' => true, 'dois' => false];
        $m = naval_pendentes('michael', null, $pode, $agora, []);
        igual(2, $m['total']);
        // a de ontem primeiro: está atrasada
        igual(['caixa', 2, true, 'josefa'], [$m['itens'][0]['tipo'], $m['itens'][0]['nivel'], $m['itens'][0]['atraso'], $m['itens'][0]['por']]);
        igual(['deposito', 'L77', 150000.0, false], [$m['itens'][1]['tipo'], $m['itens'][1]['lacre'], $m['itens'][1]['valor'], $m['itens'][1]['atraso']]);
        // quem lançou e requisitou não aprova, mesmo com o nível
        igual(0, naval_pendentes('josefa', null, $pode, $agora, [])['total']);
        // com 1.º nível: a folha validada vai primeiro ao 1.º nível; o responsável só vê o depósito
        igual(['deposito'], array_column(naval_pendentes('michael', null, ['caixa2' => true, 'dois' => true], $agora, [])['itens'], 'tipo'));
        $s = naval_pendentes('sara', null, ['caixa1' => true, 'dois' => true], $agora, []);
        igual([['caixa', 1], ['deposito', null]], array_map(function ($x) { return [$x['tipo'], $x['nivel'] ?? null]; }, $s['itens']));
        igual(0, naval_pendentes('ana', null, [], $agora, [])['total']);
    } finally {
        foreach ($fs as $f) @unlink($f);
    }
});

teste('pendentes: conciliações das carrinhas pelo passo que falta e pela separação de funções', function () {
    $car = [
        ['rota' => '1005', 'promotor' => 'Olindo', 'resumo' => ['estado' => 'por_contar', 'diferencas' => 0]],
        ['rota' => '1006', 'promotor' => 'Luís', 'resumo' => ['estado' => 'por_validar', 'diferencas' => 2], 'contado_por' => 'manuel'],
        ['rota' => '1007', 'promotor' => 'Rui', 'resumo' => ['estado' => 'por_aprovar', 'diferencas' => 1], 'contado_por' => 'manuel', 'validado_por' => 'manuel'],
        ['rota' => '1008', 'promotor' => 'Ana', 'resumo' => ['estado' => 'por_fechar', 'diferencas' => 1], 'contado_por' => 'manuel', 'validado_por' => 'manuel',
            'coordenado_por' => 'coord'],
        ['rota' => '1009', 'promotor' => 'Eva', 'resumo' => ['estado' => 'aprovada', 'diferencas' => 0]],
    ];
    $par = function ($r) { return array_map(function ($x) { return [$x['rota'], $x['accao'], $x['atraso']]; }, $r['itens']); };
    $depois = strtotime('2026-11-13 16:10');   // sexta-feira, depois do fecho (15:30–16:00): atrasadas
    igual([['1005', 'contar', true], ['1006', 'validar', true]], $par(naval_pendentes('manuel', null, ['contar' => true], $depois, $car)));
    // antes do fecho não se pede para contar; só quem contou valida
    igual([['1006', 'validar', false]], $par(naval_pendentes('manuel', null, ['contar' => true], strtotime('2026-11-13 14:00'), $car)));
    igual([], $par(naval_pendentes('outro', null, ['contar' => true], strtotime('2026-11-13 14:00'), $car)));
    igual([['1007', 'coordenar', true]], $par(naval_pendentes('coord', null, ['coordenar' => true], $depois, $car)));
    igual([], $par(naval_pendentes('manuel', null, ['coordenar' => true], $depois, $car)));
    igual([['1008', 'fechar', true]], $par(naval_pendentes('chefe', null, ['fechar' => true], $depois, $car)));
    igual([], $par(naval_pendentes('coord', null, ['fechar' => true], $depois, $car)));
    // domingo: folga, nada para contar
    igual([], $par(naval_pendentes('manuel', null, ['contar' => true], strtotime('2026-11-15 16:10'), [$car[0]])));
});

teste('notificações: folha de caixa e depósito por aprovar avisam quem aprova, não quem lançou', function () {
    foreach (['subscricoes.json', 'preferencias.json', 'enviadas.json'] as $f) @unlink(naval_notif_pasta() . '/' . $f);
    $antes = naval_utilizadores();
    naval_gravar_utilizadores([
        'josefa' => ['nome' => 'Josefa', 'email' => 'j@naval.co.ao', 'perfil' => 'DIR', 'rotas' => [], 'activo' => true],
        'michael' => ['nome' => 'Michael Samson', 'email' => 'm@naval.co.ao', 'perfil' => 'DIR', 'rotas' => [], 'activo' => true],
    ]);
    $GLOBALS['pd_ent'] = [];
    $GLOBALS['naval_notif_entregar'] = function ($sub, $msg) {
        $GLOBALS['pd_ent'][] = [$sub['login'], $msg['titulo']];
        return 201;
    };
    try {
        foreach (['josefa', 'michael'] as $l) {
            $k = naval_ec_nova();
            naval_notif_subscrever($l, ['endpoint' => 'https://fcm.googleapis.com/fcm/send/' . $l, 'keys' => ['p256dh' => naval_b64u(naval_ec_ponto($k)), 'auth' => naval_b64u(random_bytes(16))]], 'teste');
            naval_notif_gravar_preferencias($l, ['silencio' => false]);
        }
        if (!naval_notif_push_disponivel()) return;
        naval_notif_folhas_caixa('2026-11-13', strtotime('2026-11-13 16:10'), pd_folhas(), false);
        $r = $GLOBALS['pd_ent'];
        sort($r);
        igual([['michael', 'Depósito no banco por aprovar'], ['michael', 'Fecho de caixa de 12-11-2026 para aprovação final']], $r);
        // não se repete
        $GLOBALS['pd_ent'] = [];
        naval_notif_folhas_caixa('2026-11-13', strtotime('2026-11-13 16:25'), pd_folhas(), false);
        igual([], $GLOBALS['pd_ent']);
    } finally {
        unset($GLOBALS['naval_notif_entregar']);
        foreach (['subscricoes.json', 'preferencias.json', 'enviadas.json'] as $f) @unlink(naval_notif_pasta() . '/' . $f);
        foreach (glob(naval_notif_pasta('caixa') . '/*.json') ?: [] as $f) @unlink($f);
        naval_gravar_utilizadores($antes);
    }
});

teste('à sua espera: PO por aprovar para quem aprova (o coordenador), nunca para quem a criou ou emitiu', function () {
    naval_servico('requisicoes');
    $r = naval_req_criar('1009', date('Y-m-d'), [['codigo' => 'A1', 'artigo' => 'Fraldas M', 'quantidade' => 4]], 'pro9');
    $e = naval_req_emitir($r['numero'], $r['versao'], 'pro9');
    // primeiro o controlo de stock valida: até lá não aparece a quem aprova
    $antes = naval_pendentes('nathan', null, ['po' => true], time(), []);
    igual([], array_values(array_filter($antes['itens'], function ($x) use ($e) { return ($x['numero'] ?? '') === $e['numero']; })), 'por validar: ainda não vai ao coordenador');
    $teresa = naval_pendentes('teresa.gomes', null, ['validar_po' => true], time(), []);
    $v = array_values(array_filter($teresa['itens'], function ($x) use ($e) { return ($x['numero'] ?? '') === $e['numero']; }));
    igual('po_validar_stock', $v[0]['tipo']);
    naval_req_validar_quantidades($e['numero'], [], '', 'teresa.gomes');
    $nathan = naval_pendentes('nathan', null, ['po' => true], time(), []);
    $meu = array_values(array_filter($nathan['itens'], function ($x) use ($e) { return ($x['numero'] ?? '') === $e['numero']; }));
    igual(1, count($meu));
    igual(['po', '1009', 1, 4.0, 'pro9'], [$meu[0]['tipo'], $meu[0]['rota'], $meu[0]['artigos'], $meu[0]['quantidade'], $meu[0]['por']]);
    $proprio = naval_pendentes('pro9', null, ['po' => true], time(), []);
    igual([], array_values(array_filter($proprio['itens'], function ($x) use ($e) { return ($x['numero'] ?? '') === $e['numero']; })), 'quem emitiu não aprova');
    naval_req_aprovar($e['numero'], 'nathan');
    $depois = naval_pendentes('nathan', null, ['po' => true], time(), []);
    igual([], array_values(array_filter($depois['itens'], function ($x) use ($e) { return ($x['numero'] ?? '') === $e['numero']; })), 'aprovada sai da lista');
});

teste('vendas por sincronizar: rota carregada, sem vendas desde a carga e telemóvel parado há mais de 6 horas', function () {
    naval_servico('sincronizacao-alertas');
    $agora = strtotime('2026-11-20 16:30:00');
    $l = [
        ['ROTA' => '1011', 'ULTIMA_CARGA' => '2026-11-20 15:40:30', 'ULTIMA_VENDA' => '2026-11-09 13:34:00', 'ULTIMA_SINCRONIZACAO' => '2026-11-20 09:32:00'],
        ['ROTA' => '1008', 'ULTIMA_CARGA' => '2026-11-19 15:26:53', 'ULTIMA_VENDA' => '2026-11-20 15:02:00', 'ULTIMA_SINCRONIZACAO' => '2026-11-20 09:00:00'],   // vendeu depois da carga
        ['ROTA' => '1004', 'ULTIMA_CARGA' => '2026-11-20 15:42:21', 'ULTIMA_VENDA' => '2026-11-20 13:57:00', 'ULTIMA_SINCRONIZACAO' => '2026-11-20 16:00:00'],   // sincronizou há pouco
        ['ROTA' => '1002', 'ULTIMA_CARGA' => '2026-11-10 08:00:00', 'ULTIMA_VENDA' => '2026-11-09 10:00:00', 'ULTIMA_SINCRONIZACAO' => '2026-11-10 09:00:00'],   // carga antiga
    ];
    $x = naval_sync_vendas_por_sincronizar($l, $agora, 'normal');
    igual(['1011'], array_column($x, 'rota'));
    igual(6, $x[0]['horas']);
    igual([], naval_sync_vendas_por_sincronizar($l, strtotime('2026-11-20 09:00:00'), 'normal'), 'de manhã não avisa');
    igual([], naval_sync_vendas_por_sincronizar($l, $agora, 'folga'), 'em dia de folga não avisa');
});

teste('conciliação: aviso de telemóvel sem sincronizar e de rota sem facturas no Pinguim BO', function () {
    naval_servico('sincronizacao-alertas');
    $ref = '2026-11-21 16:00:00';
    $tipos = function ($d) use ($ref) { return array_column(naval_sync_avisos_rota($d, '2026-11-21', $ref), 'tipo'); };
    // 1007: telemóvel parado desde 19-11
    igual(['sincronizacao'], $tipos(['ULTIMA_CARGA' => '2026-11-21 08:00:00', 'ULTIMA_VENDA' => '2026-11-19 15:00:00', 'ULTIMA_SINCRONIZACAO' => '2026-11-19 15:10:00']));
    // 1011: sincroniza mas não tem facturas desde 09-11, com carga
    igual(['sem_faturas'], $tipos(['ULTIMA_CARGA' => '2026-11-21 08:00:00', 'ULTIMA_VENDA' => '2026-11-09 13:34:00', 'ULTIMA_SINCRONIZACAO' => '2026-11-21 15:40:00']));
    // tudo em dia (sincronizou na última hora antes da referência): sem avisos
    igual([], $tipos(['ULTIMA_CARGA' => '2026-11-21 08:00:00', 'ULTIMA_VENDA' => '2026-11-21 14:00:00', 'ULTIMA_SINCRONIZACAO' => '2026-11-21 15:05:00']));
    // sem carga não pede facturas; sem dados, nada
    igual(['sincronizacao'], $tipos(['ULTIMA_CARGA' => null, 'ULTIMA_VENDA' => null, 'ULTIMA_SINCRONIZACAO' => null]));
    igual([], naval_sync_avisos_rota(null, '2026-11-21', $ref));
    $a = naval_sync_avisos_rota(['ULTIMA_CARGA' => '2026-11-21 08:00:00', 'ULTIMA_VENDA' => '2026-11-09 13:34:00', 'ULTIMA_SINCRONIZACAO' => '2026-11-19 15:10:00'], '2026-11-21', $ref);
    igual(true, strpos($a[0]['texto'], '19-11-2026 15:10') !== false);
    igual(1, count($a), 'telemóvel parado: a falta de facturas é consequência, um só aviso');
    $b = naval_sync_avisos_rota(['ULTIMA_CARGA' => '2026-11-21 08:00:00', 'ULTIMA_VENDA' => '2026-11-09 13:34:00', 'ULTIMA_SINCRONIZACAO' => '2026-11-21 15:40:00'], '2026-11-21', $ref);
    igual(true, strpos($b[0]['texto'], '09-11-2026') !== false);
});

teste('à sua espera: PO aprovada por conferir no cais (Fiel de Armazém) e carga conferida por validar (Back-Office)', function () {
    naval_servico('requisicoes');
    $r = naval_req_criar('1010', date('Y-m-d'), [['codigo' => 'A1', 'artigo' => 'Fraldas M', 'quantidade' => 6]], 'pro10');
    $e = naval_req_emitir($r['numero'], $r['versao'], 'pro10');
    $de = function (string $login, array $pode, string $tipo) use ($e) {
        return array_values(array_filter(naval_pendentes($login, null, $pode, time(), [])['itens'], function ($x) use ($e, $tipo) { return ($x['numero'] ?? '') === $e['numero'] && $x['tipo'] === $tipo; }));
    };
    igual([], $de('fiel', ['conferir' => true], 'po_conferir'), 'por aprovar: ainda não vai ao cais');
    naval_req_validar_quantidades($e['numero'], [], '', 'teresa.gomes');
    naval_req_aprovar($e['numero'], 'nathan');
    $c = $de('fiel', ['conferir' => true], 'po_conferir');
    igual(1, count($c));
    igual(['1010', 'nathan', '../reports/#cargas-po'], [$c[0]['rota'], $c[0]['por'], $c[0]['url']]);
    igual([], $de('pro10', ['conferir' => true], 'po_conferir'), 'quem emitiu não confere');
    naval_req_confirmar($e['numero'], ['A1' => 6], 'fiel');
    igual([], $de('fiel', ['conferir' => true], 'po_conferir'), 'conferida sai do cais');
    $v = $de('bo', ['validar' => true], 'po_validar');
    igual(['1010', 'fiel', false], [$v[0]['rota'], $v[0]['por'], $v[0]['atraso']]);
    igual([], $de('fiel', ['validar' => true], 'po_validar'), 'quem conferiu não valida');
    // passadas as horas de validação (PENDENTES_VALIDAR_HORAS, 2), fica atrasada
    $tarde = array_values(array_filter(naval_pendentes('bo', null, ['validar' => true], time() + 3 * 3600, [])['itens'], function ($x) use ($e) { return ($x['numero'] ?? '') === $e['numero']; }));
    igual(true, $tarde[0]['atraso']);
    igual('PO ' . $e['numero'] . ' (rota 1010): validar a carga conferida (Back-Office)', naval_pendente_texto($tarde[0]));
});

teste('pendentes: resumo de cada pessoa às 07:30 e escalamento dos atrasados, uma só vez', function () {
    naval_servico('pendentes');
    @unlink(naval_pasta('pendentes') . '/ciclo.json');
    $env = [];
    $notificar = function ($tipo, $login, $tit, $corpo, $textos, $chave) use (&$env) { $env[] = [$tipo, $login, $tit]; };
    $correio = [];
    $email = function ($para, $assunto) use (&$correio) { $correio[] = [$para, $assunto]; };
    $contas = [
        'coord' => ['activo' => true, 'perfil' => 'LOG', 'nome' => 'Coordenador', 'email' => 'coord@exemplo.ao', 'compatibilidade' => true],
        'semnada' => ['activo' => true, 'perfil' => 'AUD', 'nome' => 'Sem nada', 'email' => 'x@exemplo.ao', 'compatibilidade' => true],
    ];
    $carr = [['rota' => '1001', 'promotor' => 'Ana', 'resumo' => ['estado' => 'por_aprovar', 'diferencas' => 2], 'contado_por' => 'tec', 'validado_por' => 'tec']];
    $dia = '2030-01-10';   // quinta-feira
    $o = ['contas' => $contas, 'carrinhas' => $carr, 'notificar' => $notificar, 'email' => $email, 'escalar_para' => [['email' => 'dir@exemplo.ao']]];
    igual([], naval_pendentes_ciclo(strtotime($dia . ' 07:00:00'), $o), 'antes das 07:30 não envia');
    $r = naval_pendentes_ciclo(strtotime($dia . ' 07:35:00'), $o);
    igual(1, $r['resumos'], 'só quem tem pendentes recebe');
    igual(['pendentes', 'coord'], [$env[0][0], $env[0][1]]);
    igual([['coord@exemplo.ao'], 'À sua espera hoje: 1'], $correio[0]);
    igual([], naval_pendentes_ciclo(strtotime($dia . ' 08:00:00'), $o), 'uma só vez por dia');
    // 16:30: a aprovação passou do prazo do fecho → atrasada → sobe à Direcção, com o nome de quem a tem
    $env = [];
    $correio = [];
    $r = naval_pendentes_ciclo(strtotime($dia . ' 16:35:00'), $o);
    igual(1, $r['escalados']);
    igual('pendentes_escalados', $env[0][0]);
    igual([['dir@exemplo.ao'], 'Pendentes atrasados: 1'], $correio[0]);
    igual([], naval_pendentes_ciclo(strtotime($dia . ' 16:50:00'), $o), 'o escalamento das 16:30 não se repete');
    igual([], naval_pendentes_ciclo(strtotime('2030-01-13 08:00:00'), $o), 'domingo (folga): nada');
});
