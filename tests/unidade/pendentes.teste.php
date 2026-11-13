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
        igual([['michael', 'Depósito no banco por aprovar'], ['michael', 'Folha de caixa de 12-11-2026 para aprovação final']], $r);
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
