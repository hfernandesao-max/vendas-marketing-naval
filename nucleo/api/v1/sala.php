<?php
/**
 * Painel da sala comercial (ecrã de TV): indicadores do dia por rota, sem conta nem palavra-passe, por uma ligação
 * própria (código aleatório de 256 bits) que o super administrador ou quem gere o painel cria e revoga no portal.
 *
 * GET v1/sala (cabeçalho X-Painel-Codigo) indicadores agregados (sem clientes, NIF nem documentos); o código vai no
 *                               fragmento do endereço da página (#t=…), que não chega aos registos do Apache nem a intermediários
 * GET v1/sala/previa?local=interna|aberta   os mesmos dados, para a pré-visualização ao vivo no portal (Apresentação e
 *                               página do painel) — sessão do portal com «painel-sala» ou «apresentacao»
 * GET v1/sala/ligacoes          ligações criadas (sem o código) — recurso «painel-sala» (G)
 * POST v1/sala/ligacoes {accao: criar (nome) | revogar (id) | configurar (id, ecras, segundos) | recarregar (id), csrf}   o
 *                               código só se mostra uma vez, ao criar; «configurar» escolhe os ecrãs, a ordem (arrastada no
 *                               portal) e o tempo de cada um; «recarregar» faz o ecrã recarregar a página na leitura seguinte
 * POST v1/sala/ligacoes {accao: manutencao, id, minutos (0 = fechar), csrf}   só o super administrador: a app da TV
 *                               («RTM Painel») abre a depuração (adb) até à hora indicada e fecha-a sozinha depois
 *
 * Actualizações: cada resposta ao ecrã leva a versão do painel (naval_sala_versao); o ecrã recarrega-se sozinho quando
 * ela muda (portal actualizado ou «Recarregar ecrã») e devolve a versão que tem (X-Painel-Versao), para o portal mostrar
 * se cada ecrã já está na versão actual.
 *
 * O código guarda-se só como SHA-256 (como uma palavra-passe); cada acesso fica registado na ligação (última hora e IP).
 *
 * Onde fica o ecrã (classificação da informação, ISO/IEC 27001 A.5.12 e A.7.7 «ecrã limpo»; protecção de dados
 * pessoais, Lei 22/11): cada ligação tem um local, e o servidor só envia o que esse local pode mostrar.
 *   - «interna» (sala comercial fechada, só a equipa): vendas em Kz, classificações por rota com o nome do promotor e
 *     destaques — reconhecimento positivo, nunca o último lugar;
 *   - «aberta» (open space, recepção, corredor: visitantes, fornecedores e outras equipas podem ver): só o progresso
 *     da equipa em percentagem e tendências (▲ ▼), contagens e destaques por rota, sem valores em Kz, sem nomes de
 *     pessoas e sem classificações. As classificações não chegam a ser enviadas.
 *   - em nenhum ecrã: clientes, NIF, documentos, caixa, faltas, perdas, anulados ou risco por pessoa.
 *
 * Ligação presa ao ecrã (opcional): depois do primeiro acesso, quem gere o painel vê o IP de onde o ecrã acedeu e pode
 * «fixar» a ligação a esse IP (accao fixar_ip; soltar_ip desfaz). A partir daí, o mesmo endereço aberto noutro sítio é
 * recusado (403) e fica registado, mesmo que alguém o copie.
 */
defined('NAVAL_API') || exit;
naval_servico('sala');

function naval_sala_ficheiro(): string
{
    return naval_pasta('sala') . '/ligacoes.json';
}

if (($_GET['previa'] ?? '') === '1') {
    $GLOBALS['naval_api_sensivel'] = true;
    if (!naval_pode('painel-sala') && !naval_pode('apresentacao')) throw new NavalErro('Sem acesso ao painel da sala comercial.', 403);
    $local = in_array($_GET['local'] ?? '', NAVAL_SALA_LOCAIS, true) ? (string) $_GET['local'] : 'interna';
    naval_json(naval_sala_resposta($local, null));
}

