<?php
/**
 * Módulos do portal (arquitectura de monólito modular: uma só aplicação feita de peças independentes, como na
 * Shopify; Domain-Driven Design, um módulo por área de negócio).
 *
 * Cada módulo tem uma ficha em nucleo/modulos/<id>.json (manifesto): páginas, funções da matriz de acessos, rotas da
 * API, conectores com outros sistemas, eventos que publica e escuta, dependências e versão (versões semânticas). O
 * núcleo lê as fichas e é por elas que a matriz de acessos, o menu e a API respeitam o estado de cada módulo:
 *   ligado     para todos os perfis, conforme a matriz de acessos;
 *   piloto     só para o super administrador e o administrador da plataforma (lançamento por anéis, «anel 0»);
 *   desligado  para ninguém: as páginas saem do menu e a API responde 403.
 * Os módulos do núcleo (núcleo e administração) nunca se desligam. O estado fica em dados-privados/modulos.json e
 * cada alteração fica no registo de auditoria.
 *
 * Regras das peças (testes em tests/unidade/modulos.teste.php): cada página do catálogo, cada função da matriz e
 * cada rota da API pertence a um e um só módulo; as dependências existem; um módulo só fala com outro pela API ou por
 * eventos, nunca a ler os ficheiros do outro.
 */
defined('NAVAL_NUCLEO') || exit;

const NAVAL_MODULO_ESTADOS = ['ligado', 'piloto', 'desligado'];
/** Perfis que vêem os módulos em piloto e o ambiente de ensaio. */
const NAVAL_PERFIS_ADMIN_SISTEMA = ['SADM', 'TI'];

/**
 * Conectores com outros sistemas (adaptadores: trocar de fornecedor muda só o conector, não os módulos).
 * id => [nome, chave do .env que indica que está configurado ('' = não depende do .env)].
 */
const NAVAL_CONECTORES = [
    'pinguim-sql' => ['Pinguim BO (SQL Server, só leitura)', 'DB_HOST'],
    'pinguim-rest' => ['Pinguim BO (exportação REST)', 'PINGUIM_URL'],
    'olap' => ['Servidor OLAP (localização das visitas)', 'OLAP_HOST'],
    'google-maps' => ['Google Maps', 'GOOGLE_MAPS_API_KEY'],
    'mdm' => ['ManageEngine MDM Plus', 'MDM_OAUTH_CLIENT_ID'],
    'sap' => ['SAP (ficheiro de carregamento)', ''],
    'sap-odata' => ['SAP S/4HANA (OData, só leitura)', 'SAP_ODATA_URL'],
    'ia' => ['Assistente de inteligência artificial', 'IA_API_KEY'],
    'github' => ['GitHub (versões do portal)', ''],
    'quatenus' => ['Quatenus (GPS das viaturas)', 'QUATENUS_API_URL'],
    'cctv' => ['CCTV do parque (gravador NVR)', 'CCTV_NVR_URL'],
];

/**
 * Ficha de cada conector (catálogo de integrações, como nas plataformas de integração — Azure, MuleSoft, Workato):
 * [categoria, fornecedor, protocolo, sentido dos dados, para que serve].
 */
const NAVAL_CONECTORES_FICHA = [
    'pinguim-sql' => ['Dados de negócio (ERP)', 'Pinguim BO', 'SQL Server (ODBC), só leitura', 'entrada', 'Vendas, cobranças, clientes, stock e cargas, lidos directamente da base de dados.'],
    'pinguim-rest' => ['Dados de negócio (ERP)', 'Pinguim BO', 'REST (HTTPS)', 'entrada', 'Exportações do Pinguim BO pela API, alternativa à base de dados.'],
    'olap' => ['Dados de negócio (ERP)', 'Pinguim BO', 'OLAP (XMLA)', 'entrada', 'Localização das visitas dos promotores aos clientes.'],
    'google-maps' => ['Localização e mapas', 'Google', 'REST (HTTPS)', 'entrada', 'Mapas, moradas e distâncias das rotas.'],
    'quatenus' => ['Localização e mapas', 'Quatenus', 'REST (HTTPS)', 'entrada', 'Posição GPS e percursos das viaturas.'],
    'mdm' => ['Gestão de dispositivos', 'ManageEngine', 'REST (OAuth 2.0)', 'entrada e saída', 'Smartphones das rotas: inventário, localização e modo quiosque.'],
    'cctv' => ['Segurança física', 'Gravador NVR do parque', 'ONVIF / RTSP', 'entrada', 'Imagens do parque nas cargas e descargas das carrinhas.'],
    'ia' => ['Inteligência artificial', 'Fornecedor de IA', 'REST (HTTPS)', 'entrada e saída', 'Assistente que responde a perguntas sobre os dados do portal.'],
    'sap' => ['Integração com sistemas', 'SAP', 'Ficheiro Excel (modelo de carregamento)', 'saída', 'Ficheiro de vendas para carregar no SAP, sem alterações à estrutura.'],
    'sap-odata' => ['Dados de negócio (ERP)', 'SAP', 'OData v2 (HTTPS), só leitura', 'entrada', 'Facturação de todas as empresas (Naval, Foodtec, Higitec, Refitech) para o Relatório de Vendas.'],
    'github' => ['Plataforma', 'GitHub', 'REST (GitHub App)', 'entrada', 'Versões novas do portal, instaladas pelo actualizador.'],
];

