<?php
/**
 * Página «Acessos» do portal: utilizadores, matriz de acessos e registos de actividade.
 *
 * Leitura (GET): perfis com acesso à página Acessos.
 * Alterações (POST): só o super administrador (SADM), com o código anti-falsificação da sessão e
 * confirmação da identidade (palavra-passe, ou código de dois passos se estiver activo), válida 10 minutos.
 * Cada alteração fica nos registos de actividade.
 */
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/../../geo/auth/auth_check.php';
naval_exigir(['acessos']);

const REFORCO_DURACAO = 600;   // 10 minutos sem voltar a pedir a confirmação

function responder(array $d, int $codigo = 200): void
{
    http_response_code($codigo);
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}

function recusar(string $erro, string $accao = '', array $det = []): void
{
    naval_registar('alteracao_recusada', ['accao' => $accao, 'motivo' => $erro] + $det);
    responder(['ok' => false, 'erro' => $erro], 400);
}

/** Lista de nomes de quem aprovou (duas pessoas, distintas). */
function aprovacoes($v): array
{
    $l = is_array($v) ? $v : explode(',', (string) $v);
    $l = array_values(array_unique(array_filter(array_map(function ($x) { return trim((string) $x); }, $l))));
    return $l;
}

function sadm_activos(array $d): int
{
    $n = 0;
    foreach ($d as $u) if (($u['perfil'] ?? '') === 'SADM' && naval_conta_valida($u)) $n++;
    return $n;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $p = json_decode((string) file_get_contents('php://input'), true) ?: [];
    $accao = (string) ($p['accao'] ?? '');
    $eu = naval_utilizador();

    if (naval_perfil() !== 'SADM') {
        naval_registar('alteracao_recusada', ['accao' => $accao, 'motivo' => 'perfil']);
        responder(['ok' => false, 'erro' => 'Só o super administrador pode alterar contas e acessos.'], 403);
    }
    if (!hash_equals((string) ($_SESSION['csrf'] ?? ''), (string) ($p['csrf'] ?? ''))) {
        responder(['ok' => false, 'erro' => 'Pedido inválido. Actualize a página e tente de novo.'], 403);
    }
    if (!empty($eu['compatibilidade'])) {
        responder(['ok' => false, 'erro' => 'As contas vêm ainda da lista antiga. Crie primeiro a lista nova no servidor (ferramentas/utilizadores.php importar-antigo).'], 400);
    }

    // Confirmação da identidade antes de alterar (excepto desbloquear)
    if ($accao !== 'desbloquear' && (int) ($_SESSION['reforco_ate'] ?? 0) < time()) {
        $metodo = empty($eu['mfa']) ? 'senha' : 'codigo';
        $conf = (string) ($p['confirmacao'] ?? '');
        if ($conf === '') responder(['ok' => false, 'confirmar' => $metodo]);
        $valido = $metodo === 'codigo' ? naval_totp_valido((string) $eu['mfa'], $conf) : password_verify($conf, (string) ($eu['senha'] ?? ''));
        if (!$valido) {
            $_SESSION['reforco_falhas'] = (int) ($_SESSION['reforco_falhas'] ?? 0) + 1;
            naval_registar('confirmacao_falhada', ['accao' => $accao]);
            if ($_SESSION['reforco_falhas'] >= NAVAL_MAX_TENTATIVAS) {
                naval_registar('sessao_terminada', ['motivo' => 'confirmacao']);
                $_SESSION = [];
                session_destroy();
                responder(['ok' => false, 'erro' => 'Confirmação falhada ' . NAVAL_MAX_TENTATIVAS . ' vezes. A sessão foi terminada.', 'sair' => true], 401);
            }
            responder(['ok' => false, 'confirmar' => $metodo, 'erro' => $metodo === 'codigo' ? 'Código inválido.' : 'Palavra-passe incorrecta.']);
        }
        $_SESSION['reforco_ate'] = time() + REFORCO_DURACAO;
        $_SESSION['reforco_falhas'] = 0;
    }

    $d = naval_utilizadores();
    $login = trim((string) ($p['conta'] ?? ''));

    switch ($accao) {
        case 'desbloquear':
            responder(['ok' => naval_desbloquear($login, $eu['login'])]);

        case 'guardar_conta':
            $novo = !empty($p['novo']);
            if ($novo) {
                if (!preg_match('/^[A-Za-z0-9._-]{3,40}$/', $login)) recusar('O utilizador tem de ter 3 a 40 caracteres: letras, números, ponto, hífen ou sublinhado.', $accao);
                if (isset($d[$login])) recusar('Já existe uma conta com este utilizador.', $accao);
                $antes = null;
                $u = ['criado' => date('Y-m-d'), 'criado_por' => $eu['login']];
            } else {
                if (!isset($d[$login])) recusar('Conta não encontrada.', $accao);
                $antes = $d[$login];
                $u = $antes;
            }
            $nome = trim((string) ($p['nome'] ?? ''));
            $email = trim((string) ($p['email'] ?? ''));
            $perfil = strtoupper(trim((string) ($p['perfil'] ?? '')));
            $rotas = array_values(array_unique(array_filter(array_map(function ($x) { return trim((string) $x); }, (array) ($p['rotas'] ?? [])))));
            $ate = trim((string) ($p['valido_ate'] ?? ''));
            $activo = !empty($p['activo']);

            if ($nome === '') recusar('Indique o nome.', $accao);
            if (!naval_email_valido($email)) recusar('O email é obrigatório e tem de ser válido.', $accao);
            foreach ($d as $l => $x) {
                if ($l !== $login && strcasecmp(trim((string) ($x['email'] ?? '')), $email) === 0) recusar('Este email já está associado à conta ' . $l . '.', $accao);
            }
            if (!isset(NAVAL_PERFIS[$perfil])) recusar('Perfil inválido.', $accao);
            if (in_array($perfil, NAVAL_POR_ROTA, true) && !$rotas) recusar('Os perfis SUP e PRO precisam de pelo menos uma rota.', $accao);
            if ($ate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $ate)) recusar('Data de fim inválida.', $accao);

            // A própria conta: não se pode retirar o perfil SADM nem desactivar
            if ($login === $eu['login'] && ($perfil !== 'SADM' || !$activo || ($ate !== '' && $ate < date('Y-m-d')))) {
                recusar('Não pode retirar o seu próprio perfil de super administrador nem desactivar a sua conta.', $accao);
            }
            // Super administrador: criar, dar ou retirar o perfil, ou activar, exige duas aprovações
            $envolveSadm = $perfil === 'SADM' && ($novo || ($antes['perfil'] ?? '') !== 'SADM' || ($activo && empty($antes['activo'])))
                || (!$novo && ($antes['perfil'] ?? '') === 'SADM' && $perfil !== 'SADM');
            $aprov = aprovacoes($p['aprovado_por'] ?? []);
            if ($envolveSadm && count($aprov) < 2) recusar('Alterações a contas de super administrador exigem duas aprovações (nomes de duas pessoas da Administração).', $accao);

            $u['nome'] = $nome;
            $u['email'] = $email;
            $u['perfil'] = $perfil;
            $u['rotas'] = in_array($perfil, NAVAL_POR_ROTA, true) ? $rotas : [];
            $u['activo'] = $activo;
            if ($ate === '') unset($u['valido_ate']); else $u['valido_ate'] = $ate;
            $senha = null;
            if ($novo) {
                $senha = naval_senha_temporaria();
                $u['senha'] = password_hash($senha, PASSWORD_DEFAULT);
                $u['trocar_senha'] = true;
            }
            $u['alterado'] = date('Y-m-d H:i');
            $u['alterado_por'] = $eu['login'];
            $nd = $d;
            $nd[$login] = $u;
            if (sadm_activos($nd) < 1) recusar('Tem de ficar pelo menos um super administrador activo.', $accao);
            naval_gravar_utilizadores($nd);

            // Registo: o que mudou
            $mud = [];
            foreach (['nome', 'email', 'perfil', 'rotas', 'activo', 'valido_ate'] as $k) {
                $a = $antes[$k] ?? null; $b = $u[$k] ?? null;
                if ($a != $b) $mud[$k] = is_array($b) ? implode(', ', $b) : (is_bool($b) ? ($b ? 'sim' : 'não') : (string) $b);
            }
            naval_registar($novo ? 'conta_criada' : 'conta_alterada', ['conta' => $login] + $mud + ($envolveSadm ? ['aprovado_por' => $aprov] : []));
            responder(['ok' => true, 'senha' => $senha, 'dois_passos_obrigatorio' => in_array($perfil, NAVAL_MFA, true) && empty($u['mfa'])]);

        case 'senha':
            if (!isset($d[$login])) recusar('Conta não encontrada.', $accao);
            $senha = naval_senha_temporaria();
            $d[$login]['senha'] = password_hash($senha, PASSWORD_DEFAULT);
            $d[$login]['trocar_senha'] = true;
            naval_gravar_utilizadores($d);
            naval_registar('senha_reposta', ['conta' => $login]);
            responder(['ok' => true, 'senha' => $senha]);

        case 'dois_passos':
            if (!isset($d[$login])) recusar('Conta não encontrada.', $accao);
            if (!empty($p['activar'])) {
                $seg = naval_novo_segredo();
                $d[$login]['mfa'] = $seg;
                naval_gravar_utilizadores($d);
                naval_registar('dois_passos_configurado', ['conta' => $login]);
                responder(['ok' => true, 'segredo' => $seg, 'uri' => naval_otpauth($seg, (string) ($d[$login]['email'] ?? '') ?: $login)]);
            }
            if ($login === $eu['login']) recusar('Não pode retirar a verificação em dois passos da sua própria conta.', $accao);
            unset($d[$login]['mfa']);
            naval_gravar_utilizadores($d);
            naval_registar('dois_passos_retirado', ['conta' => $login]);
            responder(['ok' => true]);

        case 'matriz':
            $antes = naval_matriz();
            $nova = is_array($p['matriz'] ?? null) ? $p['matriz'] : [];
            $final = $antes;
            $mud = [];
            foreach ($nova as $rec => $col) {
                if (!isset($antes[$rec]) || !is_array($col)) continue;
                foreach ($col as $perf => $nivel) {
                    $nivel = (string) $nivel;
                    if (!in_array($perf, NAVAL_COLUNAS, true)) continue;
                    if (!in_array($nivel, naval_niveis_recurso($rec), true)) recusar('Nível não permitido em ' . $rec . ' / ' . $perf . '.', $accao);
                    if ($antes[$rec][$perf] !== $nivel) {
                        $final[$rec][$perf] = $nivel;
                        $mud[] = $rec . ' · ' . $perf . ': ' . ($antes[$rec][$perf] ?: '—') . ' → ' . ($nivel ?: '—');
                    }
                }
            }
            if (!$mud) responder(['ok' => true, 'alteracoes' => 0]);
            naval_gravar_matriz($final);
            naval_registar('matriz_alterada', ['alteracoes' => $mud]);
            responder(['ok' => true, 'alteracoes' => count($mud)]);

        case 'repor_matriz':
            naval_gravar_matriz(naval_matriz_base());
            naval_registar('matriz_reposta', []);
            responder(['ok' => true]);
    }
    responder(['ok' => false, 'erro' => 'Acção desconhecida.'], 400);
}

