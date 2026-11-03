<?php
// Serviços de negócio: vendas do Pinguim BO, plano de cargas e ficheiro SAP.
naval_servico('vendas');
naval_servico('cargas');
naval_servico('sap');

function xml_vendas(): SimpleXMLElement
{
    return simplexml_load_string(<<<'XML'
<export>
  <entities><entity><code>C1</code><description>Loja Avô</description><tax_number>5000000001</tax_number></entity></entities>
  <payment_methods><payment_method><code>MC</code><description>Multicaixa Express</description></payment_method></payment_methods>
  <transactions>
    <transaction><document_type>FR</document_type><document_nr>FR 1/1</document_nr><store_code>1001</store_code><entity_code>C1</entity_code>
      <total>1000</total><transaction_date>2026-10-24</transaction_date><transaction_time>09:15:30</transaction_time><tax_number></tax_number><deleted>0</deleted>
      <footer><line><payment_code>NU</payment_code><amount>1200</amount></line><line><payment_code>MC</payment_code><amount>0</amount></line></footer>
      <detail><line><product_code>A1</product_code><product_description>Fraldas</product_description><uom>CAR</uom><quantity>2</quantity>
        <base_amount>400</base_amount><total>800</total><discount_amount>0</discount_amount><tax_amount>200</tax_amount></line></detail>
    </transaction>
    <transaction><document_type>FT</document_type><document_nr>FT 1/1</document_nr><store_code>1002</store_code><entity_code>X</entity_code>
      <total>500</total><transaction_date>2026-10-24</transaction_date><transaction_time>10:00</transaction_time><tax_number>999999999</tax_number><deleted>0</deleted>
      <footer><line><payment_code>MC</payment_code><amount>200</amount></line></footer>
    </transaction>
    <transaction><document_type>FR</document_type><document_nr>FR 1/2</document_nr><store_code>1001</store_code><total>50</total><deleted>1</deleted></transaction>
    <transaction><document_type>NC</document_type><document_nr>NC 1/1</document_nr><store_code>1001</store_code><total>-50</total><deleted>0</deleted></transaction>
  </transactions>
</export>
XML
    );
}

teste('vendas: FR/FT e notas de crédito não anuladas, cliente, NIF da entidade, troco e nomes dos meios', function () {
    $d = naval_vendas_do_xml(xml_vendas(), ['1001' => 'Ana'], true);
    igual(['FR 1/1', 'FT 1/1', 'NC 1/1'], array_column($d['vendas'], 'documento'));   // FR 1/2 anulada fica de fora
    [$fr, $ft, $nc] = $d['vendas'];
    igual('Ana', $fr['promotor']);
    igual('1002', $ft['promotor']);               // sem promotor conhecido fica a rota
    igual('Loja Avô', $fr['cliente']);
    igual('5000000001', $fr['nif']);
    igual('09:15', $fr['hora']);
    igual([['codigo' => 'NU', 'meio' => 'Numerário', 'valor' => 1000.0]], $fr['pagamentos']);   // troco de 200 retirado
    verdade($fr['liquidado']);
    igual('Consumidor Final', $ft['cliente']);
    igual('Multicaixa Express', $ft['meio_pagamento']);
    verdade(!$ft['liquidado']);
    igual(200.0, $ft['valor_pago']);
    igual(1, count($d['artigos']));
    igual(1000.0, $d['artigos'][0]['total']);
    igual([], naval_vendas_do_xml(xml_vendas(), [], false)['artigos']);
    // Nota de crédito: desconta às vendas, sem pagamentos nem valor por receber
    verdade($nc['nc'] && !$fr['nc'] && !$ft['nc']);
    igual('Nota de crédito', $nc['tipo']);
    igual(-50.0, $nc['total']);
    igual([], $nc['pagamentos']);
    igual(0.0, $nc['valor_pago']);
    verdade($nc['liquidado']);
    igual(1450.0, array_sum(array_column($d['vendas'], 'total')));   // 1000 + 500 − 50
});