if (($_GET['gerir'] ?? '') === '1') {
    $GLOBALS['naval_api_sensivel'] = true;
    if (!naval_pode('painel-sala', 'G')) throw new NavalErro('Sem permissão para gerir o painel da sala comercial.', 403);
    $lig = naval_json_ler(naval_sala_ficheiro());
    $vista = function (array $l) {
        return array_map(function ($id, $x) {
            unset($x['hash']);
            $v = naval_sala_versao($x);
            return ['id' => $id] + $x + ['versao_actual' => $v, 'em_dia' => (string) ($x['versao_ecra'] ?? '') === $v];
        }, array_keys($l), $l);
    };
    if (!naval_e_post()) naval_json(['ok' => true, 'ligacoes' => $vista($lig)]);
    $p = naval_pedido();
    naval_exigir_csrf($p);
    $eu = (array) naval_utilizador();
    $r = naval_exclusivo('sala-ligacoes', function () use ($p, $eu) {
        $lig = naval_json_ler(naval_sala_ficheiro());
        $accao = (string) ($p['accao'] ?? '');
        if ($accao === 'criar') {
            $nome = naval_texto($p['nome'] ?? '', 60);
            if ($nome === '') throw new NavalErro('Indique onde fica o ecrã (ex.: «Sala comercial · Viana»).', 400);
            $local = (string) ($p['local'] ?? '');
            if (!in_array($local, NAVAL_SALA_LOCAIS, true)) throw new NavalErro('Indique se o ecrã fica numa sala interna ou num espaço aberto.', 400);
            if (count(array_filter($lig, function ($x) { return empty($x['revogado']); })) >= 20) throw new NavalErro('Demasiadas ligações activas: revogue as que já não usa.', 400);
            $codigo = bin2hex(random_bytes(32));
            $id = bin2hex(random_bytes(6));
            $lig[$id] = ['nome' => $nome, 'hash' => hash('sha256', $codigo), 'criado' => date('Y-m-d H:i:s'), 'criado_por' => $eu['login'], 'revogado' => null, 'ultimo_acesso' => null, 'local' => $local];
            naval_json_gravar(naval_sala_ficheiro(), $lig);
            naval_registar('sala_ligacao_criada', ['id' => $id, 'nome' => $nome, 'local' => $local]);
            return ['id' => $id, 'codigo' => $codigo];
        }
        if ($accao === 'configurar') {
            $id = (string) ($p['id'] ?? '');
            if (!isset($lig[$id]) || !empty($lig[$id]['revogado'])) throw new NavalErro('Ligação desconhecida.', 404);
            $local = in_array($p['local'] ?? '', NAVAL_SALA_LOCAIS, true) ? (string) $p['local'] : (string) ($lig[$id]['local'] ?? 'interna');
            $ecras = array_values(array_unique(array_filter((array) ($p['ecras'] ?? []), function ($e) { return in_array($e, NAVAL_SALA_ECRAS, true); })));
            if ($local === 'aberta' && array_diff($ecras, NAVAL_SALA_ECRAS_ABERTA)) {
                throw new NavalErro('Num espaço aberto não podem estar as classificações (com nomes e valores): ficam para a sala interna.', 400);
            }
            if (!$ecras) throw new NavalErro('Escolha pelo menos um ecrã.', 400);
            $lig[$id]['local'] = $local;
            $seg = (int) ($p['segundos'] ?? 20);
            $lig[$id]['config'] = ['ecras' => $ecras, 'segundos' => in_array($seg, [10, 20, 30, 60], true) ? $seg : 20, 'personalizado' => true];
            naval_json_gravar(naval_sala_ficheiro(), $lig);
            naval_registar('sala_ligacao_configurada', ['id' => $id, 'ecras' => $ecras, 'local' => $local]);
            return ['id' => $id];
        }
        if ($accao === 'fixar_ip' || $accao === 'soltar_ip') {
            $id = (string) ($p['id'] ?? '');
            if (!isset($lig[$id]) || !empty($lig[$id]['revogado'])) throw new NavalErro('Ligação desconhecida.', 404);
            if ($accao === 'soltar_ip') {
                unset($lig[$id]['ip_fixo']);
            } else {
                $ip = trim((string) ($p['ip'] ?? ($lig[$id]['ip'] ?? '')));
                if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
                    throw new NavalErro('Ainda não há acessos deste ecrã: abra primeiro a ligação no ecrã e volte a tentar.', 409);
                }
                $lig[$id]['ip_fixo'] = $ip;
            }
            naval_json_gravar(naval_sala_ficheiro(), $lig);
            naval_registar('sala_ligacao_' . $accao, ['id' => $id, 'ip' => $lig[$id]['ip_fixo'] ?? null]);
            return ['id' => $id];
        }
        if ($accao === 'manutencao') {
            // a depuração da TV (adb) só abre a pedido do super administrador e fecha sozinha no fim do prazo
            if (naval_perfil() !== 'SADM') throw new NavalErro('Só o super administrador abre a manutenção da TV.', 403);
            $id = (string) ($p['id'] ?? '');
            if (!isset($lig[$id]) || !empty($lig[$id]['revogado'])) throw new NavalErro('Ligação desconhecida.', 404);
            $min = (int) ($p['minutos'] ?? 0);
            if ($min < 0 || $min > 240) throw new NavalErro('Duração inválida (até 4 horas).', 400);
            if ($min === 0) unset($lig[$id]['manutencao_ate']);
            else $lig[$id]['manutencao_ate'] = date('Y-m-d H:i:s', time() + $min * 60);
            $lig[$id]['manutencao_por'] = $eu['login'];
            naval_json_gravar(naval_sala_ficheiro(), $lig);
            naval_registar('sala_ligacao_manutencao', ['id' => $id, 'minutos' => $min]);
            return ['id' => $id];
        }
        if ($accao === 'recarregar') {
            $id = (string) ($p['id'] ?? '');
            if (!isset($lig[$id]) || !empty($lig[$id]['revogado'])) throw new NavalErro('Ligação desconhecida.', 404);
            $lig[$id]['recarregar_em'] = date('Y-m-d H:i:s');
            $lig[$id]['recarregar_por'] = $eu['login'];
            naval_json_gravar(naval_sala_ficheiro(), $lig);
            naval_registar('sala_ligacao_recarregar', ['id' => $id]);
            return ['id' => $id];
        }
        if ($accao === 'revogar') {
            $id = (string) ($p['id'] ?? '');
            if (!isset($lig[$id])) throw new NavalErro('Ligação desconhecida.', 404);
            $lig[$id]['revogado'] = date('Y-m-d H:i:s');
            $lig[$id]['revogado_por'] = $eu['login'];
            naval_json_gravar(naval_sala_ficheiro(), $lig);
            naval_registar('sala_ligacao_revogada', ['id' => $id]);
            return ['id' => $id];
        }
        throw new NavalErro('Acção desconhecida.', 400);
    });
    naval_json(['ok' => true] + $r + ['ligacoes' => $vista(naval_json_ler(naval_sala_ficheiro()))]);
}

