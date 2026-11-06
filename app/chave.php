<?php
/**
 * App no telemóvel (JSON, mesma origem): activação com o código QR, Face ID / impressão digital (chaves de acesso
 * WebAuthn) e entrada com a chave. Regras e verificações: nucleo/servicos/app-telemovel.php.
 *
 * POST {f:'activar', codigo}                  activa a app neste equipamento e abre a sessão
 * POST {f:'registo-opcoes'} / {f:'registar'}  cria a chave de acesso (sessão da app já aberta)
 * POST {f:'entrada-opcoes', equipamento}      desafio para entrar com a chave deste equipamento
 * POST {f:'entrar', equipamento, resposta}    confirma a assinatura e abre a sessão
 */
require_once __DIR__ . '/../nucleo/arranque.php';
naval_iniciar_sessao();
naval_servico('app-telemovel');
header('Cache-Control: no-store');

try {
    if (!naval_e_post()) throw new NavalErro('Método não permitido.', 405);
    // só pedidos da própria página (JSON e mesma origem): impede pedidos forjados a partir de outros sites
    [, $origem] = naval_wa_rp();
    if (stripos((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== 0 || (string) ($_SERVER['HTTP_ORIGIN'] ?? '') !== $origem) {
        throw new NavalErro('Pedido recusado.', 403);
    }
    $q = json_decode((string) file_get_contents('php://input', false, null, 0, 65536), true);
    if (!is_array($q)) throw new NavalErro('Pedido inválido.', 400);
    $f = (string) ($q['f'] ?? '');
    $ip = naval_ip_cliente();
    if (in_array($f, ['activar', 'entrar'], true) && naval_ip_suspenso($ip)) throw new NavalErro('Demasiadas tentativas falhadas a partir deste endereço. Aguarde 15 minutos.', 429);

    if ($f === 'activar') {
        [$eq, $erro] = naval_app_activar((string) ($q['codigo'] ?? ''), (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), $ip);
        if (!$eq) {
            naval_ip_falha($ip);
            throw new NavalErro($erro, 400);
        }
        $u = naval_utilizadores()[$eq['login']];
        naval_abrir_sessao($eq['login'], $u, true, 'codigo_qr', $eq['id']);
        naval_json(['ok' => true, 'equipamento' => $eq['id'], 'app' => $eq['app'], 'nome' => NAVAL_APP_NOMES[$eq['app']], 'destino' => NAVAL_APP_DESTINO[$eq['app']],
            'pessoa' => (string) ($u['nome'] ?? $eq['login'])]);
    }
    if ($f === 'registo-opcoes' || $f === 'registar') {
        $id = (string) ($_SESSION['app_equipamento'] ?? '');
        $eq = $id !== '' && naval_autenticado() ? naval_app_activo($id) : null;
        if (!$eq) throw new NavalErro('Abra a app instalada pelo código QR do portal.', 403);
        if ($f === 'registo-opcoes') naval_json(['ok' => true, 'opcoes' => naval_wa_opcoes_registo($eq, naval_utilizador() ?: [])]);
        naval_wa_registar($id, (array) ($q['resposta'] ?? []));
        naval_json(['ok' => true]);
    }
    if ($f === 'entrada-opcoes' || $f === 'entrar') {
        $id = (string) ($q['equipamento'] ?? '');
        $eq = naval_app_activo($id);
        if (!$eq) naval_json(['ok' => false, 'desactivada' => true, 'erro' => 'Esta app foi desactivada ou activada noutro equipamento. No portal, abra «App no telemóvel» e leia o código QR.'], 410);
        if (!$eq['credenciais']) naval_json(['ok' => false, 'sem_chave' => true, 'erro' => 'A biometria ainda não está activada neste equipamento. Entre com a palavra-passe.'], 409);
        if ($f === 'entrada-opcoes') naval_json(['ok' => true, 'opcoes' => naval_wa_opcoes_entrada($eq)]);
        $u = naval_utilizadores()[$eq['login']] ?? null;
        if (!$u || !naval_conta_valida($u)) throw new NavalErro('Esta conta está desactivada ou expirou. Contacte o administrador da plataforma.', 403);
        if (naval_bloqueado($eq['login'])) throw new NavalErro('A conta está bloqueada. Para a desbloquear, use «Esqueceu-se da palavra-passe ou tem a conta bloqueada?».', 403);
        if (!empty($u['trocar_senha'])) throw new NavalErro('Entre com a palavra-passe para escolher uma nova.', 409);
        try {
            naval_wa_entrar($id, (array) ($q['resposta'] ?? []));
        } catch (RuntimeException $e) {
            naval_ip_falha($ip);
            naval_registar('entrada_falhada', ['motivo' => 'chave', 'erro' => $e->getMessage(), 'equipamento' => $id], $eq['login']);
            throw new NavalErro('Não foi possível confirmar a biometria. Tente de novo ou entre com a palavra-passe.', 401);
        }
        naval_abrir_sessao($eq['login'], $u, true, 'chave', $id);
        naval_json(['ok' => true, 'destino' => NAVAL_APP_DESTINO[$eq['app']]]);
    }
    throw new NavalErro('Pedido desconhecido.', 400);
} catch (NavalErro $e) {
    naval_json(['ok' => false, 'erro' => $e->getMessage()], $e->estado());
} catch (RuntimeException $e) {
    naval_json(['ok' => false, 'erro' => $e->getMessage()], 400);
}
