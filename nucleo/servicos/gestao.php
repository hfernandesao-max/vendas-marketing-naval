<?php
/**
 * Gestão de contas e da matriz de acessos (página «Acessos» do portal).
 *
 * Só o super administrador (SADM) altera, com confirmação da identidade (palavra-passe, ou código
 * de dois passos se estiver activo) válida 10 minutos. Cada alteração fica nos registos.
 */
defined('NAVAL_NUCLEO') || exit;
require_once __DIR__ . '/rotas.php';

const NAVAL_REFORCO_DURACAO = 600;   // 10 minutos sem voltar a pedir a confirmação

/** Recusa uma alteração: regista o motivo e lança NavalErro (400). */
function naval_gestao_recusar(string $erro, string $accao, array $det = []): void
{
    naval_registar('alteracao_recusada', ['accao' => $accao, 'motivo' => $erro] + $det);
    throw new NavalErro($erro, 400);
}

/** Quem pode alterar: SADM com contas já na lista nova. Lança NavalErro se não puder. */
function naval_gestao_autorizar(array $eu, string $accao): void
{
    if (($eu['perfil'] ?? '') !== 'SADM') {
        naval_registar('alteracao_recusada', ['accao' => $accao, 'motivo' => 'perfil']);
        throw new NavalErro('Só o super administrador pode alterar contas e acessos.', 403);
    }
    if (!empty($eu['compatibilidade'])) {
        throw new NavalErro('As contas vêm ainda da lista antiga. Crie primeiro a lista nova no servidor (ferramentas/utilizadores.php importar-antigo).');
    }
}

/**
 * Confirmação da identidade antes de alterar (excepto desbloquear). Devolve null quando está
 * confirmada; caso contrário, a resposta a enviar ao portal ({confirmar: senha|codigo}).
 */
function naval_gestao_confirmar(array $eu, string $accao, string $conf): ?array
{
    if ($accao === 'desbloquear' || (int) ($_SESSION['reforco_ate'] ?? 0) >= time()) return null;
    $metodo = empty($eu['mfa']) ? 'senha' : 'codigo';
    if ($conf === '') return ['ok' => false, 'confirmar' => $metodo];
    $valido = $metodo === 'codigo' ? naval_totp_valido((string) $eu['mfa'], $conf) : password_verify($conf, (string) ($eu['senha'] ?? ''));
    if (!$valido) {
        $_SESSION['reforco_falhas'] = (int) ($_SESSION['reforco_falhas'] ?? 0) + 1;
        naval_registar('confirmacao_falhada', ['accao' => $accao]);
        if ($_SESSION['reforco_falhas'] >= NAVAL_MAX_TENTATIVAS) {
            naval_registar('sessao_terminada', ['motivo' => 'confirmacao']);
            $_SESSION = [];
            session_destroy();
            throw new NavalErro('Confirmação falhada ' . NAVAL_MAX_TENTATIVAS . ' vezes. A sessão foi terminada.', 401, ['sair' => true]);
        }
        return ['ok' => false, 'confirmar' => $metodo, 'erro' => $metodo === 'codigo' ? 'Código inválido.' : 'Palavra-passe incorrecta.'];
    }
    $_SESSION['reforco_ate'] = time() + NAVAL_REFORCO_DURACAO;
    $_SESSION['reforco_falhas'] = 0;
    return null;
}

/** Nomes de quem aprovou (sem repetidos nem vazios). */
function naval_gestao_aprovacoes($v): array
{
    $l = is_array($v) ? $v : explode(',', (string) $v);
    return array_values(array_unique(array_filter(array_map(function ($x) { return trim((string) $x); }, $l))));
}

function naval_gestao_sadm_activos(array $d): int
{
    $n = 0;
    foreach ($d as $u) if (($u['perfil'] ?? '') === 'SADM' && naval_conta_valida($u)) $n++;
    return $n;
}

