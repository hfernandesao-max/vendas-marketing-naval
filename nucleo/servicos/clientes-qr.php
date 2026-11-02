<?php
/**
 * Código QR do cliente (cartaz A4 colado no estabelecimento): o cliente lê o código com o telemóvel e, sem conta,
 * faz uma encomenda, actualiza os dados da empresa ou aceita receber mensagens por WhatsApp. TUDO fica pendente até o
 * back-office validar (página «Pedidos dos clientes»); nada vai directamente para o Pinguim BO (só de leitura).
 *
 * Armazenamento (pasta privada, fora da pasta pública e do GitHub):
 *   clientes-qr/codigos.json           código → cliente (código aleatório de 128 bits; vários por cliente ao longo do tempo)
 *   clientes-qr/pedidos/<id>.json      cada pedido, com o estado (pendente | aprovado | recusado) e a decisão
 *   clientes-qr/dados-aprovados.jsonl  alterações de dados aprovadas, para o back-office lançar no Pinguim BO
 *   clientes-qr/consentimentos.json    consentimentos de comunicações (WhatsApp), com data, origem e texto aceite
 *
 * Protecções da página pública: código não adivinhável, limites por código, por cliente e por IP, campo-armadilha
 * para robôs, tamanhos limitados e só texto (sem HTML); a página mostra só o nome do estabelecimento.
 */
defined('NAVAL_NUCLEO') || exit;
require_once __DIR__ . '/analise.php';

const NAVAL_CQR_TIPOS = ['encomenda', 'dados', 'consentimento'];
const NAVAL_CQR_LIMITE_DIA_CLIENTE = 6;
const NAVAL_CQR_LIMITE_DIA_IP = 30;
const NAVAL_CQR_TEXTO_CONSENTIMENTO = 'Aceito receber do Grupo Naval, por WhatsApp, promoções, novidades e avisos de visita. Posso deixar de receber '
    . 'a qualquer momento, respondendo «SAIR». Os meus dados são tratados nos termos da Lei n.º 22/11 (protecção de dados pessoais).';

function naval_cqr_pasta(string $sub = ''): string
{
    return naval_pasta('clientes-qr' . ($sub !== '' ? '/' . $sub : ''));
}

/** Código novo: 16 bytes aleatórios em base64url (22 caracteres). */
function naval_cqr_codigo_novo(): string
{
    return rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
}

function naval_cqr_codigo_valido(string $c): bool
{
    return preg_match('/^[A-Za-z0-9_-]{22}$/', $c) === 1;
}

/** Endereço impresso no cartaz (curto, pelo domínio dos códigos QR, se configurado; senão o do portal). */
function naval_cqr_url(string $codigo): string
{
    $curto = rtrim(naval_config('QR_URL_CURTO', ''), '/');
    if ($curto !== '') return $curto . '/c/' . $codigo;
    return rtrim(naval_config('PORTAL_URL', 'https://geo.naval.co.ao:2051'), '/') . '/c/?k=' . $codigo;
}

/** Cliente de um código activo: {codigo, cliente, nome, rota} ou null. */
function naval_cqr_cliente(string $codigo): ?array
{
    if (!naval_cqr_codigo_valido($codigo)) return null;
    $x = naval_json_ler(naval_cqr_pasta() . '/codigos.json')[$codigo] ?? null;
    if (!is_array($x) || !empty($x['revogado'])) return null;
    return ['codigo' => $codigo] + $x;
}

/**
 * Códigos dos clientes indicados ({cliente, nome, rota}): reutiliza o código activo de cada um (o cartaz pode voltar a
 * imprimir-se) ou cria um; com $novo, revoga o anterior (cartaz perdido ou copiado) e cria outro.
 */
function naval_cqr_codigos(array $clientes, string $login, bool $novo = false): array
{
    return naval_exclusivo('clientes-qr', function () use ($clientes, $login, $novo) {
        $f = naval_cqr_pasta() . '/codigos.json';
        $todos = naval_json_ler($f);
        $porCliente = [];
        foreach ($todos as $c => $x) if (empty($x['revogado'])) $porCliente[(string) $x['cliente']] = $c;
        $out = [];
        foreach ($clientes as $cl) {
            $id = naval_texto($cl['cliente'] ?? '', 40);
            if ($id === '') continue;
            $c = $porCliente[$id] ?? null;
            if ($c !== null && $novo) {
                $todos[$c]['revogado'] = date('Y-m-d H:i:s');
                $todos[$c]['revogado_por'] = $login;
                $c = null;
            }
            if ($c === null) {
                $c = naval_cqr_codigo_novo();
                $todos[$c] = ['cliente' => $id, 'nome' => naval_texto($cl['nome'] ?? '', 120), 'rota' => naval_texto($cl['rota'] ?? '', 10),
                    'criado' => date('Y-m-d H:i:s'), 'criado_por' => $login, 'revogado' => null];
                $porCliente[$id] = $c;
            }
            $out[] = ['cliente' => $id, 'nome' => $todos[$c]['nome'], 'rota' => $todos[$c]['rota'], 'codigo' => $c, 'url' => naval_cqr_url($c)];
        }
        naval_json_gravar($f, $todos);
        naval_registar('clientes_qr_codigos', ['clientes' => count($out), 'novos' => $novo]);
        return $out;
    });
}

