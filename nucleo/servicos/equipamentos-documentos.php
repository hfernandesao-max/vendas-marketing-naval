<?php
/**
 * Equipamentos — documentos e assinatura electrónica (fase 1).
 *
 * Fluxo: (1) emitir o PDF e bloqueá-lo (SHA-256 em HashOriginal); (2) assinar no ecrã, por ordem: 1 = colaborador
 * (presencial, no equipamento do responsável, que fica registado como testemunha; ou com a própria conta do portal),
 * 2 = responsável do Grupo Naval (com a sua conta); (3) com as duas assinaturas, acrescentar ao PDF original, sem lhe
 * alterar um byte, a página de assinaturas com as evidências, e guardar o SHA-256 do resultado em HashFinal; (4) aplicar o efeito do documento (Convenção de
 * Prova no colaborador; Auto de Substituição nos dois equipamentos). Os ficheiros ficam na pasta privada.
 * Fase 2: PDF/A-3 selado em PAdES-B-LT com selo temporal (RFC 3161) por um serviço de assinatura (EU DSS).
 * Os textos dos documentos são modelos provisórios, a validar pelo departamento jurídico.
 */
defined('NAVAL_NUCLEO') || exit;
require_once __DIR__ . '/equipamentos.php';
require_once __DIR__ . '/pdf-simples.php';

const NAVAL_ATV_DOCS = [
    'TERMO_ENTREGA' => ['TER', 'Termo de Entrega e Responsabilidade', 'ENTREGUE'],
    'AUTO_DEVOLUCAO' => ['DEV', 'Auto de Devolução', 'DEVOLVIDO'],
    'AUTO_SUBSTITUICAO' => ['SUB', 'Auto de Substituição', null],
    'PARTICIPACAO_PERDA' => ['PPR', 'Participação de Perda ou Roubo', 'ABRANGIDO'],
    'AUTO_ABATE' => ['ABT', 'Auto de Abate', 'ABRANGIDO'],
    'CONVENCAO_PROVA' => ['CPE', 'Convenção de Prova Electrónica', null],
];

const NAVAL_ATV_CLAUSULAS = [
    'TERMO_ENTREGA' => [
        'O Grupo Naval entrega ao colaborador identificado os equipamentos descritos, que são propriedade do Grupo Naval, para uso exclusivo nas funções comerciais da rota indicada.',
        'O colaborador declara recebê-los em bom estado de funcionamento, salvo o indicado no estado físico, e compromete-se a utilizá-los com zelo, a não os ceder a terceiros, a não remover as etiquetas de identificação e a mantê-los inscritos na gestão de equipamentos do Grupo Naval.',
        'O colaborador comunica de imediato ao responsável qualquer avaria, perda ou roubo e devolve os equipamentos quando lhe for pedido ou quando deixar as funções.',
    ],
    'AUTO_DEVOLUCAO' => [
        'O colaborador identificado devolve ao Grupo Naval os equipamentos descritos.',
        'O responsável do Grupo Naval confirma a recepção e o estado físico indicado. A responsabilidade do colaborador por estes equipamentos termina com a assinatura deste auto.',
    ],
    'AUTO_SUBSTITUICAO' => [
        'O equipamento devolvido é substituído pelo equipamento entregue, que passa a estar à guarda do colaborador identificado nas mesmas condições do Termo de Entrega e Responsabilidade.',
        'Depois das duas assinaturas, o portal regista a substituição: o equipamento devolvido segue para reparação e o equipamento entregue fica atribuído ao colaborador, na mesma rota e com a mesma associação.',
    ],
    'PARTICIPACAO_PERDA' => [
        'O colaborador identificado participa a perda ou o roubo dos equipamentos descritos, nas circunstâncias indicadas nas observações.',
        'O Grupo Naval pode activar o modo de perda e apagar os dados dos equipamentos à distância. O colaborador compromete-se a apresentar a participação às autoridades quando se trate de roubo.',
    ],
    'AUTO_ABATE' => [
        'Os equipamentos descritos são abatidos ao registo de activos do Grupo Naval pelo motivo indicado nas observações.',
        'O ID do activo não volta a ser utilizado. Os dados do equipamento são apagados antes do destino final.',
    ],
    'CONVENCAO_PROVA' => [
        'O colaborador identificado e o Grupo Naval acordam que os documentos relativos aos equipamentos que lhe forem atribuídos (termos de entrega, autos de devolução e de substituição e participações de perda ou roubo) podem ser assinados electronicamente no Portal RTM.',
        'A assinatura é feita presencialmente, desenhada no ecrã de um equipamento do Grupo Naval, na presença do responsável, que fica registado como testemunha; ou com a conta pessoal do colaborador no portal.',
        'O portal regista a data e a hora, o endereço IP, o navegador, a identificação do signatário e da testemunha e a impressão digital SHA-256 de cada documento, antes e depois das assinaturas. As partes aceitam estes registos como meio de prova, nos termos do Decreto Presidencial n.º 202/11.',
        'Esta convenção é assinada uma única vez, antes do primeiro documento, e vale até ser revogada por escrito.',
    ],
];

function naval_atv_doc_pasta(): string
{
    return naval_pasta('equipamentos/documentos');
}

