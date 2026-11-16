<?php
// Suporte (nucleo/servicos/suporte.php e suporte-encaminhar.php): prioridade pela matriz impacto × urgência (ITIL 4),
// prazos de resposta e resolução, assunto e bloco «E-mail Command» para o ServiceDesk Plus, dados pessoais fora do
// correio e reenvio quando o correio não sai.
naval_servico('suporte-encaminhar');

teste('suporte: prioridade pela matriz impacto × urgência; segurança pelo menos P2; melhoria P4', function () {
    igual('P1', naval_sup_prioridade('incidente', 'alto', 'alta'));
    igual('P3', naval_sup_prioridade('incidente', 'baixo', 'alta'));
    igual('P4', naval_sup_prioridade('pedido', 'baixo', 'baixa'));
    igual('P2', naval_sup_prioridade('seguranca', 'baixo', 'baixa'));
    igual('P1', naval_sup_prioridade('seguranca', 'alto', 'alta'));
    igual('P4', naval_sup_prioridade('melhoria', 'alto', 'alta'));
    igual('P2', naval_sup_prioridade('incidente', '', '', 'P2'));   // pedidos antigos: a prioridade indicada
    igual('2026-11-16 10:15:00', naval_sup_prazo_resposta('2026-11-16 10:00:00', 'P1'));
    igual('2026-11-16 18:00:00', naval_sup_prazo('2026-11-16 10:00:00', 'P2'));
});

teste('suporte: assunto normalizado e «E-mail Command» com os nomes da ferramenta, sem o delimitador nos valores', function () {
    $p = ['id' => 'SUP-000123', 'tipo' => 'incidente', 'servico' => 'smartphone', 'impacto' => 'medio', 'urgencia' => 'alta', 'prioridade' => 'P2',
        'titulo' => "Não\r\nsincroniza \$x\$", 'criado_email' => 'ana@naval.co.ao'];
    $cfg = naval_sup_enc_omissao();
    igual('[Portal RTM] [SUP-000123] P2 · Incidente · Smartphone RTM · Não sincroniza $x$', naval_sup_enc_assunto($p, $cfg));
    igual('', naval_sup_enc_comando($p, $cfg));   // desligado por omissão
    $cfg['comando']['activo'] = true;
    $cfg['comando']['grupo'] = 'Suporte RTM';
    igual("\$Operation=AddRequest\$\n\$Requester=ana@naval.co.ao\$\n\$RequestType=Incident\$\n\$Category=Smartphone RTM\$\n\$Priority=High\$\n\$Impact=Medium\$\n\$Urgency=High\$\n\$Group=Suporte RTM\$",
        naval_sup_enc_comando($p, $cfg));
});

teste('suporte: com dados pessoais o correio não leva a descrição; o texto vai sempre escapado', function () {
    $p = ['id' => 'SUP-000124', 'tipo' => 'incidente', 'servico' => 'portal', 'impacto' => 'baixo', 'urgencia' => 'media', 'prioridade' => 'P4',
        'titulo' => '<b>x</b>', 'descricao' => 'Cliente João, NIF 500000000', 'dados_pessoais' => true, 'criado_nome' => 'Ana', 'criado_por' => 'ana',
        'criado_em' => '2026-11-16 10:00:00', 'prazo' => '2026-11-19 10:00:00', 'prazo_resposta' => '2026-11-16 18:00:00', 'contexto' => ['pagina' => 'vendas-dia']];
    $h = naval_sup_enc_html($p, naval_sup_enc_omissao());
    verdade(strpos($h, 'NIF 500000000') === false, 'sem a descrição');
    verdade(strpos($h, 'Lei n.º 22/11') !== false, 'aviso dos dados pessoais');
    verdade(strpos($h, '<b>x</b>') === false && strpos($h, '&lt;b&gt;x&lt;/b&gt;') !== false, 'escapado');
    verdade(strpos($h, 'vendas-dia') !== false && strpos($h, '#suporte?id=SUP-000124') !== false, 'contexto e ligação');
    $p['dados_pessoais'] = false;
    verdade(strpos(naval_sup_enc_html($p, naval_sup_enc_omissao()), 'NIF 500000000') !== false, 'sem dados pessoais: com a descrição');
});

