<?php
// Vigilância em produção: falhas agrupadas sem dados pessoais, verificações de negócio e reposição automática.
naval_servico('vigia');
naval_servico('actualizacao');

function vigia_limpar_tudo(): void
{
    foreach (['estado.json', 'problemas.json'] as $f) @unlink(naval_vigia_pasta() . '/' . $f);
    foreach (glob(naval_pasta_privada() . '/registos/falhas-*.jsonl') ?: [] as $f) @unlink($f);
    foreach (glob(naval_pasta_privada() . '/registos/desempenho-*.jsonl') ?: [] as $f) @unlink($f);
    @unlink(naval_act_pasta() . '/pedido-reversao.json');
    @unlink(naval_act_pasta() . '/estado.json');
}

teste('vigia: a mensagem perde e-mails, números longos e valores entre plicas', function () {
    $m = naval_vigia_limpar("Falhou para ana.silva@naval.co.ao com o NIF 5417012345 e o cliente 'Loja do Zé'");
    verdade(strpos($m, '@') === false, 'sem e-mail');
    verdade(strpos($m, '5417012345') === false, 'sem números longos');
    verdade(strpos($m, 'Loja') === false, 'sem valores');
});

teste('vigia: falhas iguais ficam num só problema; 5xx explicado por uma falha não conta duas vezes', function () {
    vigia_limpar_tudo();
    $_SERVER['REQUEST_URI'] = '/api/index.php/v1/vendas?de=1';
    naval_vigia_registar_falha('api', 'TypeError', 'Argumento 1 inválido na linha 12', __DIR__ . '/../../nucleo/servicos/vendas.php', 400);
    naval_vigia_registar_falha('api', 'TypeError', 'Argumento 1 inválido na linha 13', __DIR__ . '/../../nucleo/servicos/vendas.php', 400);
    naval_jsonl_acrescentar(naval_pasta_privada() . '/registos/desempenho-' . date('Y-m') . '.jsonl', ['data' => date('Y-m-d H:i:s'), 'rota' => 'v1/vendas', 'ms' => 30, 'estado' => 500]);
    naval_jsonl_acrescentar(naval_pasta_privada() . '/registos/desempenho-' . date('Y-m') . '.jsonl', ['data' => date('Y-m-d H:i:s'), 'rota' => 'v1/stock', 'ms' => 30, 'estado' => 502]);
    naval_jsonl_acrescentar(naval_pasta_privada() . '/registos/desempenho-' . date('Y-m') . '.jsonl', ['data' => date('Y-m-d H:i:s'), 'rota' => 'v1/cargas', 'ms' => 3000, 'estado' => 200]);
    $r = naval_vigia_ciclo(time(), ['forcar' => true, 'negocio' => [], 'sem_github' => true, 'sem_email' => true]);
    igual(3, $r['ocorrencias']);
    igual(2, count($r['novos']), 'a falha do código e o 502 do stock');
    $p = array_values(naval_vigia_problemas());
    $codigo = array_values(array_filter($p, function ($x) { return $x['tipo'] === 'TypeError'; }))[0];
    igual(2, $codigo['contagem']);
    igual('nucleo/servicos/vendas.php', $codigo['ficheiro']);
    // segunda volta: nada de novo, o cursor não volta a ler as mesmas linhas
    $r = naval_vigia_ciclo(time(), ['forcar' => true, 'negocio' => [], 'sem_github' => true, 'sem_email' => true]);
    igual(0, $r['ocorrencias']);
    igual(null, naval_vigia_ciclo(time(), ['sem_github' => true, 'sem_email' => true]), 'respeita o intervalo de 15 minutos');
});

teste('vigia: vendas a zero com documentos no Pinguim BO abrem problema; 7 dias sem voltar dão-no por resolvido', function () {
    vigia_limpar_tudo();
    $neg = ['pinguim' => ['ok' => true], 'vendas_2026-11-10' => ['ok' => false, 'mensagem' => 'O Pinguim BO tem 80 documentos de venda em 2026-11-10 e o portal não mostra nenhum.']];
    $r = naval_vigia_ciclo(time(), ['forcar' => true, 'negocio' => $neg, 'sem_github' => true, 'sem_email' => true]);
    igual(1, count($r['novos']));
    $p = array_values(naval_vigia_problemas())[0];
    igual('negocio_vendas', $p['tipo']);
    igual('aberto', $p['estado']);
    naval_vigia_ciclo(time() + 8 * 86400, ['forcar' => true, 'negocio' => ['pinguim' => ['ok' => true]], 'sem_github' => true, 'sem_email' => true]);
    igual('resolvido', array_values(naval_vigia_problemas())[0]['estado']);
    $v = naval_vigia_vista();
    igual(0, $v['abertos']);
});