/** Pasta das fichas dos módulos. */
function naval_modulos_pasta(): string
{
    return dirname(__DIR__) . '/modulos';
}

/** Fichas de todos os módulos, validadas: [id => ficha]. Uma ficha inválida faz parar com a causa. */
function naval_modulos(): array
{
    static $m = null;
    if ($m !== null) return $m;
    $m = [];
    foreach (glob(naval_modulos_pasta() . '/*.json') ?: [] as $f) {
        $x = json_decode((string) file_get_contents($f), true);
        if (!is_array($x) || ($x['id'] ?? '') !== basename($f, '.json')) throw new RuntimeException('Ficha de módulo inválida: ' . basename($f));
        if (!preg_match('/^\d+\.\d+\.\d+$/', (string) ($x['versao'] ?? ''))) throw new RuntimeException('Versão inválida no módulo ' . $x['id']);
        $x += ['nucleo' => false, 'ensaio' => 'normal', 'paginas' => [], 'recursos' => [], 'api' => [], 'conectores' => [], 'eventos' => ['publica' => [], 'escuta' => []], 'depende' => []];
        $m[$x['id']] = $x;
    }
    ksort($m);
    return $m;
}

/** Módulo de cada função da matriz de acessos: [recurso => id]. */
function naval_modulos_por_recurso(): array
{
    static $r = null;
    if ($r !== null) return $r;
    $r = [];
    foreach (naval_modulos() as $id => $x) foreach ($x['recursos'] as $rec) $r[(string) $rec] = $id;
    return $r;
}

/** Módulo de cada rota da API: [rota => id]. */
function naval_modulos_por_rota(): array
{
    static $r = null;
    if ($r !== null) return $r;
    $r = [];
    foreach (naval_modulos() as $id => $x) foreach ($x['api'] as $rota) $r[(string) $rota] = $id;
    return $r;
}

function naval_modulos_ficheiro(): string
{
    return naval_pasta_privada() . '/modulos.json';
}

/** Estado de cada módulo: [id => ligado|piloto|desligado]. Os do núcleo estão sempre ligados. */
function naval_modulos_estados(bool $recarregar = false): array
{
    static $e = null;
    if ($e !== null && !$recarregar) return $e;
    $gravado = naval_json_ler(naval_modulos_ficheiro());
    $e = [];
    foreach (naval_modulos() as $id => $x) {
        $v = (string) ($gravado[$id]['estado'] ?? 'ligado');
        $e[$id] = $x['nucleo'] || !in_array($v, NAVAL_MODULO_ESTADOS, true) ? 'ligado' : $v;
    }
    return $e;
}

/** O módulo está disponível para este perfil? */
function naval_modulo_disponivel(string $id, string $perfil): bool
{
    $estado = naval_modulos_estados()[$id] ?? 'ligado';
    if ($estado === 'ligado') return true;
    if ($estado === 'piloto') return in_array($perfil, NAVAL_PERFIS_ADMIN_SISTEMA, true);
    return false;
}

/** A função da matriz está num módulo disponível para o perfil? (funções sem módulo: sim) */
function naval_recurso_disponivel(string $recurso, string $perfil): bool
{
    $id = naval_modulos_por_recurso()[$recurso] ?? null;
    return $id === null || naval_modulo_disponivel($id, $perfil);
}