/** Cria ou altera uma conta. Devolve {senha (temporária, só ao criar), dois_passos_obrigatorio}. */
function naval_gestao_guardar_conta(array $p, array $eu): array
{
    $accao = 'guardar_conta';
    $d = naval_utilizadores();
    $login = trim((string) ($p['conta'] ?? ''));
    $novo = !empty($p['novo']);
    if ($novo) {
        if (!preg_match('/^[A-Za-z0-9._-]{3,40}$/', $login)) naval_gestao_recusar('O utilizador tem de ter 3 a 40 caracteres: letras, números, ponto, hífen ou sublinhado.', $accao);
        if (isset($d[$login])) naval_gestao_recusar('Já existe uma conta com este utilizador.', $accao);
        $antes = null;
        $u = ['criado' => date('Y-m-d'), 'criado_por' => $eu['login']];
    } else {
        if (!isset($d[$login])) naval_gestao_recusar('Conta não encontrada.', $accao);
        $antes = $d[$login];
        $u = $antes;
    }
    $nome = trim((string) ($p['nome'] ?? ''));
    $email = trim((string) ($p['email'] ?? ''));
    $perfil = strtoupper(trim((string) ($p['perfil'] ?? '')));
    $rotas = array_values(array_unique(array_filter(array_map(function ($x) { return trim((string) $x); }, (array) ($p['rotas'] ?? [])))));
    $ate = trim((string) ($p['valido_ate'] ?? ''));
    $activo = !empty($p['activo']);

    if ($nome === '') naval_gestao_recusar('Indique o nome.', $accao);
    if (!naval_email_valido($email)) naval_gestao_recusar('O email é obrigatório e tem de ser válido.', $accao);
    foreach ($d as $l => $x) {
        if ($l !== $login && strcasecmp(trim((string) ($x['email'] ?? '')), $email) === 0) naval_gestao_recusar('Este email já está associado à conta ' . $l . '.', $accao);
    }
    if (!isset(NAVAL_PERFIS[$perfil])) naval_gestao_recusar('Perfil inválido.', $accao);
    if (in_array($perfil, NAVAL_POR_ROTA, true) && !$rotas) naval_gestao_recusar('Os perfis SUP e PRO precisam de pelo menos uma rota.', $accao);
    if ($ate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $ate)) naval_gestao_recusar('Data de fim inválida.', $accao);

    // A própria conta: não se pode retirar o perfil SADM nem desactivar
    if ($login === $eu['login'] && ($perfil !== 'SADM' || !$activo || ($ate !== '' && $ate < date('Y-m-d')))) {
        naval_gestao_recusar('Não pode retirar o seu próprio perfil de super administrador nem desactivar a sua conta.', $accao);
    }
    // Super administrador: criar, dar ou retirar o perfil, ou activar, exige duas aprovações
    $envolveSadm = $perfil === 'SADM' && ($novo || ($antes['perfil'] ?? '') !== 'SADM' || ($activo && empty($antes['activo'])))
        || (!$novo && ($antes['perfil'] ?? '') === 'SADM' && $perfil !== 'SADM');
    $aprov = naval_gestao_aprovacoes($p['aprovado_por'] ?? []);
    if ($envolveSadm && count($aprov) < 2) naval_gestao_recusar('Alterações a contas de super administrador exigem duas aprovações (nomes de duas pessoas da Administração).', $accao);

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
    if (naval_gestao_sadm_activos($nd) < 1) naval_gestao_recusar('Tem de ficar pelo menos um super administrador activo.', $accao);
    naval_gravar_utilizadores($nd);

    // Registo: o que mudou
    $mud = [];
    foreach (['nome', 'email', 'perfil', 'rotas', 'activo', 'valido_ate'] as $k) {
        $a = $antes[$k] ?? null;
        $b = $u[$k] ?? null;
        if ($a != $b) $mud[$k] = is_array($b) ? implode(', ', $b) : (is_bool($b) ? ($b ? 'sim' : 'não') : (string) $b);
    }
    naval_registar($novo ? 'conta_criada' : 'conta_alterada', ['conta' => $login] + $mud + ($envolveSadm ? ['aprovado_por' => $aprov] : []));
    return ['senha' => $senha, 'dois_passos_obrigatorio' => in_array($perfil, NAVAL_MFA, true) && empty($u['mfa'])];
}

