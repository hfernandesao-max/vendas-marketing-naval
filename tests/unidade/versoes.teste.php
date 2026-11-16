<?php
// Identificação e classificação das versões (nucleo/servicos/versoes.php): número sem falhas, tipo mais exigente,
// ficheiros sensíveis exigem «impacto», e o registo de alterações classificado desde 16-11-2026.
naval_servico('novidades');
naval_servico('versoes');

teste('versões: próximo número = maior existente + 1, sem falhas e independente do dia', function () {
    igual('v2026.11.16.108', naval_versao_proxima(['v2026.11.16.107', 'v2026.11.14.99', 'v2026.11.14.103', 'x', 'v2026.11.16.106'], '2026.11.16'));
    igual('v2026.11.17.108', naval_versao_proxima(['v2026.11.16.107'], '2026.11.17'));
    igual('v2026.11.17.1', naval_versao_proxima([], '2026.11.17'));
    $erro = null;
    try {
        naval_versao_proxima([], '16-11-2026');
    } catch (InvalidArgumentException $e) {
        $erro = $e;
    }
    verdade($erro !== null, 'dia em formato errado recusado');
});

teste('versões: tipo mais exigente e ficheiros sensíveis exigem «impacto»', function () {
    igual('funcionalidade', naval_versao_tipo_maior(['correcao', 'funcionalidade', 'melhoria', 'xpto']));
    igual('', naval_versao_tipo_maior([]));
    $imp = naval_versao_impacto(['nucleo/servicos/sap.php', './.htaccess', 'ferramentas/sql/migracoes/0007-x.sql', 'reports/js/app.js', 'nucleo/seguranca/contas.php']);
    igual(['nucleo/servicos/sap.php', '.htaccess', 'ferramentas/sql/migracoes/0007-x.sql', 'nucleo/seguranca/contas.php'], array_keys($imp));
    $s = function (string $tipo) { return ['titulo' => 'X', 'tipo' => $tipo]; };
    igual([], naval_versao_verificar([$s('melhoria')], ['reports/js/app.js']));
    igual(1, count(naval_versao_verificar([$s('')], ['reports/js/app.js'])));
    igual(1, count(naval_versao_verificar([$s('melhoria')], ['nucleo/servicos/sap.php'])));
    igual([], naval_versao_verificar([$s('melhoria'), $s('impacto')], ['nucleo/servicos/sap.php']));
    igual(1, count(naval_versao_verificar([], ['ferramentas/sql/migracoes/0007-x.sql'])));
    igual([], naval_versao_verificar([], ['package-lock.json']));
});

teste('versões: o registo de alterações está classificado desde 16-11-2026 (tipos válidos)', function () {
    $sem = [];
    foreach (naval_nov_seccoes((string) file_get_contents(naval_raiz() . '/CHANGELOG.md')) as $x) {
        if (naval_versao_data_seccao($x['data']) >= NAVAL_VERSAO_CLASSIFICACAO_DESDE && !isset(NAVAL_VERSAO_TIPOS[$x['tipo']])) $sem[] = $x['data'] . ' ' . $x['titulo'];
    }
    igual([], $sem);
    igual('2026-11-16', naval_versao_data_seccao('16-11-2026'));
});
