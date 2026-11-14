<?php
// Espelho da apresentação (nucleo/servicos/espelho.php): só o estado do ecrã, validado; só com o modo apresentação
// activo; cada gravação avança a sequência; começar ou acabar a apresentação recomeça o espelho vazio.
naval_servico('espelho');

teste('espelho: só com a apresentação activa; o estado do ecrã é validado e a sequência avança', function () {
    $login = 'teste-espelho';
    @unlink(naval_espelho_ficheiro($login));
    lanca(409, function () use ($login) { naval_espelho_gravar($login, 'abcdefgh12', ['sep' => 'hoje']); });
    naval_espelho_activar($login, time() + 600);
    $s1 = naval_espelho_gravar($login, 'abcdefgh12', ['sep' => 'rotas', 'periodo' => 'mes', 'folha' => '1004', 'rolar' => 3, 'idioma' => 'en',
        'conversa' => [['papel' => 'pessoa', 'texto' => 'Olá'], ['papel' => '<x>', 'texto' => 'r']], 'extra' => 'nunca']);
    $d = naval_espelho_ler($login);
    igual($s1, (int) $d['seq']);
    igual('abcdefgh12', $d['origem']);
    igual(['rotas', 'mes', '1004', 1.0, 'en'], [$d['estado']['sep'], $d['estado']['periodo'], $d['estado']['folha'], (float) $d['estado']['rolar'], $d['estado']['idioma']]);
    igual('pessoa', $d['estado']['conversa'][1]['papel']);
    verdade(!isset($d['estado']['extra']), 'chaves desconhecidas não passam');
    // identificadores inválidos e idioma desconhecido não passam
    $e = naval_espelho_estado(['sep' => '../x', 'folha' => '<script>', 'idioma' => 'fr', 'dia' => '14/11/2026']);
    igual([null, null, 'pt', null], [$e['sep'], $e['folha'], $e['idioma'], $e['dia']]);
    // documento e cliente abertos, período e dia
    $e = naval_espelho_estado(['sep' => 'documentos', 'folha' => 'd:FR A/123|2026-11-14', 'pv' => 'ano', 'dia' => '2026-11-14']);
    igual(['d:FR A/123|2026-11-14', 'ano', '2026-11-14'], [$e['folha'], $e['pv'], $e['dia']]);
    lanca(400, function () use ($login) { naval_espelho_gravar($login, 'X!', ['sep' => 'hoje']); });
    lanca(400, function () { naval_espelho_estado('texto'); });
    $s2 = naval_espelho_gravar($login, 'outrolado01', ['sep' => 'hoje']);
    verdade($s2 > $s1, 'sequência avança');
    // fim da apresentação: o espelho recomeça vazio e deixa de aceitar ecrãs
    naval_espelho_activar($login, 0);
    $d = naval_espelho_ler($login);
    igual([0, null], [(int) $d['ate'], $d['estado']]);
    lanca(409, function () use ($login) { naval_espelho_gravar($login, 'abcdefgh12', ['sep' => 'hoje']); });
    @unlink(naval_espelho_ficheiro($login));
});

teste('espelho: o computador sabe quando o telemóvel já está a seguir (leitura há menos de 6 segundos)', function () {
    $login = 'teste-espelho-tel';
    @unlink(naval_espelho_ficheiro($login) . '.tel');
    verdade(!naval_espelho_telemovel_a_seguir($login), 'sem leituras');
    naval_espelho_marcar_telemovel($login);
    verdade(naval_espelho_telemovel_a_seguir($login), 'acabou de ler');
    @touch(naval_espelho_ficheiro($login) . '.tel', time() - 30);
    clearstatcache();
    verdade(!naval_espelho_telemovel_a_seguir($login), 'deixou de ler');
    @unlink(naval_espelho_ficheiro($login) . '.tel');
});