/** Clientes de uma rota (com compras nos últimos 120 dias), por ordem de nome: [{cliente, nome, rota, compras, ultima, qr}]. */
function naval_cqr_clientes_rota(array $agregados, string $rota): array
{
    $c = [];
    foreach ($agregados as $dia => $x) {
        foreach (($x['clientes'] ?? []) as $cod => $cl) {
            if ((string) $cl['r'] !== $rota) continue;
            if (!isset($c[$cod])) $c[$cod] = ['cliente' => (string) $cod, 'nome' => $cl['n'], 'rota' => $rota, 'compras' => 0, 'ultima' => $dia];
            $c[$cod]['compras']++;
            if ($dia > $c[$cod]['ultima']) $c[$cod]['ultima'] = $dia;
            if ($cl['n'] !== '') $c[$cod]['nome'] = $cl['n'];
        }
    }
    $activos = [];
    foreach (naval_json_ler(naval_cqr_pasta() . '/codigos.json') as $cod => $x) if (empty($x['revogado'])) $activos[(string) $x['cliente']] = $x['criado'];
    foreach ($c as $k => $x) $c[$k]['qr'] = $activos[$k] ?? null;
    $c = array_values($c);
    usort($c, function ($a, $b) { return strcoll($a['nome'], $b['nome']); });
    return $c;
}

/** Artigos que se podem encomendar: os vendidos na rota nos últimos 90 dias, por quantidade (até 40). */
function naval_cqr_artigos(array $agregados, string $rota): array
{
    $a = [];
    foreach ($agregados as $x) {
        foreach (($x['artigos'][$rota] ?? []) as $cod => $art) {
            if (!isset($a[$cod])) $a[$cod] = ['codigo' => (string) $cod, 'nome' => $art['n'], 'unidade' => $art['u'], 'q' => 0.0];
            $a[$cod]['q'] += (float) $art['q'];
        }
    }
    usort($a, function ($x, $y) { return $y['q'] <=> $x['q']; });
    return array_map(function ($x) { unset($x['q']); return $x; }, array_slice(array_values($a), 0, 40));
}

/** Valida e limpa o conteúdo de um pedido; lança NavalErro 400 com a mensagem para o cliente. */
function naval_cqr_limpar(string $tipo, array $d, array $artigos): array
{
    $txt = function ($k, int $max) use ($d) { return naval_texto($d[$k] ?? '', $max); };
    $contacto = ['contacto_nome' => $txt('contacto_nome', 80), 'contacto_telefone' => preg_replace('/[^\d+ ]/', '', $txt('contacto_telefone', 20))];
    if ($tipo === 'encomenda') {
        $porCodigo = array_column($artigos, null, 'codigo');
        $linhas = [];
        foreach (array_slice((array) ($d['linhas'] ?? []), 0, 40) as $l) {
            $cod = (string) ($l['codigo'] ?? '');
            $q = (int) ($l['quantidade'] ?? 0);
            if (!isset($porCodigo[$cod]) || $q <= 0) continue;
            $linhas[] = ['codigo' => $cod, 'nome' => $porCodigo[$cod]['nome'], 'unidade' => $porCodigo[$cod]['unidade'], 'quantidade' => min($q, 10000)];
        }
        if (!$linhas) throw new NavalErro('Indique pelo menos um artigo e a quantidade.', 400);
        if ($contacto['contacto_telefone'] === '') throw new NavalErro('Indique um telefone para confirmarmos a encomenda.', 400);
        return ['linhas' => $linhas, 'observacoes' => $txt('observacoes', 400)] + $contacto;
    }
    if ($tipo === 'dados') {
        $out = ['nome_comercial' => $txt('nome_comercial', 120), 'contacto_nome' => $contacto['contacto_nome'], 'contacto_telefone' => $contacto['contacto_telefone'],
            'email' => naval_email_valido($d['email'] ?? null) ? trim((string) $d['email']) : '', 'morada' => $txt('morada', 200), 'horario' => $txt('horario', 80),
            'observacoes' => $txt('observacoes', 400)];
        foreach (['latitude' => [-90, 90], 'longitude' => [-180, 180]] as $k => [$min, $max]) {
            $v = $d[$k] ?? null;
            $out[$k] = is_numeric($v) && (float) $v >= $min && (float) $v <= $max ? round((float) $v, 6) : null;
        }
        if (!array_filter($out, function ($v) { return $v !== '' && $v !== null; })) throw new NavalErro('Preencha pelo menos um campo.', 400);
        return $out;
    }
    if ($tipo === 'consentimento') {
        if (empty($d['aceito'])) throw new NavalErro('Para receber mensagens tem de aceitar as condições.', 400);
        if (strlen((string) preg_replace('/\D/', '', $contacto['contacto_telefone'])) < 9) throw new NavalErro('Indique o número de WhatsApp.', 400);
        return ['aceito' => true, 'texto' => NAVAL_CQR_TEXTO_CONSENTIMENTO] + $contacto;
    }
    throw new NavalErro('Pedido desconhecido.', 400);
}

