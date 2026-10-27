<?php
/**
 * Prepara o ambiente dos testes do servidor: .env de teste, pasta privada limpa e contas de cada perfil.
 * Corre antes de arrancar o servidor PHP dos testes (ver playwright.config.js). Nada disto vai para produção.
 *
 * Dois ambientes: «ambiente» (porta 8810, dois passos não obrigatórios, para os testes dos serviços) e
 * «ambiente-mfa» (porta 8811, com os dois passos obrigatórios, para o teste da configuração na entrada).
 */
const SENHA_TESTE = 'Teste-12345!';

function apagar(string $d): void
{
    if (!is_dir($d)) return;
    foreach (array_diff(scandir($d), ['.', '..']) as $f) {
        $p = $d . '/' . $f;
        is_dir($p) ? apagar($p) : unlink($p);
    }
    rmdir($d);
}

function preparar_ambiente(string $amb, int $porta, array $extra): string
{
    $priv = $amb . '/dados-privados';
    if (!is_dir($amb)) mkdir($amb, 0770, true);
    apagar($priv);
    mkdir($priv, 0770, true);
    file_put_contents($amb . '/.env', implode("\n", array_merge([
        'USERNAME=u', 'PASSWORD=p', 'PINGUIM_URL=http://127.0.0.1:8801/index.php',
        'DADOS_PRIVADOS=' . $priv, 'EMAIL_MODO=ficheiro', 'PORTAL_URL=http://127.0.0.1:' . $porta,
        // os testes fazem muitas entradas falhadas de propósito a partir do mesmo IP (127.0.0.1)
        'LOGIN_MAX_FALHAS_IP=500', 'GOOGLE_MAPS_API_KEY=chave-de-teste',
    ], $extra)) . "\n");
    return $priv;
}

$base = __DIR__;
$ambientes = [
    $base . '/ambiente' => preparar_ambiente($base . '/ambiente', 8810, ['MFA_OBRIGATORIA=0']),
    $base . '/ambiente-mfa' => preparar_ambiente($base . '/ambiente-mfa', 8811, ['MFA_OBRIGATORIA=1']),
];

$contas = [
    'sadm' => ['SADM', []], 'ti' => ['TI', []], 'dir' => ['DIR', []], 'com' => ['COM', []], 'fin' => ['FIN', []],
    'bo' => ['BO', []], 'log' => ['LOG', []], 'aud' => ['AUD', []], 'sup' => ['SUP', ['1001']], 'pro' => ['PRO', ['1002']],
];
$d = [];
foreach ($contas as $login => [$perfil, $rotas]) {
    $d[$login] = ['nome' => 'Teste ' . $perfil, 'email' => $login . '@naval.co.ao', 'senha' => password_hash(SENHA_TESTE, PASSWORD_DEFAULT),
        'perfil' => $perfil, 'rotas' => $rotas, 'activo' => true, 'criado' => date('Y-m-d')];
}
// as contas gravam-se com o núcleo (formato e permissões reais) em cada pasta privada
foreach ($ambientes as $amb => $priv) {
    file_put_contents($priv . '/utilizadores.json', json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    chmod($priv . '/utilizadores.json', 0660);
}
putenv('NAVAL_ENV=' . $base . '/ambiente/.env');
require __DIR__ . '/../../nucleo/arranque.php';
naval_gravar_utilizadores($d);
echo 'Ambientes de teste prontos: ' . count($d) . ' contas em ' . implode(' e ', array_values($ambientes)) . "\n";
