<?php
// Folha de caixa: mudar só o saldo inicial do primeiro dia (incluindo para 0) grava; gravar outra vez igual não.
naval_servico('caixa');

teste('caixa: mudar só o saldo inicial (para 0) grava e assina uma vez; repetir igual não volta a gravar', function () {
    $d = '2020-03-12';   // dia sem folhas anteriores: saldo inicial escrito à mão
    @unlink(naval_caixa_ficheiro($d));
    $e = [['rota' => '1005', 'empresa' => 'NAVAL', 'notas' => ['500' => 124, '1000' => 220, '2000' => 352, '5000' => 50]]];
    try {
        $c = naval_caixa_gravar($d, ['saldo_inicial' => 2826000, 'entregas' => $e, 'tpa' => [], 'saidas' => []], 0, 'josefa');
        igual(['manual', 2826000.0, 1], [$c['saldo_inicial_origem'], $c['saldo_inicial'], $c['versao']]);
        $c2 = naval_caixa_gravar($d, ['saldo_inicial' => 0, 'entregas' => $e, 'tpa' => [], 'saidas' => []], $c['versao'], 'josefa');
        igual([0.0, 2, 2], [$c2['saldo_inicial'], $c2['versao'], count($c2['historico'])], 'o saldo 0 fica gravado e assinado');
        $c3 = naval_caixa_gravar($d, ['saldo_inicial' => 0, 'entregas' => $e, 'tpa' => [], 'saidas' => []], $c2['versao'], 'josefa');
        igual([2, 2], [$c3['versao'], count($c3['historico'])], 'igual: não grava nem assina outra vez');
    } finally {
        @unlink(naval_caixa_ficheiro($d));
    }
});