function naval_atv_doc_formatar(array $d): array
{
    return [
        'id' => (int) $d['IdDocumento'], 'numero' => $d['NumDocumento'], 'tipo' => $d['TipoDocumento'],
        'titulo' => NAVAL_ATV_DOCS[$d['TipoDocumento']][1] ?? $d['TipoDocumento'], 'estado' => $d['Estado'],
        'emissao' => $d['DataEmissao'], 'conclusao' => $d['DataConclusao'] ?? null, 'colaborador' => $d['Colaborador'] ?? null,
        'papel' => $d['Papel'] ?? null, 'emitido_por' => $d['EmitidoPor'] ?? null, 'motivo_anulacao' => $d['MotivoAnulacao'] ?? null,
    ];
}

/** Documento pelo número (TER-2026-000001), com o colaborador, os equipamentos e as assinaturas. */
function naval_atv_documento(string $num): array
{
    $num = strtoupper(trim($num));
    if (!preg_match('/^[A-Z]{3}-\d{4}-\d{6}$/', $num)) throw new NavalErro('Número de documento inválido.', 400);
    $d = naval_atv_q('SELECT d.*, c.Nome AS Colaborador, c.Funcao AS ColabFuncao, c.RotaActual AS ColabRota, c.UtilizadorPortal AS ColabUtilizador
        FROM ATV_DOCUMENTO d LEFT JOIN ATV_COLABORADOR c ON c.IdColaborador = d.IdColaborador WHERE d.NumDocumento = :n', [':n' => $num]);
    if (!$d) throw new NavalErro('Documento não encontrado.', 404);
    $d = $d[0];
    $act = naval_atv_q('SELECT da.*, a.Tipo, a.Marca, a.Modelo, a.NumSerie, a.Imei1, a.MacBluetooth, a.RotaActual
        FROM ATV_DOCUMENTO_ACTIVO da JOIN ATV_ACTIVO a ON a.IdActivo = da.IdActivo WHERE da.IdDocumento = :d ORDER BY da.IdActivo', [':d' => $d['IdDocumento']]);
    $rotas = naval_rotas();
    $u = naval_utilizador();
    $proprio = $u && trim((string) $d['ColabUtilizador']) !== '' && strcasecmp(trim((string) $d['ColabUtilizador']), (string) $u['login']) === 0;
    if ($rotas !== null && !$proprio) {
        $ok = in_array((string) $d['ColabRota'], $rotas, true);
        foreach ($act as $a) $ok = $ok || in_array((string) $a['RotaActual'], $rotas, true);
        if (!$ok) throw new NavalErro('Este documento não pertence às suas rotas.', 403);
    }
    $ass = naval_atv_q('SELECT * FROM ATV_ASSINATURA WHERE IdDocumento = :d ORDER BY Ordem', [':d' => $d['IdDocumento']]);
    return [
        'linha' => $d,
        'documento' => naval_atv_doc_formatar($d) + ['id_colaborador' => $d['IdColaborador'] !== null ? (int) $d['IdColaborador'] : null,
            'colaborador_utilizador' => $d['ColabUtilizador'], 'hash_original' => $d['HashOriginal'], 'hash_final' => $d['HashFinal']],
        'equipamentos' => array_map(function ($a) {
            return ['id' => $a['IdActivo'], 'tipo' => $a['Tipo'], 'marca' => $a['Marca'], 'modelo' => $a['Modelo'], 'num_serie' => $a['NumSerie'],
                'imei' => $a['Imei1'], 'mac' => $a['MacBluetooth'], 'papel' => $a['Papel'], 'acessorios' => $a['Acessorios'], 'estado_fisico' => $a['EstadoFisico']];
        }, $act),
        'assinaturas' => array_map(function ($s) {
            return ['ordem' => (int) $s['Ordem'], 'nome' => $s['NomeSignatario'], 'qualidade' => $s['Qualidade'], 'metodo' => $s['MetodoAutenticacao'],
                'testemunha' => $s['UtilizadorTestemunha'], 'data' => $s['DataHora'], 'ip' => $s['EnderecoIp']];
        }, $ass),
    ];
}

/** O documento está em nome do utilizador actual (colaborador com conta no portal)? */
function naval_atv_e_do_colaborador(string $num): bool
{
    $u = naval_utilizador();
    $num = strtoupper(trim($num));
    if (!$u || !preg_match('/^[A-Z]{3}-\d{4}-\d{6}$/', $num)) return false;
    $l = naval_atv_q('SELECT c.UtilizadorPortal FROM ATV_DOCUMENTO d JOIN ATV_COLABORADOR c ON c.IdColaborador = d.IdColaborador WHERE d.NumDocumento = :n', [':n' => $num]);
    return $l && trim((string) $l[0]['UtilizadorPortal']) !== '' && strcasecmp(trim((string) $l[0]['UtilizadorPortal']), (string) $u['login']) === 0;
}

function naval_atv_documentos(?string $estado = null): array
{
    $l = naval_atv_q('SELECT d.*, c.Nome AS Colaborador, c.RotaActual AS ColabRota FROM ATV_DOCUMENTO d
        LEFT JOIN ATV_COLABORADOR c ON c.IdColaborador = d.IdColaborador ORDER BY d.DataEmissao DESC, d.IdDocumento DESC');
    $act = naval_atv_q('SELECT da.IdDocumento, da.IdActivo, a.RotaActual FROM ATV_DOCUMENTO_ACTIVO da JOIN ATV_ACTIVO a ON a.IdActivo = da.IdActivo');
    $porDoc = [];
    foreach ($act as $a) $porDoc[$a['IdDocumento']][] = $a;
    $rotas = naval_rotas();
    $out = [];
    foreach ($l as $d) {
        if ($estado !== null && $d['Estado'] !== $estado) continue;
        $eq = $porDoc[$d['IdDocumento']] ?? [];
        if ($rotas !== null) {
            $ok = in_array((string) $d['ColabRota'], $rotas, true);
            foreach ($eq as $a) $ok = $ok || in_array((string) $a['RotaActual'], $rotas, true);
            if (!$ok) continue;
        }
        $out[] = naval_atv_doc_formatar($d) + ['equipamentos' => array_column($eq, 'IdActivo'), 'rota' => $d['ColabRota']];
    }
    return $out;
}

/** Número seguinte do tipo e do ano (TER-2026-000001). */
function naval_atv_doc_numero(string $tipo): string
{
    $pref = NAVAL_ATV_DOCS[$tipo][0] . '-' . date('Y') . '-';
    $l = naval_atv_q('SELECT MAX(NumDocumento) AS M FROM ATV_DOCUMENTO WHERE NumDocumento LIKE :p', [':p' => $pref . '%']);
    $n = $l && $l[0]['M'] ? (int) substr($l[0]['M'], -6) + 1 : 1;
    return $pref . str_pad((string) $n, 6, '0', STR_PAD_LEFT);
}

/** Cabeçalho comum: logótipo, empresa, título, número e data. */
function naval_atv_pdf_cabecalho(array &$p, string $titulo, string $num, string $data): void
{
    $logo = __DIR__ . '/../recursos/naval-logo.jpg';
    if (is_file($logo)) naval_pdf_imagem($p, (string) file_get_contents($logo), 62);
    naval_pdf_espacar($p, 6);
    naval_pdf_texto($p, 'GRUPO NAVAL · Portal RTM · Equipamentos', 8.5, true, NAVAL_PDF_MARGEM, null, [0.0, 0.34, 0.72]);
    naval_pdf_texto($p, $titulo, 17, true);
    naval_pdf_texto($p, 'Documento n.º ' . $num . ' · emitido em ' . naval_data_pt(substr($data, 0, 10)) . ' às ' . substr($data, 11, 5), 9.5, false, NAVAL_PDF_MARGEM, null, [0.27, 0.33, 0.43]);
    naval_pdf_linha($p);
}

/** PDF do documento (conteúdo fixo desde a emissão). */
function naval_atv_pdf_documento(string $tipo, string $num, string $data, string $emissor, ?array $colab, array $equip, string $obs): string
{
    $titulo = NAVAL_ATV_DOCS[$tipo][1];
    $p = naval_pdf_novo(['titulo' => $titulo . ' ' . $num, 'rodape' => $num . ' · Modelo provisório, a validar pelo departamento jurídico · Grupo Naval']);
    naval_atv_pdf_cabecalho($p, $titulo, $num, $data);
    naval_pdf_texto($p, 'Partes', 11, true);
    naval_pdf_campo($p, 'Empresa', 'Grupo Naval Angola');
    naval_pdf_campo($p, 'Responsável', $emissor);
    if ($colab) {
        naval_pdf_campo($p, 'Colaborador', (string) $colab['Nome']);
        naval_pdf_campo($p, 'Função', (string) ($colab['Funcao'] ?? ''));
        naval_pdf_campo($p, 'Rota', (string) ($colab['RotaActual'] ?? ''));
    }
    if ($equip) {
        naval_pdf_espacar($p, 6);
        naval_pdf_texto($p, 'Equipamentos', 11, true);
        $papel = ['ENTREGUE' => 'Entregue', 'DEVOLVIDO' => 'Devolvido', 'ABRANGIDO' => 'Abrangido'];
        foreach ($equip as $e) {
            $id = [];
            if (!empty($e['NumSerie'])) $id[] = 'N.º de série ' . $e['NumSerie'];
            if (!empty($e['Imei1'])) $id[] = 'IMEI ' . $e['Imei1'];
            if (!empty($e['MacBluetooth'])) $id[] = 'MAC ' . $e['MacBluetooth'];
            naval_pdf_espacar($p, 3);
            naval_pdf_texto($p, $e['IdActivo'] . ' · ' . ($e['Tipo'] === 'SMT' ? 'Smartphone' : ($e['Tipo'] === 'IMP' ? 'Impressora móvel' : $e['Tipo']))
                . ' · ' . trim(($e['Marca'] ?? '') . ' ' . ($e['Modelo'] ?? '')) . ' · ' . ($papel[$e['Papel']] ?? $e['Papel']), 10, true);
            naval_pdf_texto($p, implode(' · ', $id) ?: '—', 9.5);
            if (!empty($e['Acessorios'])) naval_pdf_texto($p, 'Acessórios: ' . $e['Acessorios'], 9.5);
            if (!empty($e['EstadoFisico'])) naval_pdf_texto($p, 'Estado físico: ' . $e['EstadoFisico'], 9.5);
        }
    }
    naval_pdf_espacar($p, 6);
    naval_pdf_texto($p, 'Condições', 11, true);
    foreach (NAVAL_ATV_CLAUSULAS[$tipo] as $i => $c) {
        naval_pdf_espacar($p, 2);
        naval_pdf_texto($p, ($i + 1) . '. ' . $c, 10);
    }
    if ($obs !== '') {
        naval_pdf_espacar($p, 6);
        naval_pdf_texto($p, 'Observações', 11, true);
        naval_pdf_texto($p, $obs, 10);
    }
    naval_pdf_espacar($p, 10);
    naval_pdf_texto($p, 'Assinaturas: colaborador e responsável do Grupo Naval, no Portal RTM. A página de assinaturas, com as evidências de cada assinatura, junta-se a este documento depois de assinado.', 8.5, false, NAVAL_PDF_MARGEM, null, [0.35, 0.4, 0.5]);
    return naval_pdf_gerar($p);
}

/**
 * Emitir um documento (movimentar): tipo, equipamentos (com o papel de cada um), colaborador e observações.
 * Termo de Entrega e Auto de Substituição exigem a Convenção de Prova do colaborador já assinada.
 */
function naval_atv_emitir(array $d): array
{
    $tipo = strtoupper((string) ($d['tipo'] ?? ''));
    if (!isset(NAVAL_ATV_DOCS[$tipo])) throw new NavalErro('Escolha o tipo de documento.', 400);
    $colabId = (int) ($d['id_colaborador'] ?? 0);
    $colab = $colabId ? naval_atv_colaborador($colabId) : null;
    if (!$colab && $tipo !== 'AUTO_ABATE') throw new NavalErro('Escolha o colaborador.', 400);
    if ($colab && !naval_rota_permitida((string) $colab['RotaActual']) && naval_rotas() !== null) throw new NavalErro('Este colaborador não pertence às suas rotas.', 403);
    if (in_array($tipo, ['TERMO_ENTREGA', 'AUTO_SUBSTITUICAO'], true) && empty($colab['IdDocConvencao'])) {
        throw new NavalErro('Este colaborador ainda não assinou a Convenção de Prova Electrónica: emita-a e assine-a primeiro.', 409, ['codigo' => 'atv_sem_convencao']);
    }
    $equip = [];
    if ($tipo !== 'CONVENCAO_PROVA') {
        foreach ((array) ($d['equipamentos'] ?? []) as $e) {
            $id = naval_atv_id((string) ($e['id'] ?? ''));
            $a = naval_atv_obter($id);
            if (!$a) throw new NavalErro('Equipamento não registado: ' . $id . '.', 404);
            if (!naval_atv_no_ambito($a) && $a['rota'] !== null) throw new NavalErro('O equipamento ' . $id . ' não pertence às suas rotas.', 403);
            $papel = NAVAL_ATV_DOCS[$tipo][2] ?? strtoupper((string) ($e['papel'] ?? ''));
            if (!in_array($papel, ['ENTREGUE', 'DEVOLVIDO', 'ABRANGIDO'], true)) throw new NavalErro('Indique o papel de cada equipamento.', 400);
            $equip[$id] = ['id' => $id, 'papel' => $papel, 'acessorios' => naval_atv_txt($e['acessorios'] ?? '', 200), 'estado_fisico' => naval_atv_txt($e['estado_fisico'] ?? '', 200)];
        }
        if (!$equip) throw new NavalErro('Escolha os equipamentos do documento.', 400);
        if ($tipo === 'AUTO_SUBSTITUICAO') {
            $papeis = array_count_values(array_column($equip, 'papel'));
            if (($papeis['DEVOLVIDO'] ?? 0) !== 1 || ($papeis['ENTREGUE'] ?? 0) !== 1) throw new NavalErro('O Auto de Substituição tem um equipamento devolvido e um entregue.', 400);
            foreach ($equip as $e) {
                $a = naval_atv_obter($e['id']);
                if ($e['papel'] === 'ENTREGUE' && $a['estado'] !== 'EM_STOCK') throw new NavalErro('O equipamento entregue tem de estar em stock (reserva).', 409);
                if ($e['papel'] === 'DEVOLVIDO' && $a['estado'] !== 'ATRIBUIDO') throw new NavalErro('O equipamento devolvido tem de estar atribuído.', 409);
            }
        }
    }
    $obs = (string) naval_atv_txt($d['observacoes'] ?? '', 1000);
    // um pedido de cada vez: o número seguinte e o ficheiro nunca colidem com outra emissão em simultâneo
    return naval_exclusivo('atv-emissao', function () use ($tipo, $colab, $equip, $obs) {
        return naval_atv_emitir_bloqueado($tipo, $colab, $equip, $obs);
    });
}

function naval_atv_emitir_bloqueado(string $tipo, ?array $colab, array $equip, string $obs): array
{
    $ficheiroCriado = null;
    try {
        return naval_atv_transacao(function () use ($tipo, $colab, $equip, $obs, &$ficheiroCriado) {
        $num = naval_atv_doc_numero($tipo);
        $data = naval_atv_agora();
        $linhas = [];
        foreach ($equip as $e) {
            $a = naval_atv_q('SELECT * FROM ATV_ACTIVO WHERE IdActivo = :id', [':id' => $e['id']])[0];
            $linhas[] = $a + ['Papel' => $e['papel'], 'Acessorios' => $e['acessorios'], 'EstadoFisico' => $e['estado_fisico']];
        }
        $pdf = naval_atv_pdf_documento($tipo, $num, $data, naval_atv_quem(), $colab, $linhas, $obs);
        $ficheiro = $num . '.pdf';
        if (is_file(naval_atv_doc_pasta() . '/' . $ficheiro)) throw new NavalErro('Número de documento repetido. Tente de novo.', 409);
        if (file_put_contents(naval_atv_doc_pasta() . '/' . $ficheiro, $pdf, LOCK_EX) === false) throw new NavalErro('Não foi possível guardar o documento.', 500);
        $ficheiroCriado = naval_atv_doc_pasta() . '/' . $ficheiro;
        $idDoc = (int) naval_atv_inserir('ATV_DOCUMENTO', [
            'NumDocumento' => $num, 'TipoDocumento' => $tipo, 'Estado' => 'A_ASSINAR', 'IdColaborador' => $colab ? (int) $colab['IdColaborador'] : null,
            'DataEmissao' => $data, 'EmitidoPor' => naval_atv_quem(), 'HashOriginal' => hash('sha256', $pdf), 'FicheiroPdf' => $ficheiro,
        ], 'IdDocumento');
        foreach ($equip as $e) {
            naval_atv_inserir('ATV_DOCUMENTO_ACTIVO', ['IdDocumento' => $idDoc, 'IdActivo' => $e['id'], 'Papel' => $e['papel'],
                'Acessorios' => $e['acessorios'], 'EstadoFisico' => $e['estado_fisico']]);
        }
        naval_registar('equipamentos_documento_emitido', ['numero' => $num, 'tipo' => $tipo]);
        return naval_atv_documento($num)['documento'];
        });
    } catch (Throwable $e) {
        if ($ficheiroCriado !== null) @unlink($ficheiroCriado);   // a emissão não ficou gravada: o ficheiro também não
        throw $e;
    }
}

function naval_atv_anular(string $num, string $motivo): array
{
    $motivo = (string) naval_atv_txt($motivo, 400);
    if (strlen($motivo) < 5) throw new NavalErro('Indique o motivo da anulação.', 400);
    $doc = naval_atv_documento($num);
    if ($doc['documento']['estado'] !== 'A_ASSINAR') throw new NavalErro('Só um documento por assinar pode ser anulado.', 409);
    if ($doc['assinaturas']) throw new NavalErro('Este documento já tem assinaturas: não pode ser anulado.', 409);
    naval_atv_x("UPDATE ATV_DOCUMENTO SET Estado = 'ANULADO', MotivoAnulacao = :m WHERE IdDocumento = :id", [':m' => $motivo, ':id' => $doc['linha']['IdDocumento']]);
    naval_registar('equipamentos_documento_anulado', ['numero' => $doc['documento']['numero']]);
    return naval_atv_documento($num)['documento'];
}

/** Imagem desenhada no ecrã: «data:image/…;base64,…» → bytes, com o tipo e o tamanho verificados. */
function naval_atv_imagem(string $dataUrl, string $tipo, int $max = 600000): string
{
    if (!preg_match('#^data:image/' . $tipo . ';base64,([A-Za-z0-9+/=]+)$#', $dataUrl, $m)) throw new NavalErro('Assinatura inválida.', 400);
    $b = (string) base64_decode($m[1], true);
    if ($b === '' || strlen($b) > $max) throw new NavalErro('Assinatura inválida.', 400);
    $info = @getimagesizefromstring($b);
    $esperado = $tipo === 'png' ? IMAGETYPE_PNG : IMAGETYPE_JPEG;
    if (!$info || $info[2] !== $esperado || $info[0] < 100 || $info[1] < 40) throw new NavalErro('Assinatura inválida.', 400);
    return $b;
}

/**
 * Assinar (movimentar). Ordem 1: o colaborador, presencialmente (o utilizador actual fica como testemunha) ou com a
 * sua conta do portal; ordem 2: o responsável, com a sua conta. Exige «Li e aceito», a assinatura desenhada (PNG para
 * evidência e JPEG para o PDF) e o documento sem alterações desde a emissão. Com as duas, conclui o documento.
 */
function naval_atv_assinar(string $num, array $d, bool $podeMovimentar = true): array
{
    // uma assinatura de cada vez por documento: a ordem lê-se e grava-se sem outra assinatura pelo meio
    $chave = 'atv-ass-' . preg_replace('/[^A-Z0-9-]/', '', strtoupper($num));
    return naval_exclusivo($chave, function () use ($num, $d, $podeMovimentar) {
        return naval_atv_assinar_bloqueado($num, $d, $podeMovimentar);
    });
}

function naval_atv_assinar_bloqueado(string $num, array $d, bool $podeMovimentar): array
{
    $doc = naval_atv_documento($num);
    $linha = $doc['linha'];
    if ($linha['Estado'] !== 'A_ASSINAR') throw new NavalErro('Este documento já não está por assinar.', 409);
    if (empty($d['aceito'])) throw new NavalErro('Confirme «Li e aceito» antes de assinar.', 400);
    $png = naval_atv_imagem((string) ($d['imagem_png'] ?? ''), 'png');
    $jpg = naval_atv_imagem((string) ($d['imagem_jpeg'] ?? ''), 'jpeg');
    $tracos = isset($d['tracos']) && is_array($d['tracos']) ? $d['tracos'] : [];
    if (count($tracos) < 1) throw new NavalErro('Desenhe a assinatura na área indicada.', 400);
    $u = naval_utilizador();
    $feitas = array_column($doc['assinaturas'], 'ordem');
    $ordem = in_array(1, $feitas, true) ? 2 : 1;
    if ($ordem === 1 && $linha['IdColaborador'] === null) $ordem = in_array(2, $feitas, true) ? 3 : 2;   // auto de abate: só o responsável
    if ($ordem > 2) throw new NavalErro('O documento já tem todas as assinaturas.', 409);
    $eColaborador = $linha['ColabUtilizador'] !== null && $linha['ColabUtilizador'] !== '' && strcasecmp((string) $linha['ColabUtilizador'], (string) $u['login']) === 0;
    if (!$podeMovimentar && !($ordem === 1 && $eColaborador)) throw new NavalErro('Sem permissão para assinar este documento.', 403);
    $base = ['IdDocumento' => (int) $linha['IdDocumento'], 'Ordem' => $ordem, 'DataHora' => naval_atv_agora(),
        'EnderecoIp' => naval_ip_cliente(), 'Navegador' => naval_atv_txt($_SERVER['HTTP_USER_AGENT'] ?? '', 300)];
    if (isset($d['lat'], $d['lon']) && is_numeric($d['lat']) && is_numeric($d['lon']) && abs((float) $d['lat']) <= 90 && abs((float) $d['lon']) <= 180) {
        // texto com 6 casas e ponto decimal: o SQL Server converte-o para DECIMAL(9,6) sem notação científica
        $base['Latitude'] = sprintf('%.6F', (float) $d['lat']);
        $base['Longitude'] = sprintf('%.6F', (float) $d['lon']);
    }
    if ($ordem === 1) {
        $propria = $eColaborador;
        $base += ['NomeSignatario' => $linha['Colaborador'], 'Qualidade' => 'Colaborador', 'IdColaborador' => (int) $linha['IdColaborador']];
        $base += $propria
            ? ['MetodoAutenticacao' => 'CONTA_PORTAL', 'UtilizadorPortal' => $u['login']]
            : ['MetodoAutenticacao' => 'PRESENCIAL', 'UtilizadorTestemunha' => $u['login']];
    } else {
        if ($eColaborador) {
            throw new NavalErro('O responsável que assina tem de ser outra pessoa.', 409);
        }
        $base += ['NomeSignatario' => trim((string) ($u['nome'] ?? '')) ?: $u['login'], 'Qualidade' => 'Responsável do Grupo Naval',
            'MetodoAutenticacao' => 'CONTA_PORTAL', 'UtilizadorPortal' => $u['login']];
    }
    $pasta = naval_pasta('equipamentos/assinaturas');
    $nome = $linha['NumDocumento'] . '-' . $ordem;
    $original = (string) @file_get_contents(naval_atv_doc_pasta() . '/' . $linha['FicheiroPdf']);
    if ($original === '' || !hash_equals((string) $linha['HashOriginal'], hash('sha256', $original))) {
        throw new NavalErro('O ficheiro do documento foi alterado ou não existe: não é possível assinar.', 409, ['codigo' => 'atv_integridade']);
    }
    $tracosJson = json_encode($tracos);
    if ($tracosJson === false || strlen($tracosJson) > 2000000) throw new NavalErro('Assinatura inválida.', 400);
    $criados = [];
    try {
        $r = naval_atv_transacao(function () use ($base, $pasta, $nome, $png, $jpg, $tracosJson, $original, $doc, &$criados) {
            foreach (['.png' => $png, '.jpg' => $jpg, '.json' => $tracosJson] as $ext => $conteudo) {
                if (file_put_contents($pasta . '/' . $nome . $ext, $conteudo, LOCK_EX) === false) throw new NavalErro('Não foi possível guardar a assinatura.', 500);
                $criados[] = $pasta . '/' . $nome . $ext;
            }
            naval_atv_inserir('ATV_ASSINATURA', $base + ['HashDocumento' => hash('sha256', $original), 'ImagemAssinatura' => $nome . '.png']);
            $todas = naval_atv_q('SELECT Ordem FROM ATV_ASSINATURA WHERE IdDocumento = :d', [':d' => $base['IdDocumento']]);
            $precisa = $doc['linha']['IdColaborador'] === null ? 1 : 2;
            if (count($todas) >= $precisa) $criados[] = naval_atv_concluir($doc['documento']['numero']);
            return true;
        });
    } catch (Throwable $e) {
        foreach ($criados as $f) @unlink($f);   // a assinatura não ficou gravada: as evidências e o PDF também não
        throw $e;
    }
    naval_registar('equipamentos_assinatura', ['numero' => $linha['NumDocumento'], 'ordem' => $ordem, 'metodo' => $base['MetodoAutenticacao']]);
    return naval_atv_documento($num) + ['assinou' => $r];
}

/**
 * PDF final: o original seguido da página de assinaturas (evidências) — guardado com o SHA-256 em HashFinal.
 * Devolve o caminho do ficheiro assinado (para ser apagado se a transacção não chegar ao fim).
 */
function naval_atv_concluir(string $num): string
{
    $doc = naval_atv_documento($num);
    $l = $doc['linha'];
    if ($l['TipoDocumento'] === 'AUTO_SUBSTITUICAO') naval_atv_validar_substituicao($doc);
    $ass = naval_atv_q('SELECT * FROM ATV_ASSINATURA WHERE IdDocumento = :d ORDER BY Ordem', [':d' => $l['IdDocumento']]);
    $titulo = NAVAL_ATV_DOCS[$l['TipoDocumento']][1];
    $p = naval_pdf_novo(['titulo' => $titulo . ' ' . $l['NumDocumento'] . ' (assinado)', 'rodape' => $l['NumDocumento'] . ' · Página de assinaturas · Grupo Naval']);
    naval_atv_pdf_cabecalho($p, 'Assinaturas · ' . $titulo, $l['NumDocumento'], (string) $l['DataEmissao']);
    naval_pdf_campo($p, 'Documento original', $l['FicheiroPdf']);
    naval_pdf_campo($p, 'SHA-256 do original', (string) $l['HashOriginal'], 8.5);
    $metodos = ['PRESENCIAL' => 'Presencial, desenhada no ecrã, na presença da testemunha', 'CONTA_PORTAL' => 'Com a conta pessoal do portal'];
    foreach ($ass as $s) {
        naval_pdf_espacar($p, 10);
        naval_pdf_texto($p, $s['Ordem'] . '. ' . $s['NomeSignatario'] . ' · ' . $s['Qualidade'], 11, true);
        naval_pdf_campo($p, 'Método', $metodos[$s['MetodoAutenticacao']] ?? $s['MetodoAutenticacao']);
        if ($s['UtilizadorTestemunha']) naval_pdf_campo($p, 'Testemunha (portal)', $s['UtilizadorTestemunha']);
        if ($s['UtilizadorPortal']) naval_pdf_campo($p, 'Conta do portal', $s['UtilizadorPortal']);
        naval_pdf_campo($p, 'Data e hora', naval_data_pt(substr((string) $s['DataHora'], 0, 10)) . ' ' . substr((string) $s['DataHora'], 11, 8));
        naval_pdf_campo($p, 'Endereço IP', (string) $s['EnderecoIp']);
        if ($s['Latitude'] !== null) naval_pdf_campo($p, 'Localização', $s['Latitude'] . ', ' . $s['Longitude']);
        naval_pdf_campo($p, 'Navegador', (string) $s['Navegador'], 8);
        $jpg = @file_get_contents(naval_pasta('equipamentos/assinaturas') . '/' . preg_replace('/\.png$/', '.jpg', (string) $s['ImagemAssinatura']));
        naval_pdf_espacar($p, 4);
        if (!$jpg || !naval_pdf_imagem($p, $jpg, 180)) naval_pdf_texto($p, '(imagem da assinatura guardada no portal)', 9);
    }
    naval_pdf_espacar($p, 12);
    naval_pdf_texto($p, 'As assinaturas foram feitas no Portal RTM sobre o documento original identificado pelo SHA-256 acima, que não foi alterado. As imagens desenhadas, os traços e os registos de cada assinatura ficam guardados no portal como evidência.', 8.5, false, NAVAL_PDF_MARGEM, null, [0.35, 0.4, 0.5]);
    // documento assinado = o original, intacto, com a página de assinaturas acrescentada (actualização incremental)
    $original = (string) file_get_contents(naval_atv_doc_pasta() . '/' . $l['FicheiroPdf']);
    $assinado = naval_pdf_acrescentar($original, $p);
    $caminho = naval_atv_doc_pasta() . '/' . naval_atv_nome_assinado($l);
    if (file_put_contents($caminho, $assinado, LOCK_EX) === false) {
        throw new NavalErro('Não foi possível guardar o documento assinado.', 500);
    }
    $hashFinal = hash('sha256', $assinado);
    $agora = naval_atv_agora();
    naval_atv_x("UPDATE ATV_DOCUMENTO SET Estado = 'ASSINADO', HashFinal = :h, DataConclusao = :c WHERE IdDocumento = :id",
        [':h' => $hashFinal, ':c' => $agora, ':id' => $l['IdDocumento']]);
    foreach ($doc['equipamentos'] as $e) {
        naval_atv_evento_inserir($e['id'], 'DOCUMENTO_ASSINADO', ['IdDocumento' => (int) $l['IdDocumento'], 'Observacoes' => $titulo . ' ' . $l['NumDocumento']]);
    }
    if ($l['TipoDocumento'] === 'CONVENCAO_PROVA' && $l['IdColaborador'] !== null) {
        naval_atv_x('UPDATE ATV_COLABORADOR SET IdDocConvencao = :d WHERE IdColaborador = :c', [':d' => $l['IdDocumento'], ':c' => $l['IdColaborador']]);
    }
    if ($l['TipoDocumento'] === 'AUTO_SUBSTITUICAO') naval_atv_aplicar_substituicao($doc);
    return $caminho;
}

/**
 * O Auto de Substituição só se conclui se os equipamentos ainda estiverem como na emissão: o devolvido atribuído ao
 * colaborador do auto e o entregue em stock. Se algum mudou entretanto (abate, perda, outra atribuição), a última
 * assinatura é recusada e nada muda; o auto deve ser anulado e emitido de novo.
 */
function naval_atv_validar_substituicao(array $doc): void
{
    foreach ($doc['equipamentos'] as $e) {
        $a = naval_atv_obter($e['id']);
        $colab = $doc['linha']['IdColaborador'] !== null ? (int) $doc['linha']['IdColaborador'] : null;
        $ok = $a && ($e['papel'] === 'ENTREGUE' ? $a['estado'] === 'EM_STOCK' : ($a['estado'] === 'ATRIBUIDO' && $a['id_colaborador'] === $colab));
        if (!$ok) {
            throw new NavalErro('O equipamento ' . $e['id'] . ' mudou de estado desde a emissão do auto (' . ($a ? $a['estado'] : 'sem registo')
                . '): a substituição não pode ser concluída. Anule este auto e emita outro.', 409, ['codigo' => 'atv_substituicao_estado']);
        }
    }
}

/** Auto de Substituição assinado: o devolvido vai para reparação, o entregue fica atribuído e herda a associação. */
function naval_atv_aplicar_substituicao(array $doc): void
{
    $dev = $ent = null;
    foreach ($doc['equipamentos'] as $e) {
        if ($e['papel'] === 'DEVOLVIDO') $dev = naval_atv_obter($e['id']);
        if ($e['papel'] === 'ENTREGUE') $ent = naval_atv_obter($e['id']);
    }
    if (!$dev || !$ent) return;
    $idDoc = (int) $doc['linha']['IdDocumento'];
    $outro = naval_atv_fechar_associacao($dev['id'], 'Substituição ' . $doc['documento']['numero']);
    naval_atv_mudar($dev, 'SUBSTITUICAO', 'EM_REPARACAO', null, null, ['IdActivoRelacionado' => $ent['id'], 'IdDocumento' => $idDoc, 'Observacoes' => 'Substituído por ' . $ent['id']]);
    naval_atv_mudar($ent, 'SUBSTITUICAO', 'ATRIBUIDO', $dev['rota'], $dev['id_colaborador'], ['IdActivoRelacionado' => $dev['id'], 'IdDocumento' => $idDoc, 'Observacoes' => 'Substitui ' . $dev['id']]);
    if ($outro !== null) {
        naval_atv_fechar_associacao($ent['id'], 'Substituição ' . $doc['documento']['numero']);
        naval_atv_associar($ent['id'], $outro, 'Substituição ' . $doc['documento']['numero']);
    }
}

function naval_atv_nome_assinado(array $l): string
{
    return $l['NumDocumento'] . '-assinado.pdf';
}

/** Ficheiro de um documento: o assinado, se já o estiver, ou o emitido («original»). */
function naval_atv_ficheiro(string $num, string $versao = ''): array
{
    $doc = naval_atv_documento($num);
    $l = $doc['linha'];
    $f = $l['Estado'] === 'ASSINADO' && $versao !== 'original' ? naval_atv_nome_assinado($l) : $l['FicheiroPdf'];
    $c = naval_atv_doc_pasta() . '/' . $f;
    if (!is_file($c)) throw new NavalErro('Ficheiro do documento não encontrado.', 404);
    return ['nome' => $f, 'caminho' => $c];
}

/** Verificação da integridade: o original e o assinado batem com os SHA-256 guardados, e o assinado contém o original? */
function naval_atv_verificar(string $num): array
{
    $doc = naval_atv_documento($num);
    $l = $doc['linha'];
    $orig = @file_get_contents(naval_atv_doc_pasta() . '/' . $l['FicheiroPdf']);
    $okOrig = $orig !== false && hash_equals((string) $l['HashOriginal'], hash('sha256', (string) $orig));
    $okFinal = null;
    if ($l['Estado'] === 'ASSINADO') {
        $ass = @file_get_contents(naval_atv_doc_pasta() . '/' . naval_atv_nome_assinado($l));
        $okFinal = $ass !== false && hash_equals((string) $l['HashFinal'], hash('sha256', (string) $ass))
            && hash_equals((string) $l['HashOriginal'], hash('sha256', substr((string) $ass, 0, strlen((string) $orig))));
    }
    return ['numero' => $l['NumDocumento'], 'original_integro' => $okOrig, 'assinado_integro' => $okFinal, 'hash_original' => $l['HashOriginal'], 'hash_final' => $l['HashFinal']];
}
