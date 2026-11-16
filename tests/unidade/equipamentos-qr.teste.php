<?php
// Página pública do código QR (nucleo/servicos/equipamentos-qr.php): ID, tipo deduzido do ID, contacto de devolução
// (gravado no portal, com o .env por omissão) e leitura sem sessão, que nunca falha.
naval_servico('equipamentos-qr');

teste('QR: ID em maiúsculas e validado; tipo deduzido do ID, sem base de dados', function () {
    igual('NAV-SMT-0001', naval_atv_qr_id(' nav-smt-0001 '));
    igual('', naval_atv_qr_id("NAV-SMT-0001' OR 1=1"));
    igual('', naval_atv_qr_id('NAV-SMT-01'));
    igual(['Smartphone', 'Smartphone'], naval_atv_qr_tipo('NAV-SMT-0001'));
    igual(['Impressora móvel', 'Mobile printer'], naval_atv_qr_tipo('NAV-IMP-0002'));
    igual(['Equipamento', 'Device'], naval_atv_qr_tipo('NAV-TAB-0003'));
});

teste('QR: contacto de devolução validado, gravado no portal e com o .env por omissão', function () {
    @unlink(naval_atv_qr_ficheiro());
    verdade(in_array(naval_atv_contacto()['origem'], ['env', 'vazio'], true), 'sem gravação: .env');
    lanca(400, function () { naval_atv_gravar_contacto(['tel' => '', 'email' => ''], 'fin'); });
    lanca(400, function () { naval_atv_gravar_contacto(['tel' => '923-abc'], 'fin'); });
    lanca(400, function () { naval_atv_gravar_contacto(['email' => 'nao-e-email'], 'fin'); });
    $c = naval_atv_gravar_contacto(['tel' => ' +244  923 111 222 ', 'email' => 'Equipamentos@Naval.co.ao'], 'fin');
    igual(['+244 923 111 222', 'equipamentos@naval.co.ao', 'portal', 'fin'], [$c['tel'], $c['email'], $c['origem'], $c['alterado_por']]);
    $c = naval_atv_gravar_contacto(['email' => 'equipamentos@naval.co.ao'], 'fin');
    igual(['', 'portal'], [$c['tel'], $c['origem']]);
    @unlink(naval_atv_qr_ficheiro());
});

teste('QR: leitura sem sessão fica registada e nunca lança excepções (sem base de dados do módulo)', function () {
    $r = naval_atv_qr_leitura_publica('NAV-SMT-0001');
    verdade($r['registada'], 'registada');
    igual(false, $r['perdido']);
    naval_servico('notificacoes');
    igual(['equipamentos-administrar', 'G', 'critico'], array_slice(NAVAL_NOTIF_TIPOS['equipamento_lido'], 2));
    igual('Device NAV-SMT-0001 scanned', naval_notif_txt('perdido_lido', ['id' => 'NAV-SMT-0001', 'h' => '10:00', 'l' => 'Luanda'])['en'][0]);
});
