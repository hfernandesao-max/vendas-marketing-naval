<?php
/**
 * Vigilância em produção (práticas de SRE da Google: detectar antes de alguém reclamar, desfazer depressa, corrigir
 * com um teste que prova a falha). Corre nas tarefas periódicas (de 5 em 5 minutos, trabalho de 15 em 15).
 *
 *   1. Falhas do código: excepções e erros fatais do PHP (nucleo/http.php → registos/falhas-AAAA-MM.jsonl) e respostas
 *      5xx da API (registos/desempenho-AAAA-MM.jsonl), agrupadas por assinatura (tipo, ficheiro, linha e mensagem sem
 *      números) — sem dados pessoais: e-mails, NIF, telefones e números longos saem da mensagem.
 *   2. Verificações de negócio (de hora a hora): o Pinguim BO responde; há documentos de venda no Pinguim BO e o
 *      portal não os mostra (foi assim em 07-11-2026, com a regra do 8002) — o pior defeito é o que não dá erro.
 *   3. Cada problema novo vai para o GitHub como «issue» (etiqueta «vigilancia»), onde a correcção automática da
 *      madrugada o lê, e por e-mail aos administradores (ACTUALIZACAO_EMAIL). Sem GitHub, fica no portal e no e-mail.
 *   4. Reposição automática: na primeira hora depois de uma actualização, se as falhas dispararem (pelo menos
 *      VIGIA_REVERTER_MINIMO e 3 vezes a média antes) ou as vendas deixarem de aparecer, o portal volta sozinho à
 *      versão anterior (uma vez por versão).
 */
defined('NAVAL_NUCLEO') || exit;

const NAVAL_VIGIA_INTERVALO = 900;      // 15 minutos entre ciclos
const NAVAL_VIGIA_NEGOCIO = 3600;       // verificações de negócio de hora a hora
const NAVAL_VIGIA_JANELA_REVERTER = 3600;

function naval_vigia_pasta(): string
{
    return naval_pasta('vigia');
}

function naval_vigia_estado(): array
{
    return naval_json_ler(naval_vigia_pasta() . '/estado.json', ['cursor' => [], 'ultimo' => 0, 'negocio_em' => 0, 'negocio' => [], 'revertidas' => []]);
}

function naval_vigia_problemas(): array
{
    return naval_json_ler(naval_vigia_pasta() . '/problemas.json');
}

/** Mensagem sem dados pessoais nem valores (para agrupar e para enviar ao GitHub). */
function naval_vigia_limpar(string $m): string
{
    $m = (string) preg_replace('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', '<email>', $m);
    $m = (string) preg_replace('/\b\d{5,}\b/', '<n>', $m);
    $m = (string) preg_replace("/'[^']{0,200}'/", "'…'", $m);
    return mb_substr(trim((string) preg_replace('/\s+/', ' ', $m)), 0, 300);
}

/** Regista uma falha do código (chamado pelos tratadores de erros do PHP). Nunca lança excepções. */
function naval_vigia_registar_falha(string $origem, string $tipo, string $mensagem, string $ficheiro, int $linha): void
{
    try {
        $n = function (string $c): string { return str_replace('\\', '/', (string) (realpath($c) ?: $c)); };
        $raiz = $n(dirname(__DIR__, 2)) . '/';
        $f = $n($ficheiro);
        $rel = stripos($f, $raiz) === 0 ? substr($f, strlen($raiz)) : basename($f);
        naval_jsonl_acrescentar(naval_pasta_privada() . '/registos/falhas-' . date('Y-m') . '.jsonl', [
            'data' => date('Y-m-d H:i:s'), 'origem' => $origem, 'tipo' => $tipo, 'ficheiro' => $rel, 'linha' => $linha,
            'mensagem' => naval_vigia_limpar($mensagem), 'rota' => (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH),
        ]);
    } catch (Throwable $e) {
        // a vigilância nunca pode estragar a resposta
    }
}

function naval_vigia_assinatura(array $f): string
{
    $m = (string) preg_replace('/\d+/', '#', (string) ($f['mensagem'] ?? ''));
    // nosemgrep: weak-crypto -- resumo não criptográfico (identificador/versão), sem uso de segurança
    return substr(sha1(($f['tipo'] ?? '') . '|' . ($f['ficheiro'] ?? '') . '|' . ($f['linha'] ?? '') . '|' . $m), 0, 16);
}