teste('suporte: configuração validada; criar um pedido classifica, guarda o contexto e, sem correio, fica por enviar', function () {
    @unlink(naval_sup_enc_ficheiro());
    lanca(400, function () { naval_sup_enc_gravar(['destinatario' => 'x', 'identificador' => '[P]'], 'ti'); });
    lanca(400, function () { naval_sup_enc_gravar(['destinatario' => 'it@grupo-naval.com', 'identificador' => '[P]', 'comando' => ['delimitador' => 'ab']], 'ti'); });
    $c = naval_sup_enc_gravar(['activo' => true, 'destinatario' => 'it@grupo-naval.com', 'identificador' => '[Portal RTM]',
        'comando' => ['activo' => true, 'delimitador' => '$', 'prioridades' => ['P1' => 'Urgent$', 'P9' => 'x']]], 'ti');
    igual(['it@grupo-naval.com', 'Urgent', true], [$c['destinatario'], $c['comando']['prioridades']['P1'], $c['comando']['activo']]);
    verdade(!isset($c['comando']['prioridades']['P9']), 'só chaves conhecidas');
    $antes = naval_sup_todos();
    $eu = ['login' => 'ana', 'nome' => 'Ana', 'email' => 'ana@naval.co.ao', 'perfil' => 'COM'];
    lanca(400, function () use ($eu) { naval_sup_criar($eu, ['tipo' => 'incidente', 'servico' => 'portal', 'impacto' => 'alto', 'titulo' => 'Portal parado']); });
    lanca(400, function () use ($eu) { naval_sup_criar($eu, ['tipo' => 'incidente', 'servico' => 'xpto', 'impacto' => 'alto', 'urgencia' => 'alta', 'titulo' => 'Portal parado']); });
    lanca(400, function () use ($eu) { naval_sup_criar($eu, ['tipo' => 'incidente', 'servico' => 'portal', 'titulo' => 'Portal parado', 'anexo' => ['dados' => base64_encode('<?php echo 1;')]]); });
    $p = naval_sup_criar($eu, ['tipo' => 'incidente', 'servico' => 'portal', 'impacto' => 'alto', 'urgencia' => 'alta', 'titulo' => 'Portal parado',
        'contexto' => ['pagina' => 'vendas-dia', 'ecra' => '1920x1080', 'idioma' => 'pt'], 'anexo' => ['nome' => 'a.png', 'dados' => base64_encode("\x89PNG\r\n\x1a\n" . str_repeat('x', 20))]]);
    igual(['P1', 'portal', 'vendas-dia', 'ana@naval.co.ao', 1], [$p['prioridade'], $p['servico'], $p['contexto']['pagina'], $p['criado_email'], count($p['anexos'])]);
    verdade(is_file(naval_sup_pasta_anexos() . '/' . $p['anexos'][0]['ficheiro']), 'captura guardada');
    if (!naval_email_configurado()) {
        igual(['por_enviar', 1], [$p['encaminhamento']['estado'], $p['encaminhamento']['tentativas']]);
        igual(0, naval_sup_encaminhar_pendentes(time()));                // ainda dentro do intervalo
        igual(1, naval_sup_encaminhar_pendentes(time() + 301));          // 5 min depois volta a tentar
        igual(2, naval_sup_todos()[$p['id']]['encaminhamento']['tentativas']);
    }
    @unlink(naval_sup_pasta_anexos() . '/' . $p['anexos'][0]['ficheiro']);
    naval_json_gravar(naval_sup_ficheiro(), $antes);
    @unlink(naval_sup_enc_ficheiro());
});