/** Pedido feito na página pública (pendente de validação). Devolve {id}. */
function naval_cqr_pedir(string $codigo, string $tipo, array $dados, string $ip, string $navegador, array $artigos): array
{
    $cl = naval_cqr_cliente($codigo);
    if (!$cl) throw new NavalErro('Código inválido ou substituído. Peça um cartaz novo ao seu promotor.', 404);
    if (!in_array($tipo, NAVAL_CQR_TIPOS, true)) throw new NavalErro('Pedido desconhecido.', 400);
    $conteudo = naval_cqr_limpar($tipo, $dados, $artigos);
    return naval_exclusivo('clientes-qr-pedidos', function () use ($cl, $tipo, $conteudo, $ip, $navegador) {
        $lim = naval_cqr_pasta() . '/limites-' . date('Y-m-d') . '.json';
        $n = naval_json_ler($lim);
        $kc = 'c|' . $cl['cliente'];
        $ki = 'i|' . hash('sha256', $ip);
        if (($n[$kc] ?? 0) >= NAVAL_CQR_LIMITE_DIA_CLIENTE || ($n[$ki] ?? 0) >= NAVAL_CQR_LIMITE_DIA_IP) {
            throw new NavalErro('Já recebemos vários pedidos hoje. Se for urgente, contacte o seu promotor.', 429);
        }
        $n[$kc] = ($n[$kc] ?? 0) + 1;
        $n[$ki] = ($n[$ki] ?? 0) + 1;
        naval_json_gravar($lim, $n, false);
        foreach (glob(naval_cqr_pasta() . '/limites-*.json') ?: [] as $f) if (basename($f) < 'limites-' . date('Y-m-d', time() - 3 * 86400)) @unlink($f);
        $id = date('ymdHis') . '-' . bin2hex(random_bytes(3));
        $p = ['id' => $id, 'tipo' => $tipo, 'estado' => 'pendente', 'cliente' => $cl['cliente'], 'nome' => $cl['nome'], 'rota' => $cl['rota'],
            'conteudo' => $conteudo, 'criado' => date('Y-m-d H:i:s'), 'ip' => $ip, 'navegador' => mb_substr($navegador, 0, 200), 'codigo' => substr($cl['codigo'], 0, 6) . '…'];
        naval_json_gravar(naval_cqr_pasta('pedidos') . '/' . $id . '.json', $p);
        return ['id' => $id];
    });
}

/** Pedidos: pendentes (todos) e decididos nos últimos $dias dias, no âmbito $rotas (null = todas); mais recentes primeiro. */
function naval_cqr_pedidos(?array $rotas, int $dias = 30): array
{
    $out = [];
    $lim = date('Y-m-d', time() - $dias * 86400);
    foreach (glob(naval_cqr_pasta('pedidos') . '/*.json') ?: [] as $f) {
        $p = naval_json_ler($f);
        if (!$p || ($rotas !== null && !in_array((string) $p['rota'], $rotas, true))) continue;
        if ($p['estado'] !== 'pendente' && substr((string) ($p['decidido'] ?? ''), 0, 10) < $lim) continue;
        $out[] = $p;
    }
    usort($out, function ($a, $b) { return [$a['estado'] !== 'pendente', $b['criado']] <=> [$b['estado'] !== 'pendente', $a['criado']]; });
    return $out;
}