// ── Ecrã da sala: só com uma ligação válida ──
$GLOBALS['naval_api_sensivel'] = true;   // o código vai no endereço: a resposta nunca fica em caches intermédias
$codigo = (string) ($_SERVER['HTTP_X_PAINEL_CODIGO'] ?? '');
$hash = preg_match('/^[a-f0-9]{64}$/', $codigo) ? hash('sha256', $codigo) : '';
$id = null;
$config = null;
$local = 'interna';
$ipFixo = '';
foreach (naval_json_ler(naval_sala_ficheiro()) as $k => $x) {
    if ($hash !== '' && empty($x['revogado']) && hash_equals((string) $x['hash'], $hash)) {
        $id = $k;
        $config = $x['config'] ?? null;
        $local = in_array($x['local'] ?? '', NAVAL_SALA_LOCAIS, true) ? (string) $x['local'] : 'interna';
        $ipFixo = (string) ($x['ip_fixo'] ?? '');
    }
}
// ligação presa ao IP do ecrã: noutro sítio é recusada (e registada, no máximo uma vez por minuto e por IP)
if ($id !== null && $ipFixo !== '' && naval_ip_cliente() !== $ipFixo) {
    $marca = naval_pasta('limites') . '/sala-fora-' . substr(hash('sha256', naval_ip_cliente() . $id), 0, 16);
    if (!is_file($marca) || filemtime($marca) < time() - 60) {
        @touch($marca);
        naval_registar('sala_acesso_fora_do_ecra', ['id' => $id], 'painel-sala');
    }
    throw new NavalErro('Esta ligação do painel só abre no ecrã a que está associada.', 403);
}
if ($id === null) {
    // no máximo um registo por minuto e por IP (o ritmo da API já limita as tentativas)
    $ip = naval_ip_cliente();
    $marca = naval_pasta('limites') . '/sala-recusa-' . substr(hash('sha256', $ip), 0, 16);
    if (!is_file($marca) || filemtime($marca) < time() - 60) {
        @touch($marca);
        naval_registar('sala_acesso_recusado', [], 'painel-sala');
    }
    throw new NavalErro('Ligação do painel inválida ou revogada.', 403);
}
// último acesso (no máximo um registo por minuto) e a versão do painel que o ecrã tem (quando muda)
$versaoEcra = (string) ($_SERVER['HTTP_X_PAINEL_VERSAO'] ?? '');
if (!preg_match('/^[a-f0-9]{10}(-[a-f0-9]{6})?$/', $versaoEcra)) $versaoEcra = '';
$versao = '';
naval_exclusivo('sala-ligacoes', function () use ($id, $versaoEcra, &$versao) {
    $lig = naval_json_ler(naval_sala_ficheiro());
    if (!isset($lig[$id])) return;
    $versao = naval_sala_versao($lig[$id]);
    $acesso = (string) ($lig[$id]['ultimo_acesso'] ?? '') <= date('Y-m-d H:i:s', time() - 60);
    $mudou = $versaoEcra !== '' && $versaoEcra !== (string) ($lig[$id]['versao_ecra'] ?? '');
    if (!$acesso && !$mudou) return;
    if ($acesso) {
        $lig[$id]['ultimo_acesso'] = date('Y-m-d H:i:s');
        $lig[$id]['ip'] = naval_ip_cliente();
    }
    if ($mudou) $lig[$id]['versao_ecra'] = $versaoEcra;
    naval_json_gravar(naval_sala_ficheiro(), $lig);
});
// manutenção da TV pedida no portal: a app «RTM Painel» abre a depuração (adb) enquanto durar
$manut = (string) (naval_json_ler(naval_sala_ficheiro())[$id]['manutencao_ate'] ?? '');
naval_json(naval_sala_resposta($local, $config) + ['versao' => $versao, 'manutencao' => $manut !== '' && $manut > date('Y-m-d H:i:s')]);
