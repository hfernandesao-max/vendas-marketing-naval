<?php
/**
 * Folha de caixa diária do Marketing (tesouraria): substitui a folha Excel «Cash control» por um registo no portal,
 * com as regras habituais de gestão de caixa:
 *   - saldo inicial = saldo final aprovado do dia anterior (só o primeiro dia se escreve à mão);
 *   - entradas: dinheiro entregue por cada promotor (uma linha por entrega) e talões do TPA (Multicaixa) por banco;
 *   - saídas: depósito no banco, fundo de maneio de cada empresa, transferência para a gestão e outras, com descrição;
 *   - saldo final teórico = inicial + dinheiro entregue − saídas; a contagem física fecha a caixa e a diferença
 *     (falta ou sobra) fica registada e tem de ser explicada;
 *   - ciclo: aberta (regista-se) → fechada (com a contagem) → aprovada (por outra pessoa: segregação de funções).
 *     Fechada pode reabrir-se para corrigir (com motivo); aprovada fica imutável.
 * Valores em Kz, arredondados a 2 casas. Ficheiros: dados-privados/caixa/AAAA-MM-DD.json.
 */
defined('NAVAL_NUCLEO') || exit;

const NAVAL_CAIXA_SAIDAS = [
    'deposito_banco' => 'Depósito no banco',
    'fundo_maneio' => 'Fundo de maneio',
    'transferencia_gestao' => 'Transferência para a gestão',
    'outro' => 'Outra saída',
];
const NAVAL_CAIXA_BANCOS = ['BPC', 'ATLANTICO', 'BAI', 'BFA', 'BIC', 'SOL', 'KEVE', 'STANDARD', 'OUTRO'];
const NAVAL_CAIXA_EMPRESAS = ['NAVAL', 'HIGITEC', 'FOODTEC', 'REFITECH'];

/** Notas de kwanza em circulação (série de 2020 do BNA), da maior para a menor; CAIXA_NOTAS muda a lista. */
function naval_caixa_notas(): array
{
    $n = array_values(array_filter(array_map('intval', explode(',', naval_config('CAIXA_NOTAS', '5000,2000,1000,500,200'))), function ($x) { return $x > 0; }));
    rsort($n);
    return $n ?: [5000, 2000, 1000, 500, 200];
}

/**
 * Contagem por notas ({nota: quantidade, moedas: valor}) validada; devolve [contagem, total] ou [null, null] sem
 * contagem. Quantidades inteiras, moedas em Kz.
 */
function naval_caixa_por_notas($x): array
{
    if (!is_array($x) || !$x) return [null, null];
    $ok = array_flip(array_map('strval', naval_caixa_notas()));
    $out = [];
    $total = 0.0;
    foreach ($x as $k => $q) {
        $k = (string) $k;
        if ($q === '' || $q === null) continue;
        if ($k === 'moedas') {
            if (!is_numeric($q) || (float) $q < 0 || (float) $q > 1e9) throw new NavalErro('Valor das moedas inválido.', 400);
            $out['moedas'] = round((float) $q, 2);
            $total += $out['moedas'];
            continue;
        }
        if (!isset($ok[$k])) throw new NavalErro('Nota desconhecida: ' . $k . ' Kz.', 400);
        if (!is_numeric($q) || (float) $q < 0 || floor((float) $q) != (float) $q || (float) $q > 1e6) throw new NavalErro('Quantidade de notas inválida (' . $k . ' Kz).', 400);
        if ((int) $q === 0) continue;
        $out[$k] = (int) $q;
        $total += (int) $k * (int) $q;
    }
    return $out ? [$out, round($total, 2)] : [null, null];
}

function naval_caixa_ficheiro(string $dia): string
{
    if (!naval_data_valida($dia)) throw new NavalErro('Data inválida.', 400);
    return naval_pasta('caixa') . '/' . $dia . '.json';
}

