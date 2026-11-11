<?php
/**
 * Lista de componentes da versão (SBOM, formato CycloneDX 1.5): as bibliotecas de terceiros que seguem no pacote
 * (reports/vendor/componentes.json, com licença e SHA-256) e o ambiente que o portal exige (PHP e Apache, de
 * ferramentas/requisitos.json). Publicada com cada versão e atestada no GitHub (ISO/IEC 5230 OpenChain, SLSA).
 *
 * php scripts/sbom.php <ficheiro de saída> <versão> [commit]
 */
if ($argc < 3) {
    fwrite(STDERR, "Uso: php scripts/sbom.php <saída> <versão> [commit]\n");
    exit(2);
}
$raiz = dirname(__DIR__);
$inv = json_decode((string) file_get_contents($raiz . '/reports/vendor/componentes.json'), true);
$req = json_decode((string) @file_get_contents($raiz . '/ferramentas/requisitos.json'), true) ?: [];
$versao = $argv[2];
$comp = [];
foreach ($inv['componentes'] as $c) {
    $f = $raiz . '/reports/vendor/' . $c['ficheiro'];
    $sha = is_file($f) ? hash_file('sha256', $f) : '';
    if ($sha !== $c['sha256']) {
        fwrite(STDERR, 'SHA-256 de ' . $c['ficheiro'] . " não confere com componentes.json\n");
        exit(1);
    }
    if (!in_array($c['licenca'], $inv['licencas_permitidas'], true)) {
        fwrite(STDERR, 'Licença não permitida em ' . $c['ficheiro'] . ': ' . $c['licenca'] . "\n");
        exit(1);
    }
    $comp[] = [
        'type' => 'library', 'bom-ref' => 'npm:' . $c['nome'] . '@' . $c['versao'], 'name' => $c['nome'], 'version' => $c['versao'],
        'purl' => 'pkg:npm/' . $c['nome'] . '@' . $c['versao'], 'licenses' => [['license' => ['id' => $c['licenca']]]],
        'hashes' => [['alg' => 'SHA-256', 'content' => $sha]], 'externalReferences' => [['type' => 'vcs', 'url' => $c['origem']]],
        'properties' => [['name' => 'portal:ficheiro', 'value' => 'reports/vendor/' . $c['ficheiro']]],
    ];
}
if (!empty($req['php'])) $comp[] = ['type' => 'platform', 'bom-ref' => 'php', 'name' => 'PHP', 'version' => '>=' . $req['php'], 'scope' => 'required'];
$comp[] = ['type' => 'platform', 'bom-ref' => 'apache', 'name' => 'Apache HTTP Server', 'version' => '2.4', 'scope' => 'required'];
$sbom = [
    'bomFormat' => 'CycloneDX', 'specVersion' => '1.5', 'version' => 1,
    'serialNumber' => 'urn:uuid:' . vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex(random_bytes(16)), 4)),
    'metadata' => [
        'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
        'component' => ['type' => 'application', 'bom-ref' => 'portal-rtm', 'name' => 'Portal RTM', 'version' => $versao]
            + (isset($argv[3]) ? ['properties' => [['name' => 'git:commit', 'value' => $argv[3]]]] : []),
        'supplier' => ['name' => 'Grupo Naval Angola'],
    ],
    'components' => $comp,
    'dependencies' => [['ref' => 'portal-rtm', 'dependsOn' => array_column($comp, 'bom-ref')]],
];
file_put_contents($argv[1], json_encode($sbom, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
echo 'SBOM: ' . count($comp) . " componentes → {$argv[1]}\n";