teste('vendas: nota de crédito desconta sempre, venha a exportação com sinal positivo ou negativo', function () {
    foreach (['50', '-50'] as $v) {
        $x = simplexml_load_string('<export><transactions><transaction><document_type>NC</document_type><document_nr>NC 9/1</document_nr>'
            . "<store_code>1001</store_code><total>$v</total><deleted>0</deleted><transaction_date>2026-10-24</transaction_date>"
            . '<detail><line><product_code>A1</product_code><quantity>1</quantity><total>40</total><discount_amount>0</discount_amount><tax_amount>10</tax_amount></line></detail>'
            . '</transaction></transactions></export>');
        $d = naval_vendas_do_xml($x, [], true);
        igual(-50.0, $d['vendas'][0]['total']);
        igual(-1.0, $d['artigos'][0]['quantidade']);
        igual(-50.0, $d['artigos'][0]['total']);
        verdade($d['artigos'][0]['nc']);
    }
});

teste('vendas: âmbito por rota, NIF e perfil sem vendas', function () {
    $d = naval_vendas_do_xml(xml_vendas(), [], true);
    $sup = naval_vendas_ambito($d, ['1001'], false, true);
    igual(['1001'], array_unique(array_column($sup['vendas'], 'rota')));
    igual([''], array_unique(array_column($sup['vendas'], 'nif')));
    $log = naval_vendas_ambito($d, null, true, false);
    igual([], $log['vendas']);
    igual(null, $log['artigos'][0]['total']);
    igual('', $log['artigos'][0]['documento']);
    igual(2.0, $log['artigos'][0]['quantidade']);
});

teste('plano de cargas: limpeza de uma rota e erros de publicação', function () {
    $art = NAVAL_ARTIGOS_PADRAO;
    $r = naval_plano_rota_limpa(['rota' => ' 1001 ', 'sai' => 1, 'zonas' => ['Avô Cumbe', 'AVO-CUMBE', ' '], 'quantidades' => ['fraldas' => '12.6', 'sabao' => -3, 'outro' => 9],
        'matricula' => 'ld-58', 'telefone' => '+244 924-516 abc', 'viagens' => 99], $art);
    igual('1001', $r['rota']);
    igual(['Avô Cumbe'], $r['zonas']);
    igual(['fraldas' => 13, 'sabao' => 0, 'sabonete' => 0], $r['quantidades']);
    igual('LD-58', $r['matricula']);
    igual('+244 924516 ', $r['telefone']);
    igual(20, $r['viagens']);
    $e = naval_plano_erros([$r], $art);
    igual(['Rota 1001: falta o motorista.'], $e);
    igual(['Nenhuma rota sai neste dia.'], naval_plano_erros([['sai' => false] + $r], $art));
});

teste('plano de cargas: listas sem repetidos e palavras de ligação em minúsculas', function () {
    igual(['Samba', 'Avô'], naval_lista_unica(['Samba', 'SAMBA ', 'Avô', 'avo', '']));
    igual('Estalagem e Moagem do Sul', naval_ligacoes_minusculas('Estalagem E Moagem Do Sul'));
    $l = ['zonas' => [], 'armazens' => [], 'viaturas' => [], 'transportadoras' => [], 'motoristas' => [['nome' => 'Adão Paulo', 'telefone' => '', 'matricula' => 'X', 'viatura' => '', 'transportadora' => '']]];
    naval_plano_aprender($l, [['motorista' => 'ADAO PAULO', 'telefone' => '924', 'matricula' => '', 'zonas' => ['Bairro E Zona']]]);
    igual(1, count($l['motoristas']));
    igual('924', $l['motoristas'][0]['telefone']);
    igual('X', $l['motoristas'][0]['matricula']);
    igual(['Bairro e Zona'], $l['zonas']);
});

teste('plano de cargas: conflito de versão devolve 409', function () {
    $eu = ['login' => 'unidade'];
    $p = naval_plano_actualizar('2026-10-01', 0, [['rota' => '1001']], $eu, NAVAL_ARTIGOS_PADRAO);
    igual(1, $p['rev']);
    naval_plano_gravar($p);
    $e = lanca(409, function () use ($eu) { naval_plano_actualizar('2026-10-01', 0, [], $eu, NAVAL_ARTIGOS_PADRAO); });
    igual(['conflito' => true], $e->extra());
    lanca(400, function () use ($eu) { naval_plano_actualizar('01-10-2026', 1, [], $eu, NAVAL_ARTIGOS_PADRAO); });
});