/** Linhas novas de um ficheiro .jsonl desde o cursor (posição em bytes), com o novo cursor. */
function naval_vigia_ler_novas(string $f, int $desde): array
{
    if (!is_file($f)) return [[], 0];
    $tam = (int) filesize($f);
    if ($desde > $tam) $desde = 0;
    $h = fopen($f, 'r');
    if (!$h) return [[], $desde];
    fseek($h, $desde);
    $out = [];
    while (($l = fgets($h)) !== false) {
        $j = json_decode($l, true);
        if (is_array($j)) $out[] = $j;
    }
    $pos = (int) ftell($h);
    fclose($h);
    return [$out, $pos];
}

/** Verificações de negócio: Pinguim BO a responder e vendas visíveis quando o Pinguim BO tem documentos. */
function naval_vigia_negocio(int $agora): array
{
    $r = [];
    if (!function_exists('naval_bd_configurada')) naval_servico('bd');
    if (!naval_bd_configurada()) return $r;
    naval_servico('vendas');
    try {
        naval_bd_estado();
        $r['pinguim'] = ['ok' => true];
    } catch (Throwable $e) {
        return ['pinguim' => ['ok' => false, 'mensagem' => 'O Pinguim BO não responde: ' . naval_vigia_limpar($e->getMessage())]];
    }
    // ontem (dia fechado) e hoje depois das 12:00: documentos de venda no Pinguim BO mas nenhum no portal
    foreach ([date('Y-m-d', $agora - 86400)] + (date('H', $agora) >= '12' ? [1 => date('Y-m-d', $agora)] : []) as $dia) {
        try {
            // as mesmas condições das vendas do portal (naval_vendas_sql), com e sem a regra dos eliminados e anulados
            $p = [':de' => $dia, ':ate' => $dia];
            $sinal = '((DH.CALC_TOTAL_W_TAX * DH.CALC_SALES_SIGNAL) > 0 OR (DH.CALC_SALES_SIGNAL < 0 AND DH.CALC_TOTAL_W_TAX <> 0)) AND DH.DOC_DATE >= :de AND DH.DOC_DATE <= :ate';
            $c = naval_bd_consultar('SELECT SUM(CASE WHEN DH.DELETED = 0 THEN 1 ELSE 0 END) AS BRUTO, SUM(CASE WHEN ' . naval_vendas_condicao_validos() . ' THEN 1 ELSE 0 END) AS VALIDOS'
                . ' FROM DOCHEADER DH INNER JOIN DOCCONFIG DC ON DC.ID = DH.DOCCONFIG_ID WHERE ' . $sinal, $p);
            $bruto = (int) ($c[0]['BRUTO'] ?? 0);
            $validos = (int) ($c[0]['VALIDOS'] ?? 0);
            if ($bruto === 0) continue;
            $r['vendas_' . $dia] = $validos === 0
                ? ['ok' => false, 'mensagem' => 'O Pinguim BO tem ' . $bruto . ' documentos de venda em ' . $dia . ' e o portal não mostra nenhum (regra dos anulados ou filtro das vendas).']
                : ['ok' => true, 'documentos' => $validos];
        } catch (Throwable $e) {
            $r['vendas_' . $dia] = ['ok' => false, 'mensagem' => 'A verificação das vendas de ' . $dia . ' falhou: ' . naval_vigia_limpar($e->getMessage())];
        }
    }
    return $r;
}