teste('vigia: falhas a disparar na primeira hora depois de instalar pedem a versão anterior, uma só vez', function () {
    vigia_limpar_tudo();
    $agora = time();
    @mkdir(naval_act_pasta('copias') . '/20261111-0300-teste', 0770, true);
    naval_act_gravar_estado(['versao' => 'v2026.11.11.90', 'commit' => 'abc', 'aplicada_em' => date('Y-m-d H:i:s', $agora - 600), 'ficheiros' => []]);
    $f = naval_pasta_privada() . '/registos/falhas-' . date('Y-m', $agora) . '.jsonl';
    for ($i = 0; $i < 12; $i++) naval_jsonl_acrescentar($f, ['data' => date('Y-m-d H:i:s', $agora - 300), 'tipo' => 'Error', 'ficheiro' => 'x.php', 'linha' => $i, 'mensagem' => 'm']);
    $r = naval_vigia_ciclo($agora, ['forcar' => true, 'negocio' => [], 'sem_github' => true, 'sem_email' => true]);
    igual('v2026.11.11.90', $r['revertida']);
    verdade(is_file(naval_act_pasta() . '/pedido-reversao.json'), 'pedido para o ciclo de 5 minutos');
    igual('vigilancia', naval_json_ler(naval_act_pasta() . '/pedido-reversao.json')['por']);
    @unlink(naval_act_pasta() . '/pedido-reversao.json');
    for ($i = 0; $i < 12; $i++) naval_jsonl_acrescentar($f, ['data' => date('Y-m-d H:i:s', $agora - 60), 'tipo' => 'Error', 'ficheiro' => 'x.php', 'linha' => $i, 'mensagem' => 'm']);
    $r = naval_vigia_ciclo($agora, ['forcar' => true, 'negocio' => [], 'sem_github' => true, 'sem_email' => true]);
    igual(null, $r['revertida'], 'a mesma versão não é desfeita duas vezes');
    @rmdir(naval_act_pasta('copias') . '/20261111-0300-teste');
    vigia_limpar_tudo();
});

teste('vigia: poucas falhas, ou fora da primeira hora, não desfazem a versão', function () {
    vigia_limpar_tudo();
    $agora = time();
    @mkdir(naval_act_pasta('copias') . '/20261111-0300-teste', 0770, true);
    naval_act_gravar_estado(['versao' => 'v2026.11.11.91', 'commit' => 'abc', 'aplicada_em' => date('Y-m-d H:i:s', $agora - 600), 'ficheiros' => []]);
    $f = naval_pasta_privada() . '/registos/falhas-' . date('Y-m', $agora) . '.jsonl';
    for ($i = 0; $i < 3; $i++) naval_jsonl_acrescentar($f, ['data' => date('Y-m-d H:i:s', $agora - 300), 'tipo' => 'Error', 'ficheiro' => 'x.php', 'linha' => 1, 'mensagem' => 'm']);
    igual(null, naval_vigia_ciclo($agora, ['forcar' => true, 'negocio' => [], 'sem_github' => true, 'sem_email' => true])['revertida']);
    naval_act_gravar_estado(['versao' => 'v2026.11.11.91', 'commit' => 'abc', 'aplicada_em' => date('Y-m-d H:i:s', $agora - 7200), 'ficheiros' => []]);
    for ($i = 0; $i < 30; $i++) naval_jsonl_acrescentar($f, ['data' => date('Y-m-d H:i:s', $agora - 60), 'tipo' => 'Error', 'ficheiro' => 'x.php', 'linha' => 1, 'mensagem' => 'm']);
    igual(null, naval_vigia_ciclo($agora, ['forcar' => true, 'negocio' => [], 'sem_github' => true, 'sem_email' => true])['revertida']);
    @rmdir(naval_act_pasta('copias') . '/20261111-0300-teste');
    vigia_limpar_tudo();
});