/** Nova palavra-passe temporária para uma conta. */
function naval_gestao_repor_senha(string $login): string
{
    $d = naval_utilizadores();
    if (!isset($d[$login])) naval_gestao_recusar('Conta não encontrada.', 'senha');
    $senha = naval_senha_temporaria();
    $d[$login]['senha'] = password_hash($senha, PASSWORD_DEFAULT);
    $d[$login]['trocar_senha'] = true;
    naval_gravar_utilizadores($d);
    naval_registar('senha_reposta', ['conta' => $login]);
    return $senha;
}

/** Activa (devolve segredo e endereço otpauth) ou retira a verificação em dois passos. */
function naval_gestao_dois_passos(string $login, bool $activar, array $eu): array
{
    $d = naval_utilizadores();
    if (!isset($d[$login])) naval_gestao_recusar('Conta não encontrada.', 'dois_passos');
    if ($activar) {
        $seg = naval_novo_segredo();
        $d[$login]['mfa'] = $seg;
        naval_gravar_utilizadores($d);
        naval_registar('dois_passos_configurado', ['conta' => $login]);
        return ['segredo' => $seg, 'uri' => naval_otpauth($seg, (string) ($d[$login]['email'] ?? '') ?: $login)];
    }
    if ($login === $eu['login']) naval_gestao_recusar('Não pode retirar a verificação em dois passos da sua própria conta.', 'dois_passos');
    unset($d[$login]['mfa']);
    naval_gravar_utilizadores($d);
    naval_registar('dois_passos_retirado', ['conta' => $login]);
    return [];
}

/** Aplica alterações à matriz (só níveis permitidos). Devolve o número de alterações. */
function naval_gestao_matriz(array $nova): int
{
    $antes = naval_matriz();
    $final = $antes;
    $mud = [];
    foreach ($nova as $rec => $col) {
        if (!isset($antes[$rec]) || !is_array($col)) continue;
        foreach ($col as $perf => $nivel) {
            $nivel = (string) $nivel;
            if (!in_array($perf, NAVAL_COLUNAS, true)) continue;
            if (!in_array($nivel, naval_niveis_recurso($rec), true)) naval_gestao_recusar('Nível não permitido em ' . $rec . ' / ' . $perf . '.', 'matriz');
            if ($antes[$rec][$perf] !== $nivel) {
                $final[$rec][$perf] = $nivel;
                $mud[] = $rec . ' · ' . $perf . ': ' . ($antes[$rec][$perf] ?: '—') . ' → ' . ($nivel ?: '—');
            }
        }
    }
    if (!$mud) return 0;
    naval_gravar_matriz($final);
    naval_registar('matriz_alterada', ['alteracoes' => $mud]);
    return count($mud);
}

/** Dados da página Acessos: perfis, matriz, contas (sem palavras-passe), registos e o que o utilizador pode fazer. */
function naval_gestao_vista(): array
{
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
    $eu = naval_utilizador();
    $gerir = naval_perfil() === 'SADM' && empty($eu['compatibilidade']);
    return [
        'perfis' => NAVAL_PERFIS,
        'matriz' => naval_matriz(),
        'matriz_base' => naval_matriz_base(),
        'niveis' => $niveis,
        'por_rota' => NAVAL_POR_ROTA,
        'perfis_dois_passos' => NAVAL_MFA,
        'rotas' => $gerir ? naval_rotas_portal() : [],
        'utilizadores' => $us,
        'registos' => naval_pode('registos') ? naval_ler_registos(300) : [],
        'pode_desbloquear' => naval_perfil() === 'SADM',
        'pode_gerir' => $gerir,
        'eu' => $eu['login'] ?? '',
        'confirmado' => (int) ($_SESSION['reforco_ate'] ?? 0) >= time(),
    ];
}