/** Saldo final aprovado do último dia antes de $dia (null se não houver nenhum). */
function naval_caixa_anterior(string $dia): ?array
{
    $fs = (array) glob(naval_pasta('caixa') . '/????-??-??.json');
    rsort($fs);
    foreach ($fs as $f) {
        $d = basename((string) $f, '.json');
        if ($d >= $dia) continue;
        $c = naval_json_ler((string) $f);
        if (($c['estado'] ?? '') === 'aprovada') return ['dia' => $d, 'saldo' => (float) $c['contagem_fisica']];
        return ['dia' => $d, 'saldo' => null, 'estado' => $c['estado'] ?? 'aberta'];   // o dia anterior ainda não fechou
    }
    return null;
}

/** Folha do dia (nova se ainda não existir), com os totais calculados. */
function naval_caixa_ler(string $dia): array
{
    $c = naval_json_ler(naval_caixa_ficheiro($dia));
    if (!$c) {
        $ant = naval_caixa_anterior($dia);
        $c = ['dia' => $dia, 'estado' => 'aberta', 'versao' => 0, 'saldo_inicial' => $ant['saldo'] ?? null,
            'saldo_inicial_origem' => $ant && $ant['saldo'] !== null ? 'dia ' . $ant['dia'] : 'manual',
            'entregas' => [], 'tpa' => [], 'saidas' => [], 'contagem_fisica' => null, 'explicacao' => '', 'historico' => []];
    }
    return naval_caixa_totais($c);
}

function naval_caixa_totais(array $c): array
{
    $r = function ($v) { return round((float) $v, 2); };
    $c['total_entregas'] = $r(array_sum(array_column($c['entregas'], 'valor')));
    $c['total_tpa'] = $r(array_sum(array_column($c['tpa'], 'valor')));
    $c['total_saidas'] = $r(array_sum(array_column($c['saidas'], 'valor')));
    $c['saldo_teorico'] = $c['saldo_inicial'] === null ? null : $r((float) $c['saldo_inicial'] + $c['total_entregas'] - $c['total_saidas']);
    $c['diferenca'] = $c['contagem_fisica'] === null || $c['saldo_teorico'] === null ? null : $r((float) $c['contagem_fisica'] - $c['saldo_teorico']);
    return $c;
}

/** Valida as linhas recebidas do portal. */
function naval_caixa_linhas(array $p): array
{
    $valor = function ($v, string $o) {
        if (!is_numeric($v) || (float) $v <= 0 || (float) $v > 1e11) throw new NavalErro('Valor inválido em ' . $o . '.', 400);
        return round((float) $v, 2);
    };
    $rota = function ($v) { return preg_match('/^[A-Za-z0-9]{1,10}$/', (string) $v) ? (string) $v : ''; };
    $entregas = [];
    foreach (array_slice((array) ($p['entregas'] ?? []), 0, 500) as $l) {
        $l = (array) $l;
        $rt = $rota($l['rota'] ?? '');
        if ($rt === '') throw new NavalErro('Escolha o promotor de cada entrega.', 400);
        // o dinheiro entregue declara-se só por notas e moedas (contagem por denominação): o valor é o total contado
        [$notas, $totalNotas] = naval_caixa_por_notas($l['notas'] ?? null);
        if ($totalNotas === null) throw new NavalErro('Conte as notas e moedas de cada entrega.', 400);
        $entregas[] = ['rota' => $rt, 'empresa' => in_array($l['empresa'] ?? '', NAVAL_CAIXA_EMPRESAS, true) ? $l['empresa'] : '', 'valor' => $valor($totalNotas, 'entregas'),
            'notas' => $notas,
            'nota' => naval_texto($l['nota'] ?? '', 120)];
    }
    $tpa = [];
    foreach (array_slice((array) ($p['tpa'] ?? []), 0, 500) as $l) {
        $l = (array) $l;
        $rt = $rota($l['rota'] ?? '');
        if ($rt === '') throw new NavalErro('Escolha o promotor de cada talão do TPA.', 400);
        $banco = strtoupper(naval_texto($l['banco'] ?? '', 20));
        if (!in_array($banco, NAVAL_CAIXA_BANCOS, true)) throw new NavalErro('Escolha o banco de cada talão do TPA.', 400);
        // talão de fecho do TPA (fecho do lote): o número identifica-o na conferência com o extracto do banco
        $talao = naval_texto($l['talao'] ?? '', 30);
        if ($talao === '' || !preg_match('/^[\w.\-\/ ]{1,30}$/u', $talao)) throw new NavalErro('Indique o número do talão de fecho de cada TPA.', 400);
        $tpa[] = ['rota' => $rt, 'banco' => $banco, 'talao' => $talao, 'empresa' => in_array($l['empresa'] ?? '', NAVAL_CAIXA_EMPRESAS, true) ? $l['empresa'] : '',
            'valor' => $valor($l['valor'] ?? null, 'talões do TPA'), 'nota' => naval_texto($l['nota'] ?? '', 120)];
    }
    $saidas = [];
    foreach (array_slice((array) ($p['saidas'] ?? []), 0, 200) as $l) {
        $l = (array) $l;
        $tipo = (string) ($l['tipo'] ?? '');
        if (!isset(NAVAL_CAIXA_SAIDAS[$tipo])) throw new NavalErro('Escolha o tipo de cada saída.', 400);
        $desc = naval_texto($l['descricao'] ?? '', 160);
        if ($tipo === 'outro' && mb_strlen($desc) < 5) throw new NavalErro('Descreva cada «Outra saída».', 400);
        $emp = in_array($l['empresa'] ?? '', NAVAL_CAIXA_EMPRESAS, true) ? $l['empresa'] : '';
        if ($tipo === 'fundo_maneio' && $emp === '') throw new NavalErro('Indique a empresa de cada fundo de maneio.', 400);
        $saidas[] = ['tipo' => $tipo, 'empresa' => $emp, 'descricao' => $desc, 'valor' => $valor($l['valor'] ?? null, 'saídas')];
    }
    return [$entregas, $tpa, $saidas];
}

