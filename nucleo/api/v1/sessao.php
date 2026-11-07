<?php
/**
 * Sessão actual para o portal: utilizador, perfil, rotas e nível de acesso em cada página.
 * O portal usa isto para mostrar só o que o perfil pode ver; os dados são filtrados no servidor.
 */
defined('NAVAL_API') || exit;   // só pela API central (api/index.php): token JWT já validado

$u = naval_utilizador();
if (!$u) throw new NavalErro('Sessão terminada.', 401);
naval_servico('preferencias');
naval_servico('actualizacao');
naval_servico('app-telemovel');
/** Versão nova no ensaio, para o menu da conta dos administradores de sistema (null para os outros perfis). */
function naval_sessao_ensaio(string $perfil): ?array
{
    if (naval_ensaio() || !in_array($perfil, NAVAL_PERFIS_ADMIN_SISTEMA, true) || !naval_act_ensaio_ligado()) return null;
    $e = naval_act_ensaio_estado();
    if ($e['versao'] === '' || $e['versao'] === (string) (naval_act_estado()['versao'] ?? '')) return null;
    return ['versao' => $e['versao'], 'resultado' => $e['resultado'], 'url' => '../ensaio/reports/'];
}
$perm = [];
foreach (array_keys(naval_matriz()) as $rec) $perm[$rec] = naval_nivel($rec);
$GLOBALS['naval_api_sensivel'] = true;   // tem o código CSRF: nunca em cache nem comprimido
naval_json([
    'login' => $u['login'],
    'nome' => $u['nome'] ?? $u['login'],
    'perfil' => $u['perfil'],
    'perfil_nome' => naval_perfil_nome($u['perfil']),
    'rotas' => naval_rotas(),
    'nif' => naval_ve_nif(),
    'permissoes' => $perm,
    'email' => $u['email'] ?? '',
    // idioma da ficha da conta e momento da entrada: o portal abre nesse idioma a cada nova entrada
    'idioma' => naval_idioma_conta($u),
    'entrada' => (int) ($_SESSION['login_time'] ?? 0),
    'csrf' => naval_csrf(),
    'dois_passos' => !empty($u['mfa']),
    'compatibilidade' => !empty($u['compatibilidade']),
    // preferências pessoais (menu lateral): seguem a conta em qualquer dispositivo
    'preferencias' => naval_pref_ler((string) $u['login']),
    'sessao' => ['inactiva_s' => NAVAL_SESSAO_INACTIVA, 'maxima_s' => NAVAL_SESSAO_MAXIMA],
    // app instalada pelo código QR (equipamento registado) ou null: fora disso, o portal não funciona como app
    'app' => !empty($_SESSION['app_equipamento']) ? (string) $_SESSION['app_equipamento'] : null,
    // menu da conta: «App no telemóvel» a vermelho quando já tem a app activa num telemóvel, a verde quando não tem
    'app_instalada' => naval_app_tem_activa((string) ($u['login'] ?? '')),
    'app_biometria' => !empty($_SESSION['app_equipamento']) && function_exists('naval_app_activo') && !empty((naval_app_activo((string) $_SESSION['app_equipamento']) ?: [])['credenciais']),
    // versão instalada no servidor: a app no telemóvel recarrega-se sozinha quando muda (actualização transparente)
    'versao' => naval_ensaio() ? naval_ensaio_versao() : (string) (naval_act_estado()['versao'] ?? ''),
    // ambiente de ensaio: 'ensaio' dentro de <portal>/ensaio; na produção, a versão nova no ensaio (só SADM e TI)
    'ambiente' => naval_ensaio() ? 'ensaio' : 'producao',
    'ensaio' => naval_sessao_ensaio((string) $u['perfil']),
]);