/** Cria a «issue» no GitHub (ou acrescenta um comentário). Devolve o número, ou null; nunca lança excepções. */
function naval_vigia_github(array $p, ?int $issue, string &$erro): ?int
{
    $erro = '';
    if (naval_config('VIGIA_GITHUB', '1') === '0') return null;
    try {
        naval_servico('actualizacao');
        $base = rtrim(naval_config('ACTUALIZACAO_GITHUB_API', 'https://api.github.com'), '/') . '/repos/' . naval_config('ACTUALIZACAO_REPOSITORIO', NAVAL_ACT_REPOSITORIO);
        $estado = naval_act_estado();
        $corpo = implode("\n", [
            '**Detectado pela vigilância do portal em produção.** Sem dados pessoais.',
            '',
            '| Campo | Valor |', '| --- | --- |',
            '| Tipo | `' . $p['tipo'] . '` |',
            '| Onde | `' . ($p['ficheiro'] !== '' ? $p['ficheiro'] . ':' . $p['linha'] : $p['rota']) . '` |',
            '| Mensagem | ' . str_replace('|', '\\|', $p['mensagem']) . ' |',
            '| Ocorrências | ' . $p['contagem'] . ' (desde ' . $p['primeiro'] . ', última ' . $p['ultimo'] . ') |',
            '| Versão instalada | `' . ($estado['versao'] ?: '?') . '` (`' . substr((string) $estado['commit'], 0, 7) . '`) |',
            '| Assinatura | `' . $p['id'] . '` |',
            '',
            'Correcção esperada: reproduzir com um teste que falha, corrigir e enviar pelo processo de sempre (verificações, ensaio, produção).',
        ]);
        $url = $issue ? $base . '/issues/' . $issue . '/comments' : $base . '/issues';
        $dados = $issue ? ['body' => 'Continua: ' . $p['contagem'] . ' ocorrências, a última em ' . $p['ultimo'] . '.'] : ['title' => '[vigilância] ' . $p['tipo'] . ': ' . mb_substr($p['mensagem'], 0, 120), 'body' => $corpo, 'labels' => ['vigilancia']];
        $ch = curl_init($url);
        $op = [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => (string) json_encode($dados, JSON_UNESCAPED_UNICODE), CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => ['Accept: application/vnd.github+json', 'Content-Type: application/json', 'User-Agent: Portal-RTM-Vigilancia',
                'X-GitHub-Api-Version: 2022-11-28', 'Authorization: Bearer ' . naval_act_credencial()],
        ];
        if (DIRECTORY_SEPARATOR === '\\' && (string) ini_get('curl.cainfo') === '' && defined('CURLSSLOPT_NATIVE_CA')) $op[CURLOPT_SSL_OPTIONS] = CURLSSLOPT_NATIVE_CA;
        curl_setopt_array($ch, $op);
        $res = (string) curl_exec($ch);
        $codigo = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($codigo === 201) return $issue ?: (int) ((json_decode($res, true) ?: [])['number'] ?? 0) ?: null;
        $erro = $codigo === 403 || $codigo === 404
            ? 'A GitHub App do portal não tem permissão para criar «issues» (dar «Issues: Read and write» à App no GitHub).'
            : 'O GitHub respondeu HTTP ' . $codigo . '.';
    } catch (Throwable $e) {
        $erro = naval_vigia_limpar($e->getMessage());
    }
    return null;
}

function naval_vigia_avisar(array $novos): void
{
    if (!$novos) return;
    $linhas = ['A vigilância do portal encontrou ' . count($novos) . ' problema(s) novo(s) em produção:'];
    foreach ($novos as $p) $linhas[] = '• ' . $p['tipo'] . ' — ' . $p['mensagem'] . ($p['issue'] ? ' (GitHub #' . $p['issue'] . ')' : '');
    $linhas[] = 'A correcção automática da madrugada trata dos que estiverem no GitHub; o estado está em Administração › Conformidade.';
    naval_servico('actualizacao');
    naval_act_avisar('vigilância: ' . count($novos) . ' problema(s) novo(s)', $linhas);
}

/**
 * Ciclo da vigilância. $o: forcar (ignora o intervalo), negocio (resultado já calculado, para os testes),
 * sem_github, sem_email. Devolve o resumo, ou null se ainda não era altura.
 */