function naval_caixa_alterar(string $dia, ?int $versao, callable $f): array
{
    return naval_exclusivo('caixa-' . $dia, function () use ($dia, $versao, $f) {
        $c = naval_caixa_ler($dia);
        if ($versao !== null && $versao !== (int) $c['versao']) {
            throw new NavalErro('A folha de caixa foi alterada por outra pessoa entretanto. Volte a abrir para ver a versão actual.', 409, ['codigo' => 'versao']);
        }
        $c = naval_caixa_totais($f($c));
        $c['versao'] = (int) $c['versao'] + 1;
        $grava = $c;
        foreach (['total_entregas', 'total_tpa', 'total_saidas', 'saldo_teorico', 'diferenca'] as $k) unset($grava[$k]);
        naval_json_gravar(naval_caixa_ficheiro($dia), $grava);
        return $c;
    });
}

function naval_caixa_hist(array &$c, string $accao, string $login, array $extra = []): void
{
    $c['historico'][] = ['em' => date('Y-m-d H:i:s'), 'por' => $login, 'accao' => $accao] + $extra;
}

/** Grava a folha aberta (entregas, talões do TPA, saídas e, no primeiro dia, o saldo inicial). */
function naval_caixa_gravar(string $dia, array $p, ?int $versao, string $login): array
{
    if ($dia > date('Y-m-d')) throw new NavalErro('Não se regista a caixa de um dia que ainda não chegou.', 400);
    [$entregas, $tpa, $saidas] = naval_caixa_linhas($p);
    return naval_caixa_alterar($dia, $versao, function ($c) use ($p, $entregas, $tpa, $saidas, $login) {
        if ($c['estado'] !== 'aberta') throw new NavalErro('A caixa deste dia já está fechada. Reabra-a para corrigir.', 409);
        if ($c['saldo_inicial_origem'] === 'manual') {
            $si = $p['saldo_inicial'] ?? null;
            if ($si !== null && $si !== '') {
                if (!is_numeric($si) || (float) $si < 0) throw new NavalErro('Saldo inicial inválido.', 400);
                $c['saldo_inicial'] = round((float) $si, 2);
            }
        }
        $c['entregas'] = $entregas;
        $c['tpa'] = $tpa;
        $c['saidas'] = $saidas;
        $c['alterado_por'] = $login;
        $c['alterado_em'] = date('Y-m-d H:i:s');
        naval_caixa_hist($c, 'gravada', $login);
        return $c;
    });
}

