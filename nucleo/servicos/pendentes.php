<?php
/**
 * «À sua espera»: o que aguarda a acção de quem pede, como a caixa de tarefas das apps de referência (SAP Fiori
 * «My Inbox», Microsoft Approvals, ServiceNow). Só aparece o que esta pessoa pode fazer agora, tendo em conta o nível
 * de aprovação e a separação de funções (quem lança, conta ou já aprovou um passo não aprova o seguinte; ISO/IEC
 * 27001:2022 A.5.3). É só leitura: aprovar faz-se no portal, com a sessão em dois passos (A.8.5) e a assinatura
 * registada (nome, conta, data e hora).
 *
 *   - Folha de caixa (últimos 7 dias): validada → 1.º nível (se houver) → aprovação final; depósitos no banco por
 *     aprovar (controlo duplo: quem requisita não aprova).
 *   - Conciliação das carrinhas (hoje): contar (a partir do fecho), validar a própria contagem, aprovar as diferenças
 *     (coordenador) e aprovação final (responsável).
 */
defined('NAVAL_NUCLEO') || exit;

const NAVAL_PENDENTES_DIAS = 7;

/**
 * $pode: {caixa1, caixa2, contar, coordenar, fechar} (permissões G de quem pede; «dois» fixa o circuito da caixa nos testes). $carrinhas: as rotas de hoje
 * (naval_carr_vista) ou null para as ler. Devolve {itens: [...], nomes: {login: nome}, total}.
 */
