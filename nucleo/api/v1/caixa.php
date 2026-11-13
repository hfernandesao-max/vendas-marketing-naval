<?php
/**
 * Folha de caixa diária do Marketing (nucleo/servicos/caixa.php). Recurso «caixa-marketing»: V ver, G registar,
 * validar (fechar) e reabrir. Aprovar em dois níveis: «caixa-aprovacao» G (1.º nível, o superior de quem lança) e
 * «caixa-aprovacao-final» G (2.º nível, o responsável máximo); três pessoas diferentes. Quem aprova também pode
 * reabrir uma caixa ainda sem a aprovação final, para correcção.
 *
 * GET  v1/caixa?dia=AAAA-MM-DD
 * POST {accao: gravar, dia, versao, saldo_inicial?, entregas: [{rota, empresa, valor, notas?: {nota: quantidade, moedas}, nota}], tpa: [{rota, banco, empresa, valor}],
 *       saidas (depósitos no banco): [{id?, banco, lacre, valor}], csrf}
 * POST {accao: aprovar_deposito | retirar_aprovacao_deposito, dia, versao, id, csrf}   (níveis de aprovação da caixa)
 * POST {accao: fechar, dia, versao, contagem, notas?: {5000: n, …, moedas: valor}, explicacao, csrf}
 * POST {accao: aprovar, dia, versao, csrf}
 * POST {accao: reabrir, dia, versao, motivo, csrf}
 */
defined('NAVAL_API') || exit;
naval_servico('caixa');
naval_servico('rotas');
naval_exigir(['caixa-marketing']);
$gere = naval_pode('caixa-marketing', 'G');
$niveis = array_values(array_filter([naval_pode('caixa-aprovacao', 'G') ? 1 : 0, naval_pode('caixa-aprovacao-final', 'G') ? 2 : 0]));
$aprova = (bool) $niveis;

if (!naval_e_post()) {
    $dia = (string) ($_GET['dia'] ?? date('Y-m-d'));
    if (!naval_data_valida($dia) || $dia > date('Y-m-d')) throw new NavalErro('Data inválida.', 400);
    // o que cada promotor recebeu no Pinguim BO (dinheiro e Multicaixa), para conferir as entregas
    $esperado = null;
    try {
        naval_servico('relatorio-vendas');
        $esperado = naval_caixa_esperado($dia);
    } catch (Throwable $e) {
        error_log('[caixa esperado] ' . $e->getMessage());
    }
    naval_json_assinado(['ok' => true, 'gere' => $gere, 'aprova' => $niveis, 'dois_niveis' => naval_caixa_dois_niveis(), 'caixa' => naval_caixa_ler($dia), 'esperado' => $esperado === null ? null : (object) $esperado,
        'promotores' => (object) naval_promotores(), 'notas' => naval_caixa_notas(),
        'saidas' => NAVAL_CAIXA_SAIDAS, 'bancos' => NAVAL_CAIXA_BANCOS, 'empresas' => NAVAL_CAIXA_EMPRESAS, 'eu' => (string) (((array) naval_utilizador())['login'] ?? '')]);
}

$p = naval_pedido();
naval_exigir_csrf($p);
$dia = (string) ($p['dia'] ?? '');
if (!naval_data_valida($dia) || $dia > date('Y-m-d')) throw new NavalErro('Data inválida.', 400);
$eu = (array) naval_utilizador();
$login = (string) ($eu['login'] ?? '');
$versao = isset($p['versao']) && is_numeric($p['versao']) ? (int) $p['versao'] : null;
$accao = (string) ($p['accao'] ?? '');
if ($accao === 'aprovar' && !$aprova) throw new NavalErro('O seu perfil não aprova a caixa.', 403);
if (in_array($accao, ['aprovar_deposito', 'retirar_aprovacao_deposito'], true) && !$aprova) throw new NavalErro('O seu perfil não aprova depósitos.', 403);
if ($accao === 'reabrir' && !$gere && !$aprova) throw new NavalErro('O seu perfil não permite reabrir a caixa.', 403);
if (in_array($accao, ['gravar', 'fechar'], true) && !$gere) throw new NavalErro('O seu perfil não permite registar a caixa.', 403);
switch ($accao) {
    case 'aprovar_deposito':
    case 'retirar_aprovacao_deposito':
        naval_json_assinado(['ok' => true, 'caixa' => naval_caixa_aprovar_deposito($dia, (string) ($p['id'] ?? ''), $accao === 'aprovar_deposito', $versao, $login)]);
        break;
    case 'gravar':
        // valor de cada talão do TPA: o Multicaixa que o promotor recebeu no Pinguim BO (a Josefa indica o banco e o
        // número do talão de fecho); sem ligação ao Pinguim BO fica o valor escrito, para não parar a tesouraria
        $esperado = null;
        try {
            naval_servico('relatorio-vendas');
            $esperado = naval_caixa_esperado($dia);
        } catch (Throwable $e) {
            error_log('[caixa esperado] ' . $e->getMessage());
        }
        if ($esperado !== null && isset($p['tpa']) && is_array($p['tpa'])) {
            $vistos = [];
            foreach ($p['tpa'] as $i => $l) {
                $rt = (string) (((array) $l)['rota'] ?? '');
                if ($rt === '') continue;
                if (isset($vistos[$rt])) throw new NavalErro('Um talão de fecho do TPA por promotor.', 400);
                $vistos[$rt] = true;
                $mc = round((float) ($esperado[$rt]['multicaixa'] ?? 0), 2);
                if ($mc <= 0) throw new NavalErro('O promotor ' . $rt . ' não tem Multicaixa no Pinguim BO neste dia.', 400);
                $p['tpa'][$i] = ['valor' => $mc] + (array) $l;
                $p['tpa'][$i]['valor'] = $mc;
            }
        }
        naval_json_assinado(['ok' => true, 'caixa' => naval_caixa_gravar($dia, $p, $versao, $login)]);
    case 'fechar':
        naval_json_assinado(['ok' => true, 'caixa' => naval_caixa_fechar($dia, $p['contagem'] ?? null, (string) ($p['explicacao'] ?? ''), $versao, $login, $p['notas'] ?? null)]);
    case 'aprovar':
        naval_json_assinado(['ok' => true, 'caixa' => naval_caixa_aprovar($dia, $versao, $login, $niveis)]);
    case 'reabrir':
        naval_json_assinado(['ok' => true, 'caixa' => naval_caixa_reabrir($dia, (string) ($p['motivo'] ?? ''), $versao, $login)]);
}
throw new NavalErro('Acção desconhecida.', 400);
