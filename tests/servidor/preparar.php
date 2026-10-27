<?php
/**
 * Prepara o ambiente dos testes do servidor: .env de teste, pasta privada limpa e contas de cada perfil.
 * Corre antes de arrancar o servidor PHP dos testes (ver playwright.config.js). Nada disto vai para produção.
 */
$amb = __DIR__ . '/ambiente';
$priv = $amb . '/dados-privados';
if (!is_dir($amb)) mkdir($amb, 0770, true);

function apagar(string $d): void
{
    if (!is_dir($d)) return;
    foreach (array_diff(scandir($d), ['.', '..']) as $f) {
        $p = $d . '/' . $f;
        is_dir($p) ? apagar($p) : unlink($p);
    }
    rmdir($d);
}
apagar($priv);
mkdir($priv, 0770, true);

file_put_contents($amb . '/.env', implode("\n", [
    'USERNAME=u', 'PASSWORD=p', 'PINGUIM_URL=http://127.0.0.1:8801/index.php',
    'DADOS_PRIVADOS=' . $priv, 'EMAIL_MODO=ficheiro', 'PORTAL_URL=http://127.0.0.1:8810',
    // os testes fazem muitas entradas falhadas de propósito a partir do mesmo IP (127.0.0.1)
    'LOGIN_MAX_FALHAS_IP=500', 'GOOGLE_MAPS_API_KEY=chave-de-teste',
]) . "\n");
putenv('NAVAL_ENV=' . $amb . '/.env');
require __DIR__ . '/../../nucleo/arranque.php';

const SENHA_TESTE = 'Teste-12345!';
$contas = [
    'sadm' => ['SADM', []], 'ti' => ['TI', []], 'dir' => ['DIR', []], 'com' => ['COM', []], 'fin' => ['FIN', []],
    'bo' => ['BO', []], 'log' => ['LOG', []], 'aud' => ['AUD', []], 'sup' => ['SUP', ['1001']], 'pro' => ['PRO', ['1002']],
];
$d = [];
foreach ($contas as $login => [$perfil, $rotas]) {
    $d[$login] = ['nome' => 'Teste ' . $perfil, 'email' => $login . '@naval.co.ao', 'senha' => password_hash(SENHA_TESTE, PASSWORD_DEFAULT),
        'perfil' => $perfil, 'rotas' => $rotas, 'activo' => true, 'criado' => date('Y-m-d')];
}
naval_gravar_utilizadores($d);
echo "Ambiente de teste pronto: " . count($d) . " contas em $priv\n";
