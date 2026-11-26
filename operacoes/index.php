<?php
/**
 * App de operações «RTM Operações» (aplicação web instalável no iPhone e no Android), para o trabalho no terreno e
 * no armazém, cada pessoa só com as suas tarefas (matriz de acessos):
 *   - Entregar dinheiro (vendedor): as notas e moedas que entrega à tesouraria (v1/caixa-entrega);
 *   - Contar a carrinha (Logística): inventário às cegas da carrinha (v1/conciliacao-carrinha);
 *   - Carregar PO (armazém de carga): lê o QR da PO e confere o que carrega (v1/requisicoes).
 * Mesma entrada, dois passos, equipamento registado pelo QR e âmbito do portal (o mesmo modelo da app de gestão).
 */
define('NAVAL_AUTH_REDIRECT', '../operacoes/');
require_once dirname(__DIR__) . '/geo/auth/auth_check.php';
header('Cache-Control: no-store');
// Só nos equipamentos registados: a app instala-se e activa-se apenas pelo código QR pessoal do portal
naval_servico('app-telemovel');
$tarefas = naval_app_operacoes_tarefas();
if (!$tarefas) {
    header('Location: ../reports/', true, 302);
    exit;
}
$eqApp = !empty($_SESSION['app_equipamento']) ? naval_app_activo((string) $_SESSION['app_equipamento']) : null;
// Excepção documentada (modo apresentação): a app mostra-se no ecrã do computador, só dentro do próprio portal
// (moldura de telemóvel, Sec-Fetch-Dest: iframe), na mesma sessão já autenticada, com o modo apresentação activo
// (recurso «modo-apresentacao», período limitado) e com registo. Fora disso, só nos equipamentos registados.
$previa = !$eqApp && naval_apresentacao_ate() > 0 && naval_pode('modo-apresentacao', 'G') && ($_SERVER['HTTP_SEC_FETCH_DEST'] ?? '') === 'iframe';
if ($previa) {
    if (empty($_SESSION['app_previa_registada']) || (int) $_SESSION['app_previa_registada'] !== naval_apresentacao_ate()) {
        $_SESSION['app_previa_registada'] = naval_apresentacao_ate();
        naval_registar('app_pre_visualizada', ['app' => 'operacoes']);
    }
} elseif (!$eqApp) {
    // sessão do portal aberta (ex.: entrou no portal no navegador do mesmo telemóvel) mas sem o equipamento: se o
    // telemóvel está registado para esta app, entra com a biometria; o código QR só quando não está registado
    $registado = naval_app_equipamento_pedido('operacoes');
    header('Location: ../app/' . ($registado ? 'entrar.php?redirect=' . rawurlencode('../operacoes/') : 'so-pelo-qr.php?app=operacoes'), true, 302);
    exit;
}
// a app só abre com a chave do telemóvel (Face ID, impressão digital ou código de bloqueio): termina a activação
if (!$previa && !$eqApp['credenciais']) {
    header('Location: ../app/activar.php', true, 302);
    exit;
}
header('Content-Type: text/html; charset=utf-8');
require_once dirname(__DIR__) . '/nucleo/versoes.php';
$meta = '<meta name="csrf-token" content="' . htmlspecialchars(naval_csrf(), ENT_QUOTES, 'UTF-8') . '">' . naval_meta_token()
    . '<meta name="naval-tarefas" content="' . htmlspecialchars(implode(',', $tarefas), ENT_QUOTES, 'UTF-8') . '">';
$html = (string) preg_replace('/<head>/i', '<head>' . "\n  " . $meta, (string) file_get_contents(__DIR__ . '/index.html'), 1);
// Velocidade (abrir a app em menos de 1 segundo na rede móvel): estilos dentro da página (menos um pedido), letra do
// sistema do telemóvel (sem descarregar letras) e módulos com versão no endereço, guardados no telemóvel até mudarem
// (import map e pré-carregamento de toda a cadeia de módulos, sem esperar pelos import)
// a base visual é a da app de gestão (mesmas cores, letra e componentes); por cima, o que é próprio desta app
$css = (string) file_get_contents(dirname(__DIR__) . '/gestao/app.css') . PHP_EOL . (string) file_get_contents(__DIR__ . '/app.css');
$html = str_replace('<link rel="stylesheet" href="app.css" />', '<style>' . $css . '</style>', $html);
echo str_ireplace('</body>', naval_faixa_ensaio() . '</body>', naval_portal_versionar($html, dirname(__DIR__) . '/reports', [], '../reports/', 'js/app-operacoes.js'));