/**
 * Decisão do back-office: aprovar (com correcções ao conteúdo, se as houver) ou recusar (motivo obrigatório).
 * Aprovar uma encomenda avisa o promotor e o supervisor da rota; aprovar dados acrescenta a alteração à lista para o
 * Pinguim BO; aprovar um consentimento regista-o. Devolve o pedido actualizado.
 */
function naval_cqr_decidir(string $id, string $accao, string $motivo, array $correccoes, array $eu): array
{
    if (!preg_match('/^\d{12}-[a-f0-9]{6}$/', $id)) throw new NavalErro('Pedido desconhecido.', 404);
    return naval_exclusivo('clientes-qr-pedidos', function () use ($id, $accao, $motivo, $correccoes, $eu) {
        $f = naval_cqr_pasta('pedidos') . '/' . $id . '.json';
        $p = is_file($f) ? naval_json_ler($f) : [];
        if (!$p) throw new NavalErro('Pedido desconhecido.', 404);
        if ($p['estado'] !== 'pendente') throw new NavalErro('Este pedido já foi decidido por ' . ($p['decidido_por'] ?? '') . '.', 409);
        if (!in_array($accao, ['aprovar', 'recusar'], true)) throw new NavalErro('Acção desconhecida.', 400);
        $motivo = naval_texto($motivo, 300);
        if ($accao === 'recusar' && $motivo === '') throw new NavalErro('Indique o motivo da recusa.', 400);
        if ($accao === 'aprovar' && $correccoes) {
            $p['conteudo_original'] = $p['conteudo'];
            $p['conteudo'] = naval_cqr_limpar($p['tipo'], $correccoes + $p['conteudo'], $p['tipo'] === 'encomenda' ? array_map(function ($l) {
                return ['codigo' => $l['codigo'], 'nome' => $l['nome'], 'unidade' => $l['unidade']];
            }, $p['conteudo']['linhas']) : []);
        }
        $p['estado'] = $accao === 'aprovar' ? 'aprovado' : 'recusado';
        $p['decidido'] = date('Y-m-d H:i:s');
        $p['decidido_por'] = $eu['login'];
        $p['motivo'] = $motivo;
        naval_json_gravar($f, $p);
        if ($accao === 'aprovar') naval_cqr_aplicar($p);
        naval_registar('pedido_cliente_' . $p['estado'], ['id' => $id, 'tipo' => $p['tipo'], 'cliente' => $p['cliente']]);
        return $p;
    });
}

/** Efeitos de um pedido aprovado. */
function naval_cqr_aplicar(array $p): void
{
    if ($p['tipo'] === 'dados') {
        naval_jsonl_acrescentar(naval_cqr_pasta() . '/dados-aprovados.jsonl', ['id' => $p['id'], 'cliente' => $p['cliente'], 'nome' => $p['nome'], 'rota' => $p['rota'],
            'dados' => $p['conteudo'], 'aprovado' => $p['decidido'], 'aprovado_por' => $p['decidido_por'], 'lancado_pinguim' => false]);
        return;
    }
    if ($p['tipo'] === 'consentimento') {
        naval_exclusivo('clientes-qr-consentimentos', function () use ($p) {
            $f = naval_cqr_pasta() . '/consentimentos.json';
            $c = naval_json_ler($f);
            $c[$p['cliente']] = ['whatsapp' => true, 'telefone' => $p['conteudo']['contacto_telefone'], 'nome' => $p['conteudo']['contacto_nome'], 'rota' => $p['rota'],
                'texto' => $p['conteudo']['texto'], 'pedido' => $p['id'], 'data' => $p['criado'], 'validado' => $p['decidido'], 'validado_por' => $p['decidido_por'], 'saida' => null];
            naval_json_gravar($f, $c);
        });
        return;
    }
    // encomenda: aviso ao promotor e ao supervisor da rota (contas activas com essa rota)
    naval_servico('email');
    $para = [];
    foreach (naval_utilizadores() as $login => $u) {
        $u['login'] = $login;
        if (!naval_conta_valida($u) || !naval_email_valido($u['email'] ?? null)) continue;
        if (in_array($p['rota'], array_map('strval', (array) ($u['rotas'] ?? [])), true)) $para[] = ['email' => $u['email'], 'nome' => $u['nome'] ?? $login];
    }
    if (!$para) return;
    $e = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
    $linhas = '';
    foreach ($p['conteudo']['linhas'] as $l) $linhas .= '<tr><td style="padding:4px 8px;border:1px solid #C5D3EA">' . $e($l['nome']) . '</td><td style="padding:4px 8px;border:1px solid #C5D3EA;text-align:right">'
        . (int) $l['quantidade'] . ' ' . $e($l['unidade']) . '</td></tr>';
    $html = '<div style="font-family:Segoe UI,Arial,sans-serif;color:#1C2340;font-size:14px;text-align:justify"><p><b style="color:#1E3A8A">Encomenda validada</b> do cliente '
        . $e($p['nome']) . ' (rota ' . $e($p['rota']) . '), feita pelo código QR em ' . $e(naval_data_pt(substr($p['criado'], 0, 10))) . ' às ' . $e(substr($p['criado'], 11, 5)) . '.</p>'
        . '<table style="border-collapse:collapse">' . $linhas . '</table><p>Contacto: ' . $e($p['conteudo']['contacto_nome']) . ' · ' . $e($p['conteudo']['contacto_telefone'])
        . ($p['conteudo']['observacoes'] !== '' ? '<br>Observações: ' . $e($p['conteudo']['observacoes']) : '') . '</p><p style="color:#4A5568;font-size:12px">Confirme o preço e a entrega com o cliente na próxima visita.</p></div>';
    $r = naval_enviar_email($para, 'Encomenda do cliente ' . $p['nome'] . ' (rota ' . $p['rota'] . ')', $html);
    if (!$r['ok']) error_log('[pedido cliente] aviso por correio: ' . $r['erro']);
}

