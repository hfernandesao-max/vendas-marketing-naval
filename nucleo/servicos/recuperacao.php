<?php
/**
 * Recuperação da conta pela própria pessoa (palavra-passe esquecida ou conta bloqueada), segundo o OWASP Forgot
 * Password Cheat Sheet, o OWASP ASVS 4.0 (2.5) e o NIST SP 800-63B (6.1.2.3):
 *  - resposta igual exista ou não a conta, e com o mesmo tempo mínimo (não revela que contas existem);
 *  - ligação de uso único, aleatória (256 bits), válida 30 minutos, enviada só para o correio electrónico da ficha;
 *    no servidor guarda-se apenas o resumo SHA-256 (quem ler os ficheiros não consegue usar a ligação);
 *  - um pedido novo anula a ligação anterior; limites por endereço IP e por conta;
 *  - a recuperação não contorna os dois passos: com a verificação em dois passos activa, pede também o código;
 *  - no fim: palavra-passe nova (regras do portal), conta desbloqueada, todas as sessões abertas terminadas,
 *    aviso por correio electrónico e registo na auditoria. Não entra automaticamente (volta à página de entrada).
 * Quem perdeu o telemóvel dos dois passos continua a precisar do super administrador (confirmação presencial).
 */
defined('NAVAL_NUCLEO') || exit;
naval_servico('email');

const NAVAL_REC_MINUTOS = 30;          // validade da ligação
const NAVAL_REC_TENTATIVAS = 3;        // códigos de dois passos errados antes de a ligação ser anulada
const NAVAL_REC_MAX_IP = 5;            // pedidos por endereço IP em 15 minutos
const NAVAL_REC_MAX_CONTA = 3;         // pedidos por conta numa hora
const NAVAL_REC_TEMPO_MINIMO = 1.2;    // segundos: o pedido demora sempre pelo menos isto (exista ou não a conta)

/** Saudação pela hora de Luanda (Bom dia / Boa tarde / Boa noite). */
function naval_rec_saudacao(): string
{
    $h = (int) date('G');
    return $h < 12 ? 'Bom dia,' : ($h < 20 ? 'Boa tarde,' : 'Boa noite,');
}

function naval_rec_pasta(): string
{
    return naval_pasta('recuperacao');
}

/** Ficheiro da ligação (pelo resumo do código) e ficheiro da conta (pedidos recentes e ligação activa). */
function naval_rec_ficheiro_ligacao(string $resumo): string
{
    return naval_rec_pasta() . '/l-' . $resumo . '.json';
}
function naval_rec_ficheiro_conta(string $login): string
{
    return naval_rec_pasta() . '/c-' . hash('sha256', strtolower(trim($login))) . '.json';
}

/** Endereço público do portal (PORTAL_URL); nunca o cabeçalho Host do pedido (que um atacante controla). */
function naval_rec_url(string $codigo): string
{
    $base = rtrim(naval_config('PORTAL_URL'), '/');
    return $base === '' ? '' : $base . '/geo/recuperar.php?t=' . rawurlencode($codigo);
}

/** O endereço IP já fez demasiados pedidos nos últimos 15 minutos? (regista este pedido) */
function naval_rec_limite_ip(string $ip): bool
{
    if ($ip === '') return false;
    return naval_exclusivo('rec-ip', function () use ($ip) {
        $f = naval_pasta('limites') . '/rec-' . hash('sha256', $ip) . '.json';
        $limite = time() - 900;
        $l = array_values(array_filter((array) (naval_json_ler($f)['pedidos'] ?? []), function ($t) use ($limite) {
            return (int) $t > $limite;
        }));
        if (count($l) >= NAVAL_REC_MAX_IP) return true;
        $l[] = time();
        naval_json_gravar($f, ['pedidos' => $l], false);
        return false;
    });
}

/**
 * Pedido de recuperação. Responde sempre o mesmo à pessoa; só envia a ligação quando a conta existe, está activa
 * e tem correio electrónico. Devolve o que aconteceu (para os registos e os testes): enviado, sem_conta,
 * sem_email, limite_ip, limite_conta, erro_envio ou sem_endereco.
 */
function naval_rec_pedir(string $id, string $ip): string
{
    $t0 = microtime(true);
    $r = naval_rec_pedir_agora(trim($id), $ip);
    $falta = NAVAL_REC_TEMPO_MINIMO - (microtime(true) - $t0);
    if ($falta > 0) usleep((int) ($falta * 1e6) + random_int(0, 150000));
    return $r;
}