teste('SAP: configuração limpa; lote único por documento, anulação com quatro olhos, carregamento', function () {
    $c = naval_sap_gravar_config(['moeda' => 'aoa', 'rotas' => ['1001' => ['local' => 'rtm1', 'loja' => ''], '' => ['local' => 'X'], '1002' => ['local' => '']]]);
    igual(['1001' => ['local' => 'RTM1']], $c['rotas']);
    igual('AOA', $c['moeda']);
    igual($c, naval_sap_config());
    lanca(400, function () { naval_sap_novo_lote(['documentos' => []], 'PK..', ['login' => 'u']); });
    lanca(400, function () { naval_sap_novo_lote(['documentos' => ['FR 1/1']], 'não é zip', ['login' => 'u']); });
    $l = naval_sap_novo_lote(['documentos' => ['FR 1/1', 'FR 1/1'], 'de' => '2026-10-24', 'ate' => '2026-10-24'], 'PK..', ['login' => 'u']);
    igual(1, $l['numero']);
    igual(1, $l['n_documentos']);
    igual('RTM_Sales_SAP_Upload_2026-10-24_lote1.xlsx', $l['ficheiro']);
    igual(['lote' => 1], array_intersect_key(naval_sap_resumo()['exportados']['FR 1/1'], ['lote' => 0]));
    igual(hash('sha256', 'PK..'), $l['sha256']);
    $e = lanca(409, function () { naval_sap_novo_lote(['documentos' => ['FR 1/2', 'FR 1/1']], 'PK..', ['login' => 'u']); });
    igual(['FR 1/1'], $e->extra()['duplicados']);
    lanca(403, function () { naval_sap_anular(1, 'motivo suficiente', ['login' => 'u', 'perfil' => 'FIN']); });
    lanca(400, function () { naval_sap_anular(1, 'curto', ['login' => 'v', 'perfil' => 'FIN']); });
    igual('anulado', naval_sap_anular(1, 'motivo suficiente', ['login' => 'v', 'perfil' => 'FIN'])['estado']);
    igual([], naval_sap_exportados(naval_sap_lotes()));
    igual(2, naval_sap_novo_lote(['documentos' => ['FR 1/1']], 'PK..', ['login' => 'u'])['numero']);
    igual('carregado', naval_sap_confirmar(2, 'SAP-1', ['login' => 'v'])['estado']);
    lanca(409, function () { naval_sap_anular(2, 'motivo suficiente', ['login' => 'v', 'perfil' => 'FIN']); });
    lanca(403, function () { naval_sap_segunda_via(2); });   // sem sessão de auditoria
    lanca(404, function () { naval_sap_confirmar(99, '', ['login' => 'v']); });
});

teste('meios de cobrança do Pinguim BO (SQL): troco, sem rodapé nunca presumido, notas de crédito a negativo', function () {
    naval_servico('vendas');
    $nu = ['codigo' => 'NU', 'meio' => 'Numerário'];
    $mc = ['codigo' => 'MC', 'meio' => 'Multicaixa'];
    // pagou 10 000 em numerário por uma factura de 9 500: o troco sai do numerário
    igual([['codigo' => 'NU', 'meio' => 'Numerário', 'valor' => 9500.0]], naval_vendas_pagamentos_doc([$nu + ['valor' => 10000]], false, 9500, true, 9500));
    // misto: 5 000 Multicaixa + 6 000 numerário para 10 000: o troco (1 000) sai do numerário
    igual([['codigo' => 'NU', 'meio' => 'Numerário', 'valor' => 5000.0], ['codigo' => 'MC', 'meio' => 'Multicaixa', 'valor' => 5000.0]],
        naval_vendas_pagamentos_doc([$nu + ['valor' => 6000], $mc + ['valor' => 5000]], false, 10000, true, 10000));
    // liquidada sem rodapé: nunca Multicaixa presumido
    igual([['codigo' => '?', 'meio' => 'Sem meio indicado', 'valor' => 8000.0]], naval_vendas_pagamentos_doc([], false, 8000, true, 8000));
    igual([], naval_vendas_pagamentos_doc([], false, 0, false, 8000), 'por pagar, sem rodapé');
    // nota de crédito devolvida em Multicaixa: debita a Multicaixa
    igual([['codigo' => 'MC', 'meio' => 'Multicaixa', 'valor' => -780000.0]], naval_vendas_pagamentos_doc([$mc + ['valor' => 780000]], true, 0, true, -780000));
    igual([], naval_vendas_pagamentos_doc([], true, 0, true, -780000), 'nota de crédito sem meio de devolução');
});