// ---------- Leitura ----------
$bloq = naval_contas_bloqueadas();
$ultimas = [];
foreach (naval_ler_registos(2000) as $r) {
    if (($r['accao'] ?? '') === 'entrada' && !isset($ultimas[$r['utilizador']])) $ultimas[$r['utilizador']] = $r['data'];
}
$us = [];
foreach (naval_utilizadores() as $login => $u) {
    $us[] = [
        'login' => $login, 'nome' => $u['nome'] ?? $login, 'email' => $u['email'] ?? '', 'perfil' => $u['perfil'] ?? '',
        'email_ok' => naval_email_valido($u['email'] ?? null), 'bloqueado' => isset($bloq[$login]), 'bloqueado_em' => $bloq[$login] ?? null,
        'perfil_nome' => NAVAL_PERFIS[$u['perfil'] ?? ''] ?? ($u['perfil'] ?? ''),
        'rotas' => in_array($u['perfil'] ?? '', NAVAL_POR_ROTA, true) ? array_values($u['rotas'] ?? []) : null,
        'activo' => naval_conta_valida($u), 'ligada' => !empty($u['activo']), 'valido_ate' => $u['valido_ate'] ?? null,
        'dois_passos' => !empty($u['mfa']), 'compatibilidade' => !empty($u['compatibilidade']),
        'senha_temporaria' => !empty($u['trocar_senha']),
        'ultima_entrada' => $ultimas[$login] ?? null,
    ];
}
$niveis = [];
foreach (array_keys(naval_matriz()) as $rec) $niveis[$rec] = naval_niveis_recurso($rec);
$rotas = json_decode((string) @file_get_contents(__DIR__ . '/rotas.json'), true);
$gerir = naval_perfil() === 'SADM' && empty(naval_utilizador()['compatibilidade']);
echo json_encode([
    'perfis' => NAVAL_PERFIS,
    'matriz' => naval_matriz(),
    'matriz_base' => naval_matriz_base(),
    'niveis' => $niveis,
    'por_rota' => NAVAL_POR_ROTA,
    'perfis_dois_passos' => NAVAL_MFA,
    'rotas' => $gerir && is_array($rotas) ? array_map(function ($r) { return ['rota' => (string) $r['rota'], 'promotor' => $r['promotor'] ?? '']; }, $rotas) : [],
    'utilizadores' => $us,
    'registos' => naval_pode('registos') ? naval_ler_registos(300) : [],
    'pode_desbloquear' => naval_perfil() === 'SADM',
    'pode_gerir' => $gerir,
    'eu' => naval_utilizador()['login'] ?? '',
    'confirmado' => (int) ($_SESSION['reforco_ate'] ?? 0) >= time(),
], JSON_UNESCAPED_UNICODE);