function naval_vigia_ciclo(int $agora, array $o = []): ?array
{
    if (naval_config('VIGIA_ACTIVA', '1') === '0') return null;
    return naval_exclusivo('vigia', function () use ($agora, $o) {
        $e = naval_vigia_estado();
        if (empty($o['forcar']) && $agora - (int) $e['ultimo'] < NAVAL_VIGIA_INTERVALO) return null;
        $mes = date('Y-m', $agora);
        $ocorr = [];
        $rotasComFalha = [];
        // 1. falhas do código e respostas 5xx desde a última vez
        foreach (['falhas', 'desempenho'] as $tipoFich) {
            $f = naval_pasta_privada() . '/registos/' . $tipoFich . '-' . $mes . '.jsonl';
            $chave = $tipoFich . '-' . $mes;
            [$linhas, $pos] = naval_vigia_ler_novas($f, (int) ($e['cursor'][$chave] ?? 0));
            $e['cursor'] = array_filter($e['cursor'], function ($k) use ($mes) { return substr($k, -7) === $mes; }, ARRAY_FILTER_USE_KEY);
            $e['cursor'][$chave] = $pos;
            foreach ($linhas as $l) {
                if ($tipoFich === 'falhas') $rotasComFalha[basename((string) ($l['rota'] ?? ''))] = true;
                if ($tipoFich === 'desempenho') {
                    // 5xx já explicado por uma falha do código na mesma rota não conta duas vezes; 503 é «ocupado, tente de novo»
                    if ((int) ($l['estado'] ?? 0) < 500 || (int) $l['estado'] === 503 || isset($rotasComFalha[basename((string) ($l['rota'] ?? ''))])) continue;
                    $l = ['tipo' => 'http_' . (int) $l['estado'], 'ficheiro' => '', 'linha' => 0, 'mensagem' => 'Resposta ' . (int) $l['estado'] . ' em ' . ($l['rota'] ?? '?'), 'rota' => (string) ($l['rota'] ?? ''), 'data' => (string) ($l['data'] ?? '')];
                }
                $ocorr[] = $l + ['origem' => $tipoFich];
            }
        }
        // 2. negócio, de hora a hora
        $negocio = null;
        if (array_key_exists('negocio', $o) || $agora - (int) $e['negocio_em'] >= NAVAL_VIGIA_NEGOCIO) {
            $negocio = $o['negocio'] ?? naval_vigia_negocio($agora);
            $e['negocio_em'] = $agora;
            foreach ($negocio as $k => $r) {
                if (empty($r['ok'])) $ocorr[] = ['tipo' => 'negocio_' . preg_replace('/_\d{4}-\d{2}-\d{2}$/', '', $k), 'ficheiro' => '', 'linha' => 0, 'mensagem' => (string) $r['mensagem'], 'rota' => '', 'data' => date('Y-m-d H:i:s', $agora)];
            }
            $e['negocio'] = $negocio;
        }
        // 3. agrupar, guardar, enviar
        $probs = naval_vigia_problemas();
        $novos = [];
        $mudados = [];
        foreach ($ocorr as $l) {
            $id = naval_vigia_assinatura($l);
            $quando = (string) ($l['data'] ?? date('Y-m-d H:i:s', $agora));
            if (!isset($probs[$id])) {
                $probs[$id] = ['id' => $id, 'tipo' => (string) $l['tipo'], 'ficheiro' => (string) ($l['ficheiro'] ?? ''), 'linha' => (int) ($l['linha'] ?? 0),
                    'mensagem' => naval_vigia_limpar((string) ($l['mensagem'] ?? '')), 'rota' => (string) ($l['rota'] ?? ''), 'primeiro' => $quando,
                    'ultimo' => $quando, 'contagem' => 0, 'estado' => 'aberto', 'issue' => null, 'github_erro' => '', 'avisado_em' => 0];
                $novos[$id] = true;
            }
            $probs[$id]['contagem']++;
            $probs[$id]['ultimo'] = $quando;
            if ($probs[$id]['estado'] === 'resolvido') {
                $probs[$id]['estado'] = 'aberto';   // voltou depois de dado como resolvido
                $novos[$id] = true;
            }
            $mudados[$id] = true;
        }
        // resolvido sozinho: 7 dias sem voltar a acontecer
        foreach ($probs as $id => $p) {
            if ($p['estado'] === 'aberto' && !isset($mudados[$id]) && strtotime($p['ultimo']) < $agora - 7 * 86400) $probs[$id]['estado'] = 'resolvido';
        }
        $avisar = [];
        foreach (array_keys($mudados) as $id) {
            $p = &$probs[$id];
            $comentar = !isset($novos[$id]) && $p['issue'] && $agora - (int) $p['avisado_em'] >= 6 * 3600;
            if (empty($o['sem_github']) && (isset($novos[$id]) && !$p['issue'] || $comentar)) {
                $erro = '';
                $n = naval_vigia_github($p, $p['issue'] ?: null, $erro);
                if ($n) $p['issue'] = $n;
                $p['github_erro'] = $erro;
                $p['avisado_em'] = $agora;
            }
            if (isset($novos[$id])) $avisar[] = $p;
            unset($p);
        }
        naval_json_gravar(naval_vigia_pasta() . '/problemas.json', $probs);
        // 4. reposição automática na primeira hora depois de uma actualização
        $revertida = naval_vigia_reverter_se_preciso($agora, $e, $ocorr, $negocio);
        $e['ultimo'] = $agora;
        naval_json_gravar(naval_vigia_pasta() . '/estado.json', $e);
        if (empty($o['sem_email'])) naval_vigia_avisar($avisar);
        if ($novos) naval_registar('vigia_problemas', ['novos' => array_keys($novos)], 'vigilancia');
        return ['ocorrencias' => count($ocorr), 'novos' => array_keys($novos), 'revertida' => $revertida];
    });
}