function naval_rec_pedir_agora(string $id, string $ip): string
{
    if (naval_rec_limite_ip($ip)) {
        naval_registar('recuperacao_limite_ip', ['ip' => $ip], naval_id_registo($id));
        return 'limite_ip';
    }
    [$login, $u] = $id === '' ? [null, null] : naval_procurar_conta($id);
    if (!$login || !$u || !empty($u['compatibilidade']) || !naval_conta_valida($u)) {
        naval_registar('recuperacao_pedida', ['resultado' => 'sem_conta'], naval_id_registo($id));
        return 'sem_conta';
    }
    if (!naval_email_valido($u['email'] ?? null)) {
        naval_registar('recuperacao_pedida', ['resultado' => 'sem_email'], $login);
        return 'sem_email';
    }
    $codigo = bin2hex(random_bytes(32));
    $resumo = hash('sha256', $codigo);
    $res = naval_exclusivo('recuperacao', function () use ($login, $resumo, $ip) {
        $fc = naval_rec_ficheiro_conta($login);
        $c = naval_json_ler($fc);
        $hora = time() - 3600;
        $pedidos = array_values(array_filter((array) ($c['pedidos'] ?? []), function ($t) use ($hora) {
            return (int) $t > $hora;
        }));
        if (count($pedidos) >= NAVAL_REC_MAX_CONTA) return 'limite_conta';
        // um pedido novo anula a ligação anterior
        if (!empty($c['ligacao'])) @unlink(naval_rec_ficheiro_ligacao((string) $c['ligacao']));
        $pedidos[] = time();
        naval_json_gravar(naval_rec_ficheiro_ligacao($resumo), [
            'login' => $login, 'criada' => time(), 'expira' => time() + NAVAL_REC_MINUTOS * 60, 'ip' => $ip, 'tentativas' => 0,
        ], false);
        naval_json_gravar($fc, ['pedidos' => $pedidos, 'ligacao' => $resumo], false);
        return 'ok';
    });
    if ($res !== 'ok') {
        naval_registar('recuperacao_pedida', ['resultado' => $res], $login);
        return $res;
    }
    $url = naval_rec_url($codigo);
    if ($url === '') {
        error_log('[recuperacao] PORTAL_URL em falta no .env: não é possível enviar a ligação de recuperação.');
        naval_registar('recuperacao_pedida', ['resultado' => 'sem_endereco'], $login);
        return 'sem_endereco';
    }
    $nome = htmlspecialchars((string) ($u['nome'] ?? $login), ENT_QUOTES, 'UTF-8');
    $ligacao = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
    $html = '<div style="font-family:Segoe UI,Arial,sans-serif;font-size:14px;color:#0b1f3a;line-height:1.55;text-align:justify;max-width:560px">'
        . '<p>' . naval_rec_saudacao() . '</p><p>' . $nome . ', recebemos um pedido para recuperar o acesso à sua conta do Portal RTM'
        . ' (palavra-passe esquecida ou conta bloqueada).</p>'
        . '<p style="text-align:center;margin:22px 0"><a href="' . $ligacao . '" style="background:#273777;color:#fff;padding:11px 22px;border-radius:8px;text-decoration:none;font-weight:600">Escolher uma nova palavra-passe</a></p>'
        . '<p>A ligação é válida durante ' . NAVAL_REC_MINUTOS . ' minutos e só pode ser usada uma vez. Se não foi a sua pessoa a pedir,'
        . ' ignore esta mensagem: a palavra-passe actual continua válida. Nunca partilhe esta ligação.</p>'
        . '<p style="color:#52617a;font-size:12px">Pedido feito em ' . date('d-m-Y') . ' às ' . date('H:i') . ' a partir do endereço ' . htmlspecialchars($ip, ENT_QUOTES, 'UTF-8') . '.</p>'
        . '<p>Obrigado.</p><p>Cumps,<br>Portal RTM · Grupo Naval</p></div>';
    $env = naval_enviar_email([['email' => $u['email'], 'nome' => $u['nome'] ?? $login]], 'Portal RTM: recuperar o acesso à sua conta', $html);
    if (empty($env['ok'])) {
        error_log('[recuperacao] envio falhou: ' . ($env['erro'] ?? ''));
        naval_registar('recuperacao_pedida', ['resultado' => 'erro_envio'], $login);
        return 'erro_envio';
    }
    naval_registar('recuperacao_pedida', ['resultado' => 'enviado', 'ip' => $ip], $login);
    return 'enviado';
}

/** Ligação válida (existe, não expirou, a conta continua activa): devolve [login, conta] ou null. */
function naval_rec_ligacao(string $codigo): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $codigo)) return null;
    $f = naval_rec_ficheiro_ligacao(hash('sha256', $codigo));
    $l = naval_json_ler($f);
    if (empty($l['login']) || (int) ($l['expira'] ?? 0) < time()) {
        if (is_file($f)) @unlink($f);
        return null;
    }
    $u = naval_utilizadores()[$l['login']] ?? null;
    if (!$u || !naval_conta_valida($u)) return null;
    return [(string) $l['login'], $u];
}

