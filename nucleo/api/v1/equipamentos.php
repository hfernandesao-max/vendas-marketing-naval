<?php
/**
 * Equipamentos (ver nucleo/servicos/equipamentos.php e equipamentos-documentos.php).
 *
 * GET  v1/equipamentos                         → {disponivel, equipamentos, resumo, colaboradores, pode}
 * GET  v1/equipamentos/ficha?id=NAV-SMT-0001   → ficha (com origem=qr regista a leitura do código QR)
 * GET  v1/equipamentos/documentos[?numero=…]   → lista de documentos, ou um documento com equipamentos e assinaturas
 * GET  v1/equipamentos/pdf?numero=…[&versao=original] → PDF (o assinado, se existir)
 * POST v1/equipamentos {accao, csrf, …}
 *   registar | colaborador                     (Equipamentos – Administrar, G)
 *   evento | emitir | anular                   (Equipamentos – Movimentar, G; o abate exige Administrar)
 *   assinar                                    (Movimentar; ou o próprio colaborador, com a sua conta)
 *   verificar                                  (consultar)
 */
defined('NAVAL_API') || exit;   // só pela API central (api/index.php): token JWT já validado
naval_servico('equipamentos');
naval_exigir(['equipamentos']);

if (!naval_atv_configurada()) {
    naval_json(['disponivel' => false, 'erro' => 'A base de dados do módulo Equipamentos ainda não está configurada no servidor (.env: ATV_DB_NAME ou ATV_DB_DSN).']);
}
$pode = [
    'movimentar' => naval_pode('equipamentos-movimentar', 'G'),
    'administrar' => naval_pode('equipamentos-administrar', 'G'),
    'exportar' => naval_pode_exportar('equipamentos'),
];

// ---------------- Leitura ----------------
if (!naval_e_post()) {
    if (isset($_GET['pdf'])) {
        $f = naval_atv_ficheiro((string) ($_GET['numero'] ?? ''), (string) ($_GET['versao'] ?? ''));
        naval_registar('equipamentos_pdf', ['ficheiro' => $f['nome']]);
        naval_enviar_ficheiro($f['caminho'], $f['nome'], 'application/pdf');
    }
    if (isset($_GET['ficha'])) {
        naval_json(naval_atv_ficha((string) ($_GET['id'] ?? ''), ($_GET['origem'] ?? '') === 'qr') + ['pode' => $pode]);
    }
    if (isset($_GET['documentos'])) {
        if (isset($_GET['numero'])) {
            $d = naval_atv_documento((string) $_GET['numero']);
            unset($d['linha']);
            naval_json($d + ['pode' => $pode]);
        }
        $estado = (string) ($_GET['estado'] ?? '');
        naval_json(['documentos' => naval_atv_documentos(in_array($estado, ['A_ASSINAR', 'ASSINADO', 'ANULADO'], true) ? $estado : null), 'pode' => $pode]);
    }
    $lista = naval_atv_lista();
    naval_json([
        'disponivel' => true,
        'equipamentos' => $lista,
        'resumo' => naval_atv_resumo($lista),
        'colaboradores' => naval_atv_colaboradores(),
        'tipos' => array_map(function ($t) { return ['codigo' => $t['Codigo'], 'nome' => $t['Descricao'] ?? $t['Codigo']]; }, naval_atv_q('SELECT * FROM ATV_TIPO ORDER BY Codigo')),
        'pode' => $pode,
    ]);
}

// ---------------- Alterações ----------------
$q = naval_pedido();
$accao = (string) ($q['accao'] ?? '');
naval_exigir_csrf($q);
$recusar = function (string $permissao) use ($accao) {
    naval_registar('alteracao_recusada', ['accao' => 'equipamentos_' . $accao, 'motivo' => 'perfil']);
    throw new NavalErro('O seu perfil não permite esta operação (' . $permissao . ').', 403);
};

switch ($accao) {
    case 'registar':
        if (!$pode['administrar']) $recusar('Equipamentos – Administrar');
        $e = naval_atv_registar($q);
        naval_registar('equipamentos_registo', ['id' => $e['id'] ?? null]);
        naval_json(['ok' => true, 'equipamento' => $e]);
        break;
    case 'colaborador':
        if (!$pode['administrar']) $recusar('Equipamentos – Administrar');
        naval_json(['ok' => true] + naval_atv_guardar_colaborador($q));
        break;
    case 'evento':
        if (!$pode['movimentar']) $recusar('Equipamentos – Movimentar');
        $f = naval_atv_registar_evento((string) ($q['id'] ?? ''), $q);
        naval_registar('equipamentos_evento', ['id' => $q['id'] ?? null, 'tipo' => $q['tipo'] ?? null]);
        naval_json(['ok' => true, 'ficha' => $f]);
        break;
    case 'emitir':
        if (!$pode['movimentar']) $recusar('Equipamentos – Movimentar');
        naval_json(['ok' => true, 'documento' => naval_atv_emitir($q)]);
        break;
    case 'anular':
        if (!$pode['movimentar']) $recusar('Equipamentos – Movimentar');
        naval_json(['ok' => true, 'documento' => naval_atv_anular((string) ($q['numero'] ?? ''), (string) ($q['motivo'] ?? ''))]);
        break;
    case 'assinar':
        $d = naval_atv_assinar((string) ($q['numero'] ?? ''), $q, $pode['movimentar']);
        unset($d['linha']);
        naval_json(['ok' => true] + $d);
        break;
    case 'verificar':
        naval_json(['ok' => true] + naval_atv_verificar((string) ($q['numero'] ?? '')));
        break;
    default:
        throw new NavalErro('Operação desconhecida.', 400);
}