/** Fecha a caixa com a contagem física; uma diferença tem de ser explicada. */
function naval_caixa_fechar(string $dia, $contagem, string $explicacao, ?int $versao, string $login, $notas = null): array
{
    // contagem física por notas (prática de fecho de caixa): o total vem das notas e moedas contadas
    [$porNotas, $totalNotas] = naval_caixa_por_notas($notas);
    if ($totalNotas !== null) $contagem = $totalNotas;
    if (!is_numeric($contagem) || (float) $contagem < 0) throw new NavalErro('Indique o dinheiro contado na caixa.', 400);
    $explicacao = naval_texto($explicacao, 300);
    $c = naval_caixa_alterar($dia, $versao, function ($c) use ($contagem, $explicacao, $login, $porNotas) {
        if ($c['estado'] !== 'aberta') throw new NavalErro('A caixa deste dia já está fechada.', 409);
        if ($c['saldo_inicial'] === null) throw new NavalErro('Falta o saldo inicial (o dia anterior ainda não foi aprovado ou é o primeiro dia).', 409);
        $c['contagem_fisica'] = round((float) $contagem, 2);
        $c['contagem_notas'] = $porNotas;
        $c = naval_caixa_totais($c);
        if (abs((float) $c['diferenca']) >= 0.01 && mb_strlen($explicacao) < 5) {
            throw new NavalErro('A contagem não bate com o saldo teórico: explique a diferença.', 400, ['diferenca' => $c['diferenca']]);
        }
        $c['explicacao'] = $explicacao;
        $c['estado'] = 'fechada';
        $c['fechada_por'] = $login;
        $c['fechada_em'] = date('Y-m-d H:i:s');
        naval_caixa_hist($c, 'fechada', $login, ['diferenca' => $c['diferenca']]);
        return $c;
    });
    naval_registar('caixa_fechada', ['dia' => $dia, 'diferenca' => $c['diferenca']]);
    return $c;
}

/** Aprova a caixa fechada (outra pessoa que não quem registou nem quem fechou). Fica imutável. */
function naval_caixa_aprovar(string $dia, ?int $versao, string $login): array
{
    $c = naval_caixa_alterar($dia, $versao, function ($c) use ($login) {
        if ($c['estado'] === 'aprovada') throw new NavalErro('A caixa deste dia já foi aprovada.', 409);
        if ($c['estado'] !== 'fechada') throw new NavalErro('Feche a caixa antes de a aprovar.', 409);
        if (naval_config('CAIXA_SEGREGACAO', '1') !== '0') {
            foreach (['fechada_por', 'alterado_por'] as $k) {
                if (strcasecmp((string) ($c[$k] ?? ''), $login) === 0) throw new NavalErro('Segregação de funções: quem registou ou fechou a caixa não a aprova.', 403);
            }
        }
        $c['estado'] = 'aprovada';
        $c['aprovada_por'] = $login;
        $c['aprovada_em'] = date('Y-m-d H:i:s');
        naval_caixa_hist($c, 'aprovada', $login);
        return $c;
    });
    naval_registar('caixa_aprovada', ['dia' => $dia]);
    return $c;
}

/** Reabre uma caixa fechada (ainda não aprovada) para corrigir, com motivo. */
function naval_caixa_reabrir(string $dia, string $motivo, ?int $versao, string $login): array
{
    $motivo = naval_texto($motivo, 300);
    if (mb_strlen($motivo) < 5) throw new NavalErro('Indique o motivo da reabertura.', 400);
    $c = naval_caixa_alterar($dia, $versao, function ($c) use ($motivo, $login) {
        if ($c['estado'] === 'aprovada') throw new NavalErro('A caixa aprovada não se reabre.', 409);
        if ($c['estado'] !== 'fechada') throw new NavalErro('A caixa deste dia está aberta.', 409);
        $c['estado'] = 'aberta';
        $c['contagem_fisica'] = null;
        $c['contagem_notas'] = null;
        naval_caixa_hist($c, 'reaberta', $login, ['motivo' => $motivo]);
        return $c;
    });
    naval_registar('caixa_reaberta', ['dia' => $dia]);
    return $c;
}