function naval_pendentes(string $login, ?array $rotas, array $pode, int $agora, ?array $carrinhas = null): array
{
    naval_servico('calendario');
    $hoje = date('Y-m-d', $agora);
    $hm = date('H:i', $agora);
    $eu = function ($x) use ($login) { return $login !== '' && strcasecmp((string) $x, $login) === 0; };
    $itens = [];
    // ── folha de caixa ──
    if (!empty($pode['caixa1']) || !empty($pode['caixa2'])) {
        naval_servico('caixa');
        $dois = $pode['dois'] ?? naval_caixa_dois_niveis();
        for ($i = NAVAL_PENDENTES_DIAS - 1; $i >= 0; $i--) {
            $dia = date('Y-m-d', strtotime($hoje . ' 12:00') - $i * 86400);
            $c = naval_json_ler(naval_caixa_ficheiro($dia));
            if (!$c || ($c['estado'] ?? '') === 'aprovada') continue;
            $depPend = 0;
            foreach ((array) ($c['saidas'] ?? []) as $l) {
                if (($l['tipo'] ?? '') !== 'deposito_banco' || !empty($l['aprovado_por'])) continue;
                $depPend++;
                if ($eu($l['requisitante'] ?? '')) continue;   // quem requisita não aprova
                $itens[] = ['tipo' => 'deposito', 'dia' => $dia, 'valor' => round((float) ($l['valor'] ?? 0), 2), 'banco' => (string) ($l['banco'] ?? ''),
                    'lacre' => (string) ($l['lacre'] ?? ''), 'por' => (string) ($l['requisitante'] ?? ''), 'em' => (string) ($l['requisitado_em'] ?? ''),
                    'atraso' => $dia < $hoje, 'url' => '../reports/#caixa-marketing'];
            }
            $estado = (string) ($c['estado'] ?? '');
            if (!in_array($estado, ['fechada', 'verificada'], true)) continue;
            $nivel = $estado === 'fechada' && $dois ? 1 : 2;
            if (empty($pode[$nivel === 1 ? 'caixa1' : 'caixa2'])) continue;
            $impedidos = $nivel === 1 ? ['fechada_por', 'alterado_por'] : ['fechada_por', 'alterado_por', 'verificada_por'];
            if (naval_config('CAIXA_SEGREGACAO', '1') !== '0' && array_filter($impedidos, function ($k) use ($c, $eu) { return $eu($c[$k] ?? ''); })) continue;
            $v = $estado === 'verificada';   // último passo: validada (por quem lança) ou aprovada no 1.º nível
            $itens[] = ['tipo' => 'caixa', 'nivel' => $nivel, 'apos' => $estado, 'dia' => $dia, 'por' => (string) ($c[$v ? 'verificada_por' : 'fechada_por'] ?? ''),
                'em' => (string) ($c[$v ? 'verificada_em' : 'fechada_em'] ?? ''), 'depositos' => $depPend,
                'diferenca' => isset($c['diferenca']) ? round((float) $c['diferenca'], 2) : null, 'atraso' => $dia < $hoje, 'url' => '../reports/#caixa-marketing'];
        }
    }
    // ── conciliação das carrinhas (hoje) ──
    if (!empty($pode['contar']) || !empty($pode['coordenar']) || !empty($pode['fechar'])) {
        $cal = naval_cal_dia($hoje);
        if ($carrinhas === null) {
            naval_servico('carrinha');
            $carrinhas = naval_carr_vista($hoje, $rotas, false)['rotas'] ?? [];
        }
        $seg = naval_config('CARRINHA_SEGREGACAO', '1') !== '0';
        $fora = $cal['tipo'] !== 'folga' && $hm >= ($cal['fecho'][1] ?? '16:00');
        foreach ($carrinhas as $r) {
            $e = (string) ($r['resumo']['estado'] ?? '');
            $base = ['tipo' => 'carrinha', 'dia' => $hoje, 'rota' => (string) $r['rota'], 'promotor' => (string) ($r['promotor'] ?? ''),
                'diferencas' => (int) ($r['resumo']['diferencas'] ?? 0), 'atraso' => $fora, 'url' => '../reports/#conciliacao-carrinha'];
            $ja = function (array $campos) use ($r, $eu, $seg) { return $seg && array_filter($campos, function ($k) use ($r, $eu) { return $eu($r[$k] ?? ''); }); };
            if ($e === 'por_contar' && !empty($pode['contar']) && $cal['tipo'] !== 'folga' && $hm >= ($cal['fecho'][0] ?? '15:30')) {
                $itens[] = ['accao' => 'contar'] + $base;
            } elseif ($e === 'por_validar' && !empty($pode['contar']) && $eu($r['contado_por'] ?? '')) {
                $itens[] = ['accao' => 'validar', 'por' => (string) $r['contado_por'], 'em' => (string) ($r['contado_em'] ?? '')] + $base;
            } elseif ($e === 'por_aprovar' && !empty($pode['coordenar']) && !$ja(['contado_por', 'validado_por'])) {
                $itens[] = ['accao' => 'coordenar', 'por' => (string) ($r['validado_por'] ?? $r['contado_por'] ?? ''), 'em' => (string) ($r['validado_em'] ?? '')] + $base;
            } elseif ($e === 'por_fechar' && !empty($pode['fechar']) && !$ja(['contado_por', 'validado_por', 'coordenado_por'])) {
                $itens[] = ['accao' => 'fechar', 'por' => (string) ($r['coordenado_por'] ?? ''), 'em' => (string) ($r['coordenado_em'] ?? '')] + $base;
            }
        }
    }
    // os atrasados primeiro, depois os mais antigos
    $ordem = function ($x) { return [(int) !$x['atraso'], $x['dia'], $x['tipo'], $x['rota'] ?? '', $x['lacre'] ?? '']; };
    usort($itens, function ($a, $b) use ($ordem) { return $ordem($a) <=> $ordem($b); });
    return ['itens' => $itens, 'nomes' => (object) naval_nomes_assinantes($itens), 'total' => count($itens)];
}

/** Os pendentes da conta com sessão (permissões e âmbito dela). */
function naval_pendentes_conta(string $login): array
{
    return naval_pendentes($login, naval_rotas(), [
        'caixa1' => naval_pode('caixa-aprovacao', 'G'),
        'caixa2' => naval_pode('caixa-aprovacao-final', 'G'),
        'contar' => naval_pode('conciliacao-carrinha', 'G'),
        'coordenar' => naval_pode('conciliacao-coordenacao', 'G'),
        'fechar' => naval_pode('conciliacao-fecho', 'G'),
    ], time());
}