/** Pede a reposição da versão anterior quando a versão acabada de instalar falha (uma vez por versão). */
function naval_vigia_reverter_se_preciso(int $agora, array &$e, array $ocorr, ?array $negocio): ?string
{
    naval_servico('actualizacao');
    $est = naval_act_estado();
    $inst = strtotime((string) ($est['aplicada_em'] ?? '')) ?: 0;
    $versao = (string) ($est['versao'] ?? '');
    $dentro = $versao !== '' && $inst && $agora - $inst <= NAVAL_VIGIA_JANELA_REVERTER;
    if (!$dentro) {
        if (is_array($negocio)) $e['negocio_antes'] = $negocio;   // referência para a próxima instalação
        return null;
    }
    if (in_array($versao, (array) $e['revertidas'], true)) return null;
    $motivo = '';
    // falhas do código desde a instalação, comparadas com a média por hora das 24 horas antes
    $depois = 0;
    $antes = 0;
    foreach (array_unique([date('Y-m', $inst), date('Y-m', $inst - 86400)]) as $m) {
        $f = naval_pasta_privada() . '/registos/falhas-' . $m . '.jsonl';
        if (!is_file($f)) continue;
        foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $l) {
            $t = strtotime((string) ((json_decode($l, true) ?: [])['data'] ?? ''));
            if (!$t) continue;
            if ($t >= $inst) $depois++;
            elseif ($t >= $inst - 86400) $antes++;
        }
    }
    $minimo = max(5, (int) naval_config('VIGIA_REVERTER_MINIMO', '10'));
    $horas = max(1 / 60, ($agora - $inst) / 3600);
    if ($depois >= $minimo && $depois / $horas > 3 * max(1, $antes / 24)) $motivo = $depois . ' falhas do código desde a instalação (antes: ' . $antes . ' em 24 horas)';
    // vendas desaparecidas depois de instalar, quando antes da instalação apareciam
    if ($motivo === '' && is_array($negocio)) {
        $antesOk = false;
        foreach ((array) ($e['negocio_antes'] ?? []) as $k => $r) if (strpos($k, 'vendas_') === 0 && !empty($r['ok'])) $antesOk = true;
        foreach ($negocio as $k => $r) {
            if (strpos($k, 'vendas_') === 0 && empty($r['ok']) && $antesOk && strpos((string) $r['mensagem'], 'não mostra') !== false) $motivo = (string) $r['mensagem'];
        }
    }
    if ($motivo === '') return null;
    try {
        naval_act_pedir_reversao('vigilancia');
    } catch (Throwable $ex) {
        return null;   // não há cópia para repor
    }
    $e['revertidas'][] = $versao;
    $e['revertidas'] = array_slice($e['revertidas'], -20);
    naval_act_registar('reversao_automatica', ['versao' => $versao, 'motivo' => $motivo]);
    naval_act_avisar('a versão ' . $versao . ' foi desfeita sozinha', ['A vigilância pediu a reposição da versão anterior: ' . $motivo . '.', 'A reposição corre nos próximos 5 minutos; a versão fica por instalar até haver correcção.']);
    return $versao;
}

/** O que a página Conformidade mostra da vigilância. */
function naval_vigia_vista(): array
{
    $e = naval_vigia_estado();
    $p = array_values(naval_vigia_problemas());
    usort($p, function ($a, $b) { return strcmp($b['ultimo'], $a['ultimo']); });
    $erroGithub = '';
    foreach ($p as $x) if ($x['estado'] === 'aberto' && $x['github_erro'] !== '') $erroGithub = $x['github_erro'];
    return [
        'activa' => naval_config('VIGIA_ACTIVA', '1') !== '0',
        'ultimo' => $e['ultimo'] ? date('Y-m-d H:i:s', (int) $e['ultimo']) : null,
        'negocio' => $e['negocio'],
        'abertos' => count(array_filter($p, function ($x) { return $x['estado'] === 'aberto'; })),
        'problemas' => array_slice($p, 0, 100),
        'github_erro' => $erroGithub,
        'repositorio' => naval_config('ACTUALIZACAO_REPOSITORIO', defined('NAVAL_ACT_REPOSITORIO') ? NAVAL_ACT_REPOSITORIO : ''),
    ];
}