/** Anula a ligação (uso único). */
function naval_rec_anular(string $codigo, string $login): void
{
    @unlink(naval_rec_ficheiro_ligacao(hash('sha256', $codigo)));
    $fc = naval_rec_ficheiro_conta($login);
    $c = naval_json_ler($fc);
    unset($c['ligacao']);
    naval_json_gravar($fc, $c, false);
}

/**
 * Conclui a recuperação: valida o código dos dois passos (se a conta os tiver) e a palavra-passe nova; grava,
 * desbloqueia, termina as sessões e avisa. Devolve null (concluído) ou a mensagem de erro.
 */
function naval_rec_concluir(string $codigo, string $nova, string $confirmar, string $codigoMfa, string $ip): ?string
{
    $l = naval_rec_ligacao($codigo);
    if (!$l) return 'A ligação expirou ou já foi usada. Peça uma nova.';
    [$login, $u] = $l;
    if (!empty($u['mfa'])) {
        if (!naval_totp_aceitar($login, (string) $u['mfa'], $codigoMfa)) {
            $f = naval_rec_ficheiro_ligacao(hash('sha256', $codigo));
            $d = naval_json_ler($f);
            $d['tentativas'] = (int) ($d['tentativas'] ?? 0) + 1;
            naval_registar('recuperacao_codigo_errado', ['tentativas' => $d['tentativas']], $login);
            naval_ip_falha($ip);
            if ($d['tentativas'] >= NAVAL_REC_TENTATIVAS) {
                naval_rec_anular($codigo, $login);
                return 'Código inválido. Por segurança, a ligação foi anulada: peça uma nova.';
            }
            naval_json_gravar($f, $d, false);
            $resta = NAVAL_REC_TENTATIVAS - $d['tentativas'];
            return 'Código de verificação inválido. ' . ($resta === 1 ? 'Resta 1 tentativa.' : 'Restam ' . $resta . ' tentativas.');
        }
    }
    if ($nova !== $confirmar) return 'As duas palavras-passe não são iguais.';
    if ($f = naval_senha_fraca($nova, $login, (string) ($u['email'] ?? ''))) return $f;
    $novo = naval_exclusivo('utilizadores', function () use ($login, $nova) {
        $todos = naval_utilizadores();
        if (!isset($todos[$login])) return null;
        $x = $todos[$login];
        $x['senha'] = password_hash($nova, PASSWORD_DEFAULT);
        unset($x['trocar_senha'], $x['temporaria_ate']);
        $x = naval_nova_versao($x);   // todas as sessões abertas da conta terminam
        $todos[$login] = $x;
        naval_gravar_utilizadores($todos);
        return $x;
    });
    if (!$novo) return 'A ligação expirou ou já foi usada. Peça uma nova.';
    naval_rec_anular($codigo, $login);
    $estava = naval_bloqueado($login);
    @unlink(naval_bloqueio_ficheiro($login));   // desbloqueia e limpa as tentativas falhadas
    if ($estava) naval_registar('conta_desbloqueada', ['conta' => $login, 'por' => 'recuperacao'], $login);
    naval_registar('conta_recuperada', ['ip' => $ip, 'dois_passos' => !empty($u['mfa']), 'estava_bloqueada' => $estava], $login);
    $nome = htmlspecialchars((string) ($u['nome'] ?? $login), ENT_QUOTES, 'UTF-8');
    naval_enviar_email([['email' => $u['email'], 'nome' => $u['nome'] ?? $login]], 'Portal RTM: a sua palavra-passe foi alterada',
        '<div style="font-family:Segoe UI,Arial,sans-serif;font-size:14px;color:#0b1f3a;line-height:1.55;text-align:justify;max-width:560px">'
        . '<p>' . naval_rec_saudacao() . '</p><p>' . $nome . ', a palavra-passe da sua conta do Portal RTM foi alterada em ' . date('d-m-Y') . ' às ' . date('H:i')
        . ' (endereço ' . htmlspecialchars($ip, ENT_QUOTES, 'UTF-8') . ')' . ($estava ? ' e a conta foi desbloqueada' : '')
        . '. As sessões que estavam abertas foram terminadas.</p>'
        . '<p>Se não foi a sua pessoa, contacte de imediato o super administrador da plataforma.</p>'
        . '<p>Obrigado.</p><p>Cumps,<br>Portal RTM · Grupo Naval</p></div>');
    return null;
}