/** No ensaio, o módulo só lê (escreve em sistemas partilhados com a produção)? */
function naval_modulo_so_leitura_no_ensaio(string $id): bool
{
    return naval_ensaio() && (naval_modulos()[$id]['ensaio'] ?? 'normal') === 'so_leitura';
}

/** Muda o estado de um módulo (só o super administrador; regista quem, quando e porquê). */
function naval_modulo_definir(string $id, string $estado, string $motivo = ''): array
{
    $m = naval_modulos();
    if (!isset($m[$id])) throw new NavalErro('Módulo desconhecido.', 404);
    if (!in_array($estado, NAVAL_MODULO_ESTADOS, true)) throw new NavalErro('Estado inválido.', 400);
    if ($m[$id]['nucleo'] && $estado !== 'ligado') throw new NavalErro('Os módulos do núcleo não se desligam.', 409);
    // dependências: desligar um módulo de que outros ligados dependem deixaria esses módulos partidos
    if ($estado !== 'ligado') {
        foreach ($m as $outro => $x) {
            if ($outro !== $id && in_array($id, $x['depende'], true) && (naval_modulos_estados()[$outro] ?? 'ligado') === 'ligado') {
                throw new NavalErro('O módulo «' . $x['nome'] . '» depende deste e está ligado: passe-o primeiro a piloto ou desligue-o.', 409);
            }
        }
    }
    $antes = naval_modulos_estados()[$id] ?? 'ligado';
    naval_exclusivo('modulos', function () use ($id, $estado) {
        $g = naval_json_ler(naval_modulos_ficheiro());
        $g[$id] = ['estado' => $estado, 'por' => (string) ((naval_utilizador() ?: [])['login'] ?? ''), 'em' => date('Y-m-d H:i:s')];
        naval_json_gravar(naval_modulos_ficheiro(), $g);
    });
    naval_modulos_estados(true);
    naval_registar('modulo_estado', ['modulo' => $id, 'antes' => $antes, 'depois' => $estado, 'motivo' => mb_substr($motivo, 0, 300)]);
    return ['id' => $id, 'estado' => $estado];
}

/** Estado de cada conector: ligado | por_ligar. */
function naval_conectores(): array
{
    $out = [];
    foreach (NAVAL_CONECTORES as $id => [$nome, $chave]) {
        [$cat, $forn, $prot, $sentido, $desc] = NAVAL_CONECTORES_FICHA[$id];   // todos os conectores têm ficha (verificado pelo PHPStan)
        $out[$id] = ['id' => $id, 'nome' => $nome, 'estado' => $chave === '' || naval_config($chave) !== '' ? 'ligado' : 'por_ligar',
            'categoria' => $cat, 'fornecedor' => $forn, 'protocolo' => $prot, 'sentido' => $sentido, 'descricao' => $desc, 'definicao' => $chave];
    }
    return $out;
}

/** Vista para a página Administração › Módulos. */
function naval_modulos_vista(): array
{
    $estados = naval_modulos_estados();
    $gravado = naval_json_ler(naval_modulos_ficheiro());
    $con = naval_conectores();
    $lista = [];
    foreach (naval_modulos() as $id => $x) {
        $lista[] = [
            'id' => $id, 'nome' => $x['nome'], 'versao' => $x['versao'], 'descricao' => $x['descricao'], 'nucleo' => (bool) $x['nucleo'],
            'estado' => $estados[$id], 'alterado_por' => (string) ($gravado[$id]['por'] ?? ''), 'alterado_em' => (string) ($gravado[$id]['em'] ?? ''),
            'paginas' => $x['paginas'], 'api' => count($x['api']), 'depende' => $x['depende'], 'eventos' => $x['eventos'],
            'conectores' => array_values(array_map(function ($c) use ($con) { return $con[$c] ?? ['id' => $c, 'nome' => $c, 'estado' => 'por_ligar']; }, $x['conectores'])),
        ];
    }
    foreach ($con as $id => $c) {
        $con[$id]['modulos'] = array_values(array_map(function ($m) { return $m['nome']; }, array_filter($lista, function ($m) use ($id) {
            return in_array($id, array_column($m['conectores'], 'id'), true);
        })));
    }
    return ['modulos' => $lista, 'conectores' => array_values($con)];
}