/**
 * Sugestão de encomenda de um cliente a partir das suas compras (agregados diários, campo «ca»), explicável:
 *   - artigos habituais: comprados em pelo menos 2 dias; quantidade sugerida = mediana das quantidades por compra;
 *     «a repor» quando já passou pelo menos 80 % do intervalo habitual desde a última compra do artigo;
 *   - venda cruzada: os artigos mais vendidos na rota que o cliente nunca comprou (até 5).
 * Devolve {habituais: [{codigo, quantidade, compras, intervalo, repor}], cruzada: [codigo…]} (sem datas nem valores).
 */
function naval_cqr_sugestao(array $agregados, string $cliente, string $rota, string $hoje): array
{
    $hist = [];
    foreach ($agregados as $dia => $x) {
        foreach (($x['ca'][$cliente] ?? []) as $cod => $q) if ($q > 0) $hist[$cod][$dia] = (float) $q;
    }
    $hab = [];
    foreach ($hist as $cod => $dias) {
        if (count($dias) < 2) continue;
        ksort($dias);
        $d = array_keys($dias);
        $int = [];
        for ($i = 1; $i < count($d); $i++) $int[] = (strtotime($d[$i] . ' 12:00') - strtotime($d[$i - 1] . ' 12:00')) / 86400;
        $intervalo = max(1, naval_mediana($int));
        $desde = (strtotime($hoje . ' 12:00') - strtotime(end($d) . ' 12:00')) / 86400;
        $hab[] = ['codigo' => (string) $cod, 'quantidade' => (int) max(1, round(naval_mediana(array_values($dias)))), 'compras' => count($dias),
            'intervalo' => (int) round($intervalo), 'repor' => $desde >= 0.8 * $intervalo];
    }
    usort($hab, function ($a, $b) { return [$b['repor'], $b['compras']] <=> [$a['repor'], $a['compras']]; });
    $cruzada = [];
    foreach (naval_cqr_artigos($agregados, $rota) as $a) {
        if (isset($hist[$a['codigo']])) continue;
        $cruzada[] = $a['codigo'];
        if (count($cruzada) >= 5) break;
    }
    return ['habituais' => $hab, 'cruzada' => $cruzada];
}

/** Compara cada linha da encomenda com o habitual do cliente: {codigo: {habitual, factor, fora}} (fora: 3× acima ou mais, ou artigo novo em grande quantidade). */
function naval_cqr_verificar(array $linhas, array $sugestao): array
{
    $hab = array_column($sugestao['habituais'], 'quantidade', 'codigo');
    $out = [];
    foreach ($linhas as $l) {
        $h = $hab[$l['codigo']] ?? null;
        $f = $h ? round($l['quantidade'] / $h, 1) : null;
        $out[$l['codigo']] = ['habitual' => $h, 'factor' => $f, 'fora' => $h ? $f >= 3 : $l['quantidade'] >= 20];
    }
    return $out;
}
