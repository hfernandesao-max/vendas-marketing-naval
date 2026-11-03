<?php
// Perfis dinâmicos: perfis do sistema fixos, perfis criados como colunas da matriz.

teste('perfis do sistema: 12, com âmbito por rota e dois passos', function () {
    $p = naval_perfis();
    igual(12, count($p));
    igual(['SUP', 'PRO'], naval_perfis_por_rota());
    igual(['SADM', 'TI', 'DIR', 'EXT'], naval_perfis_mfa());
    igual(NAVAL_COLUNAS, naval_colunas());
});

teste('EXT (técnicos externos): só ver, todas as rotas, sem exportar, gerir, NIF, registos, acessos nem IA', function () {
    $m = naval_matriz_base();
    $ve = [];
    foreach ($m as $rec => $col) {
        verdade(in_array($col['EXT'], ['', 'V'], true), $rec . ': EXT com ' . $col['EXT']);
        if ($col['EXT'] === 'V') $ve[] = $rec;
    }
    foreach (['nif', 'acessos', 'registos', 'estrutura-dados', 'excel-completo', 'exportar-dados', 'sap-exportar', 'assistente', 'actualizacoes', 'geo-rotas-descarregar'] as $r) {
        igual('', $m[$r]['EXT'], $r);
    }
    foreach (['inicio', 'vendas-promotor', 'documentos', 'pagamentos', 'conciliacao-carrinha', 'geo-prospeccao', 'alertas'] as $r) igual('V', $m[$r]['EXT'], $r);
    verdade(!in_array('EXT', naval_perfis_por_rota(), true), 'EXT vê todas as rotas');
    verdade(count($ve) > 30, 'páginas a validar: ' . count($ve));
});

teste('perfil criado: coluna nova da matriz com as permissões copiadas; desactivado sai da matriz', function () {
    naval_gravar_perfis(['VEND' => ['nome' => 'Vendedor de loja', 'base' => 'SUP', 'por_rota' => true, 'dois_passos' => true, 'activo' => true,
        'matriz' => ['vendas-dia' => 'Vr', 'ranking' => 'Vr', 'admin-config' => 'A', 'acessos' => 'G']]]);
    igual('Vendedor de loja', naval_perfis()['VEND']);
    verdade(in_array('VEND', naval_colunas(), true));
    verdade(in_array('VEND', naval_perfis_por_rota(), true));
    verdade(in_array('VEND', naval_perfis_mfa(), true));
    $m = naval_matriz();
    igual('Vr', $m['vendas-dia']['VEND']);
    igual('', $m['admin-config']['VEND']);   // nível não permitido: nunca administra
    igual('', $m['acessos']['VEND']);        // G não é permitido na página Acessos
    igual('', $m['sap-exportar']['VEND']);
    // alteração na matriz guardada como diferença; desactivar retira a coluna
    $m['sap-exportar']['VEND'] = 'V';
    naval_gravar_matriz($m);
    igual(['sap-exportar' => ['VEND' => 'V']], naval_matriz_alteracoes());
    naval_gravar_perfis(['VEND' => ['nome' => 'Vendedor de loja', 'activo' => false, 'matriz' => []]]);
    verdade(!isset(naval_perfis()['VEND']));
    verdade(!isset(naval_matriz()['vendas-dia']['VEND']));
    verdade(!naval_conta_valida(['perfil' => 'VEND', 'activo' => true, 'email' => 'a@naval.co.ao']));
    naval_gravar_perfis([]);
    naval_gravar_matriz(naval_matriz_base());
});

teste('perfis com códigos inválidos ou iguais aos do sistema são ignorados', function () {
    naval_json_gravar(naval_ficheiro_perfis(), ['TI' => ['nome' => 'Falso', 'activo' => true], 'x' => ['nome' => 'm', 'activo' => true], 'OK1' => ['nome' => 'Bom', 'activo' => true]]);
    igual(['OK1'], array_keys(naval_perfis_criados(true)));
    igual('Administrador da plataforma', naval_perfil_nome('TI'));
    naval_gravar_perfis([]);
});
