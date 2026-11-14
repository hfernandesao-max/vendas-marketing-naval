<?php
// Explorador de relatórios (nucleo/servicos/relatorios.php): só a definição se guarda (validada), visibilidade
// pessoal/partilhada/certificada, só o dono altera ou apaga, certificar por outra pessoa (quatro olhos), e o fecho de
// caixa por período lido dos registos.
naval_servico('relatorios');

function rel_limpar(): void
{
    foreach ((array) glob(naval_rel_pasta() . '/REL-*.json') as $f) @unlink((string) $f);
}

teste('relatórios: a definição é validada e normalizada (só identificadores, nunca SQL nem código)', function () {
    $d = naval_rel_definicao(['dominio' => 'vendas', 'linhas' => ['promotor', 'data:mes', 'x; DROP TABLE', 'a', 'b'], 'coluna' => 'tipo',
        'medidas' => ['vendas_sem_iva', '<script>'], 'filtros' => ['rota' => ['1001', ['x']], 'bad key' => ['y']], 'ordem' => 'qualquer', 'limite' => 9999, 'grafico' => 'pizza']);
    igual(['promotor', 'data:mes', 'a'], $d['linhas']);
    igual(['vendas_sem_iva'], $d['medidas']);
    igual(['rota' => ['1001']], (array) $d['filtros']);
    igual(['valor_desc', 500, 'barras'], [$d['ordem'], $d['limite'], $d['grafico']]);
    lanca(400, function () { naval_rel_definicao(['dominio' => 'vendas', 'medidas' => []]); });
    lanca(400, function () { naval_rel_definicao(['dominio' => '../x', 'medidas' => ['a']]); });
    lanca(400, function () { naval_rel_definicao('SELECT 1'); });
});

teste('relatórios: guardar, ver (pessoal, partilhado, certificado), só o dono altera; certificar com quatro olhos', function () {
    rel_limpar();
    $hf = ['login' => 'hf', 'nome' => 'Henrique'];
    $dir = ['login' => 'dir', 'nome' => 'Direcção'];
    $def = ['dominio' => 'vendas', 'linhas' => ['promotor'], 'medidas' => ['vendas_sem_iva']];
    try {
        lanca(400, function () use ($hf, $def) { naval_rel_guardar(['nome' => 'ab', 'definicao' => $def], $hf, true); });
        lanca(403, function () use ($hf, $def) { naval_rel_guardar(['nome' => 'Partilhado', 'visibilidade' => 'partilhado', 'definicao' => $def], $hf, false); });
        $p = naval_rel_guardar(['nome' => 'Pessoal', 'definicao' => $def], $hf, true);
        $s = naval_rel_guardar(['nome' => 'Partilhado', 'visibilidade' => 'partilhado', 'definicao' => $def], $hf, true);
        verdade((bool) preg_match('/^REL-[a-f0-9]{10}$/', $p['id']));
        igual(['Partilhado', 'Pessoal'], array_column(naval_rel_lista('hf'), 'nome'));
        igual(['Partilhado'], array_column(naval_rel_lista('dir'), 'nome'));   // o pessoal de outro não aparece
        // só o dono altera; a versão protege alterações simultâneas
        lanca(403, function () use ($dir, $s, $def) { naval_rel_guardar(['id' => $s['id'], 'nome' => 'Meu', 'definicao' => $def], $dir, true); });
        lanca(409, function () use ($hf, $s, $def) { naval_rel_guardar(['id' => $s['id'], 'versao' => 99, 'nome' => 'Outro', 'definicao' => $def], $hf, true); });
        // quatro olhos: o autor não certifica o próprio; outra pessoa sim; o super administrador também
        lanca(403, function () use ($hf, $s) { naval_rel_certificar($s['id'], true, $hf, false); });
        $c = naval_rel_certificar($s['id'], true, $dir, false);
        igual([true, 'dir'], [$c['certificado'], $c['certificado_por']]);
        igual('Partilhado', naval_rel_lista('hf')[0]['nome']);   // certificados primeiro
        // alterar a definição de um certificado retira a certificação (tem de ser revisto)
        $a = naval_rel_guardar(['id' => $s['id'], 'versao' => $c['versao'], 'nome' => 'Partilhado', 'visibilidade' => 'partilhado',
            'definicao' => ['dominio' => 'vendas', 'linhas' => ['rota'], 'medidas' => ['vendas_sem_iva']]], $hf, true);
        igual(false, $a['certificado']);
        // o pessoal certificado por outra pessoa fica visível a todos
        naval_rel_certificar($p['id'], true, $dir, false);
        verdade(in_array('Pessoal', array_column(naval_rel_lista('outro'), 'nome'), true));
        // apagar: só o dono (ou o super administrador)
        lanca(403, function () use ($dir, $p) { naval_rel_apagar($p['id'], $dir, false); });
        naval_rel_apagar($p['id'], $hf, false);
        igual(null, naval_rel_ler($p['id']));
    } finally {
        rel_limpar();
    }
});

teste('relatórios: fecho de caixa por período lido dos registos (resumo e movimentos)', function () {
    naval_servico('caixa');
    $f = naval_caixa_ficheiro('2026-10-20');
    $antes = is_file($f) ? file_get_contents($f) : null;
    try {
        naval_json_gravar($f, ['estado' => 'aprovada', 'saldo_inicial' => 1000, 'entregas' => [['rota' => '1001', 'empresa' => 'NAVAL', 'valor' => 500]],
            'tpa' => [['rota' => '1001', 'banco' => 'BPC', 'valor' => 300]], 'saidas' => [['banco' => 'BPC', 'lacre' => 'L1', 'valor' => 200, 'aprovado_por' => 'sara']],
            'contagem_fisica' => 1300, 'diferenca' => 0, 'fechada_por' => 'josefa', 'aprovada_por' => 'dir']);
        $r = naval_rel_caixa('2026-10-19', '2026-10-21');
        igual(1, count($r['dias']));
        igual([1000.0, 500.0, 300.0, 200.0, 1300.0], [$r['dias'][0]['saldo_inicial'], $r['dias'][0]['entregue'], $r['dias'][0]['multicaixa'], $r['dias'][0]['depositos'], $r['dias'][0]['saldo_teorico']]);
        igual(['entrega', 'multicaixa', 'deposito'], array_column($r['movimentos'], 'tipo'));
        lanca(400, function () { naval_rel_caixa('2026-10-21', '2026-10-19'); });
        lanca(400, function () { naval_rel_caixa('2024-01-01', '2026-10-19'); });   // mais de 400 dias
    } finally {
        if ($antes === null) @unlink($f);
        else file_put_contents($f, $antes);
    }
});
