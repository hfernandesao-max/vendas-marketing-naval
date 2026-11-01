<?php
/**
 * Módulo «Equipamentos» (gestão de activos do terreno: smartphones e impressoras móveis), fase 1.
 *
 * Base de dados própria do módulo (tabelas ATV_*, ver ferramentas/sql/equipamentos-sqlserver.sql), com escrita,
 * separada da base de dados do Pinguim BO (que o portal só lê). Ligação no .env:
 *   ATV_DB_DSN (DSN PDO completo)  ou  ATV_DB_NAME (+ ATV_DB_HOST, ATV_DB_USER, ATV_DB_PASS; as chaves em falta
 *   vêm das do Pinguim BO: servidor, porta, driver e cifra — uma base de dados nova no mesmo SQL Server).
 *
 * Regras (especificação do pacote «Equipamentos», 31-10-2026):
 *  - ID do activo NAV-TTT-NNNN, permanente, nunca reutilizado; qualquer alteração passa por um evento (ATV_EVENTO,
 *    só inserções); um smartphone tem no máximo uma impressora em vigor e vice-versa;
 *  - estados EM_STOCK, ATRIBUIDO, EM_REPARACAO, PERDIDO_ROUBADO, ABATIDO;
 *  - documentos (Termo de Entrega, Autos, Participação, Convenção de Prova) com SHA-256 na emissão e assinatura
 *    no ecrã (equipamentos-documentos.php).
 * Acessos (matriz): «equipamentos» (consultar; Vr = só as suas rotas), «equipamentos-movimentar» (eventos,
 * documentos e assinaturas) e «equipamentos-administrar» (registar equipamentos, colaboradores, abate).
 * Fase 2 (fora deste ficheiro): selagem PAdES-B-LT com selo temporal, ordens ao MDM Plus, etiquetas, inventário.
 */
defined('NAVAL_NUCLEO') || exit;
require_once __DIR__ . '/bd.php';
require_once __DIR__ . '/rotas.php';

const NAVAL_ATV_ESTADOS = ['EM_STOCK', 'ATRIBUIDO', 'EM_REPARACAO', 'PERDIDO_ROUBADO', 'ABATIDO'];
const NAVAL_ATV_EVENTOS = ['ENTRADA', 'ETIQUETAGEM', 'ATRIBUICAO', 'DEVOLUCAO', 'ASSOCIACAO', 'DESASSOCIACAO', 'REPARACAO_ENVIO',
    'REPARACAO_REGRESSO', 'SUBSTITUICAO', 'PERDA_ROUBO', 'RECUPERACAO', 'REPOSICAO_MDM', 'LEITURA_QR', 'INVENTARIO', 'ABATE', 'DOCUMENTO_ASSINADO'];
/** Eventos que se registam à mão na ficha (os restantes nascem de outras acções) e estados de onde podem partir. */
const NAVAL_ATV_TRANSICOES = [
    'ATRIBUICAO' => [['EM_STOCK'], 'ATRIBUIDO'],
    'DEVOLUCAO' => [['ATRIBUIDO'], 'EM_STOCK'],
    'REPARACAO_ENVIO' => [['EM_STOCK', 'ATRIBUIDO'], 'EM_REPARACAO'],
    'REPARACAO_REGRESSO' => [['EM_REPARACAO'], 'EM_STOCK'],
    'PERDA_ROUBO' => [['EM_STOCK', 'ATRIBUIDO', 'EM_REPARACAO'], 'PERDIDO_ROUBADO'],
    'RECUPERACAO' => [['PERDIDO_ROUBADO'], 'EM_STOCK'],
    'ABATE' => [['EM_STOCK', 'EM_REPARACAO', 'PERDIDO_ROUBADO'], 'ABATIDO'],
    'ETIQUETAGEM' => [null, null],
    'INVENTARIO' => [null, null],
    'REPOSICAO_MDM' => [null, null],
];

// ── Ligação e acesso à base de dados do módulo ──────────────────────────────────────────────────

function naval_atv_configurada(): bool
{
    return naval_config('ATV_DB_DSN') !== '' || naval_config('ATV_DB_NAME') !== '';
}

function naval_atv_bd(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    if (!naval_atv_configurada()) {
        throw new NavalErro('A base de dados do módulo Equipamentos ainda não está configurada no servidor (.env: ATV_DB_NAME ou ATV_DB_DSN).', 503, ['codigo' => 'atv_sem_bd']);
    }
    $pdo = naval_bd_criar('ATV_DB', true);
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') $pdo->exec('PRAGMA foreign_keys = ON');
    return $pdo;
}

function naval_atv_driver(): string
{
    return (string) naval_atv_bd()->getAttribute(PDO::ATTR_DRIVER_NAME);
}

/** Parâmetros e SQL para o ODBC (Windows-1252), como nas consultas ao Pinguim BO. */
function naval_atv_preparar(string $sql, array $p): PDOStatement
{
    $pdo = naval_atv_bd();
    if (naval_atv_driver() === 'odbc') {
        $sql = naval_bd_para_odbc($sql);
        foreach ($p as $k => $v) if (is_string($v)) $p[$k] = naval_bd_para_odbc($v);
    }
    $st = $pdo->prepare($sql);
    try {
        $st->execute($p);
    } catch (PDOException $e) {
        throw naval_atv_erro($e);
    }
    return $st;
}

/** SELECT → linhas (texto em UTF-8). */
function naval_atv_q(string $sql, array $p = []): array
{
    $linhas = naval_atv_preparar($sql, $p)->fetchAll(PDO::FETCH_ASSOC);
    foreach ($linhas as &$l) foreach ($l as $k => $v) if (is_string($v)) $l[$k] = naval_bd_utf8($v);
    unset($l);
    return $linhas;
}

/** INSERT / UPDATE → número de linhas. */
function naval_atv_x(string $sql, array $p = []): int
{
    return naval_atv_preparar($sql, $p)->rowCount();
}

/**
 * INSERT numa tabela do módulo (nomes de tabela e colunas fixos no código, nunca do pedido); com $id, devolve o
 * valor da coluna de identidade. SQL Server: @@IDENTITY logo a seguir, na mesma ligação (as tabelas com triggers não
 * aceitam OUTPUT directo, e os triggers do módulo não inserem noutras tabelas com identidade); SQLite dos testes:
 * RETURNING.
 */
function naval_atv_inserir(string $tabela, array $dados, ?string $id = null)
{
    $cols = array_keys($dados);
    foreach (array_merge([$tabela], $cols, $id ? [$id] : []) as $n) {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $n)) throw new RuntimeException('Nome inválido: ' . $n);
    }
    $marcas = array_map(function ($c) { return ':' . $c; }, $cols);
    $par = array_combine($marcas, array_values($dados));
    $sql = "INSERT INTO $tabela (" . implode(', ', $cols) . ') VALUES (' . implode(', ', $marcas) . ')';
    if ($id === null) {
        naval_atv_x($sql, $par);
        return null;
    }
    if (naval_atv_driver() === 'sqlite') {
        $r = naval_atv_preparar("$sql RETURNING $id", $par)->fetchAll(PDO::FETCH_NUM);
        return $r ? $r[0][0] : null;
    }
    naval_atv_x($sql, $par);
    $r = naval_atv_q('SELECT CAST(@@IDENTITY AS BIGINT) AS V');
    return $r ? $r[0]['V'] : null;
}

/** Transacção: tudo ou nada (eventos, estado e associações de uma acção). */
function naval_atv_transacao(callable $f)
{
    $pdo = naval_atv_bd();
    $pdo->beginTransaction();
    try {
        $r = $f();
        $pdo->commit();
        return $r;
    } catch (Throwable $e) {
        try {
            if ($pdo->inTransaction()) $pdo->rollBack();
        } catch (Throwable $x) {
            // no SQL Server o erro de um trigger já desfez a transacção: fica o erro original
        }
        throw $e;
    }
}

/** Erros da base de dados → mensagens para a pessoa (sem detalhes técnicos). */
function naval_atv_erro(PDOException $e): Throwable
{
    $m = $e->getMessage();
    $mapa = [
        'UX_ATV_ACTIVO_SERIE' => 'Já existe um equipamento com este número de série.',
        'ATV_ACTIVO.NumSerie' => 'Já existe um equipamento com este número de série.',
        'UX_ATV_ACTIVO_IMEI' => 'Já existe um equipamento com este IMEI.',
        'ATV_ACTIVO.Imei' => 'Já existe um equipamento com este IMEI.',
        'UX_ATV_ACTIVO_MAC' => 'Já existe um equipamento com este endereço MAC.',
        'ATV_ACTIVO.MacBluetooth' => 'Já existe um equipamento com este endereço MAC.',
        'UX_ATV_ASSOC' => 'Um dos equipamentos já tem uma associação em vigor.',
        'ATV_ASSOCIACAO.Id' => 'Um dos equipamentos já tem uma associação em vigor.',
        'PK_ATV_ACTIVO' => 'Este ID de activo já existe.',
        'ATV_ACTIVO.IdActivo' => 'Este ID de activo já existe.',
        'UQ_ATV_DOC_NUM' => 'Número de documento repetido. Tente de novo.',
        'ATV_DOCUMENTO.NumDocumento' => 'Número de documento repetido. Tente de novo.',
        'ATV_EVENTO e um registo de auditoria' => 'O histórico não pode ser alterado nem apagado.',
        'ATV_ASSINATURA e um registo' => 'As assinaturas não podem ser alteradas nem apagadas.',
        'Assinatura recusada' => 'Assinatura recusada: o documento já não está por assinar ou foi alterado depois da emissão.',
        'Documento assinado ou anulado' => 'Documento assinado ou anulado: o conteúdo é definitivo.',
    ];
    foreach ($mapa as $chave => $texto) {
        if (stripos($m, $chave) !== false) return new NavalErro($texto, 409, ['codigo' => 'atv_regra']);
    }
    error_log('[equipamentos] ' . $m);
    return new NavalErro('A base de dados do módulo Equipamentos recusou a operação.', 500, naval_detalhe($m));
}

function naval_atv_agora(): string
{
    return date('Y-m-d H:i:s');
}

/** Quem faz a acção: «Nome (login)». */
function naval_atv_quem(): string
{
    $u = function_exists('naval_utilizador') ? naval_utilizador() : null;
    if (!$u) return 'sistema';
    $nome = trim((string) ($u['nome'] ?? ''));
    return ($nome !== '' && $nome !== $u['login'] ? $nome . ' (' . $u['login'] . ')' : $u['login']);
}

/** ID do activo em maiúsculas e validado antes de qualquer consulta (NAV-SMT-0001). */
function naval_atv_id(string $id): string
{
    $id = strtoupper(trim($id));
    if (!preg_match('/^[A-Z]{3}-[A-Z]{3}-\d{4}$/', $id)) throw new NavalErro('ID do activo inválido (formato NAV-SMT-0001).', 400, ['codigo' => 'atv_id']);
    return $id;
}

// ── Leitura ──────────────────────────────────────────────────────────────────────────────────────

/** Linha da base de dados → equipamento para o portal (nomes curtos, sem valores de aquisição). */
function naval_atv_formatar(array $a): array
{
    $n = function ($k) use ($a) { return isset($a[$k]) && $a[$k] !== '' ? $a[$k] : null; };
    return [
        'id' => $a['IdActivo'], 'tipo' => $a['Tipo'], 'marca' => $n('Marca'), 'modelo' => $n('Modelo'),
        'num_serie' => $n('NumSerie'), 'imei1' => $n('Imei1'), 'imei2' => $n('Imei2'), 'mac' => $n('MacBluetooth'),
        'telefone' => $n('NumTelefone'), 'mdm_id' => $n('MdmDeviceId'), 'estado' => $a['Estado'], 'rota' => $n('RotaActual'),
        'id_colaborador' => isset($a['IdColaboradorActual']) ? (int) $a['IdColaboradorActual'] : null,
        'responsavel' => $n('ResponsavelActual'), 'associado' => $n('IdAssociado'), 'associado_desde' => $n('AssociadoDesde'),
        'localizacao' => $n('Localizacao'), 'estado_mdm' => $n('EstadoMdm'), 'ultimo_inventario' => $n('UltimoInventario'),
        'fornecedor' => $n('Fornecedor'), 'fim_garantia' => $n('FimGarantia'), 'criado_em' => $n('CriadoEm'),
        'sem_termo' => !empty($a['SemTermo']),
    ];
}

/** O utilizador vê este equipamento? (perfis por rota só vêem os das suas rotas) */
function naval_atv_no_ambito(array $e): bool
{
    $rotas = naval_rotas();
    return $rotas === null || ($e['rota'] !== null && in_array((string) $e['rota'], $rotas, true));
}

function naval_atv_lista(): array
{
    $l = naval_atv_q('SELECT f.*, CASE WHEN s.IdActivo IS NULL THEN 0 ELSE 1 END AS SemTermo
        FROM VW_ATV_FICHA f LEFT JOIN VW_ATV_SEM_TERMO s ON s.IdActivo = f.IdActivo ORDER BY f.IdActivo');
    $out = [];
    foreach ($l as $a) {
        $e = naval_atv_formatar($a);
        if (naval_atv_no_ambito($e)) $out[] = $e;
    }
    return $out;
}

function naval_atv_obter(string $id): ?array
{
    $l = naval_atv_q('SELECT f.*, CASE WHEN s.IdActivo IS NULL THEN 0 ELSE 1 END AS SemTermo
        FROM VW_ATV_FICHA f LEFT JOIN VW_ATV_SEM_TERMO s ON s.IdActivo = f.IdActivo WHERE f.IdActivo = :id', [':id' => $id]);
    return $l ? naval_atv_formatar($l[0]) : null;
}

/** Ficha completa: equipamento, associado, histórico, associações, documentos e o link para o MDM. */
function naval_atv_ficha(string $id, bool $leituraQr = false): array
{
    $id = naval_atv_id($id);
    $e = naval_atv_obter($id);
    if (!$e) throw new NavalErro('Equipamento não registado.', 404, ['codigo' => 'atv_nao_registado', 'id' => $id]);
    if (!naval_atv_no_ambito($e)) throw new NavalErro('Este equipamento não pertence às suas rotas.', 403);
    if ($leituraQr) naval_atv_evento_inserir($id, 'LEITURA_QR', ['Observacoes' => 'Leitura do código QR da etiqueta']);
    $eventos = naval_atv_q('SELECT ev.*, ca.Nome AS ColabAntes, cd.Nome AS ColabDepois, d.NumDocumento
        FROM ATV_EVENTO ev
        LEFT JOIN ATV_COLABORADOR ca ON ca.IdColaborador = ev.IdColaboradorAntes
        LEFT JOIN ATV_COLABORADOR cd ON cd.IdColaborador = ev.IdColaboradorDepois
        LEFT JOIN ATV_DOCUMENTO d ON d.IdDocumento = ev.IdDocumento
        WHERE ev.IdActivo = :id ORDER BY ev.DataHora DESC, ev.IdEvento DESC', [':id' => $id]);
    $assoc = naval_atv_q('SELECT * FROM ATV_ASSOCIACAO WHERE IdSmartphone = :a OR IdImpressora = :b ORDER BY DataInicio DESC, IdAssociacao DESC', [':a' => $id, ':b' => $id]);
    $docs = naval_atv_q('SELECT d.IdDocumento, d.NumDocumento, d.TipoDocumento, d.Estado, d.DataEmissao, d.DataConclusao, c.Nome AS Colaborador, da.Papel
        FROM ATV_DOCUMENTO_ACTIVO da JOIN ATV_DOCUMENTO d ON d.IdDocumento = da.IdDocumento
        LEFT JOIN ATV_COLABORADOR c ON c.IdColaborador = d.IdColaborador
        WHERE da.IdActivo = :id ORDER BY d.DataEmissao DESC, d.IdDocumento DESC', [':id' => $id]);
    $associado = $e['associado'] ? naval_atv_obter($e['associado']) : null;
    $mdm = naval_config('MDM_URL_EQUIPAMENTO');
    return [
        'equipamento' => $e,
        'associado' => $associado ? ['id' => $associado['id'], 'tipo' => $associado['tipo'], 'estado' => $associado['estado'], 'modelo' => $associado['modelo']] : null,
        'eventos' => array_map(function ($v) {
            return ['data' => $v['DataHora'], 'tipo' => $v['TipoEvento'], 'utilizador' => $v['Utilizador'], 'estado_antes' => $v['EstadoAntes'],
                'estado_depois' => $v['EstadoDepois'], 'rota_antes' => $v['RotaAntes'], 'rota_depois' => $v['RotaDepois'],
                'colaborador_antes' => $v['ColabAntes'], 'colaborador_depois' => $v['ColabDepois'], 'relacionado' => $v['IdActivoRelacionado'],
                'documento' => $v['NumDocumento'], 'observacoes' => $v['Observacoes']];
        }, $eventos),
        'associacoes' => array_map(function ($v) {
            return ['smartphone' => $v['IdSmartphone'], 'impressora' => $v['IdImpressora'], 'inicio' => $v['DataInicio'], 'fim' => $v['DataFim'], 'por' => $v['RegistadoPor']];
        }, $assoc),
        'documentos' => array_map('naval_atv_doc_formatar', $docs),
        'mdm_url' => $mdm !== '' && $e['mdm_id'] ? str_replace('{id}', rawurlencode($e['mdm_id']), $mdm) : null,
    ];
}

function naval_atv_colaboradores(): array
{
    $l = naval_atv_q('SELECT c.*, d.NumDocumento AS Convencao FROM ATV_COLABORADOR c
        LEFT JOIN ATV_DOCUMENTO d ON d.IdDocumento = c.IdDocConvencao ORDER BY c.Activo DESC, c.Nome');
    $rotas = naval_rotas();
    $out = [];
    foreach ($l as $c) {
        if ($rotas !== null && !in_array((string) $c['RotaActual'], $rotas, true)) continue;
        $out[] = ['id' => (int) $c['IdColaborador'], 'nome' => $c['Nome'], 'funcao' => $c['Funcao'], 'telemovel' => $c['Telemovel'],
            'email' => $c['Email'], 'utilizador' => $c['UtilizadorPortal'], 'rota' => $c['RotaActual'], 'activo' => (bool) (int) $c['Activo'],
            'convencao' => $c['Convencao']];
    }
    return $out;
}

function naval_atv_colaborador(int $id): ?array
{
    $l = naval_atv_q('SELECT * FROM ATV_COLABORADOR WHERE IdColaborador = :id', [':id' => $id]);
    return $l ? $l[0] : null;
}

// ── Escrita ──────────────────────────────────────────────────────────────────────────────────────

/** Insere um evento no histórico (nunca se altera nem apaga). */
function naval_atv_evento_inserir(string $id, string $tipo, array $extra = []): void
{
    if (!in_array($tipo, NAVAL_ATV_EVENTOS, true)) throw new NavalErro('Tipo de evento desconhecido.', 400);
    naval_atv_inserir('ATV_EVENTO', ['IdActivo' => $id, 'TipoEvento' => $tipo, 'DataHora' => naval_atv_agora(), 'Utilizador' => naval_atv_quem()] + $extra);
}

/** Texto curto e limpo de um campo do pedido (null se vazio). */
function naval_atv_txt($v, int $max): ?string
{
    $v = trim((string) $v);
    if ($v === '') return null;
    if (function_exists('mb_substr')) return mb_substr($v, 0, $max, 'UTF-8');
    return substr($v, 0, $max);
}

/** Registar um equipamento novo (administrar): ID seguinte do tipo, estado EM_STOCK e evento ENTRADA. */
function naval_atv_registar(array $d): array
{
    $tipo = strtoupper(trim((string) ($d['tipo'] ?? '')));
    $tipos = array_column(naval_atv_q('SELECT Codigo FROM ATV_TIPO'), 'Codigo');
    if (!in_array($tipo, $tipos, true)) throw new NavalErro('Escolha o tipo de equipamento.', 400);
    $mac = naval_atv_txt($d['mac'] ?? '', 17);
    if ($mac !== null) {
        $mac = strtoupper(str_replace('-', ':', $mac));
        if (!preg_match('/^([0-9A-F]{2}:){5}[0-9A-F]{2}$/', $mac)) throw new NavalErro('Endereço MAC inválido (formato AA:BB:CC:DD:EE:FF).', 400);
    }
    foreach (['imei1', 'imei2'] as $k) {
        if (!empty($d[$k]) && !preg_match('/^\d{15}$/', (string) $d[$k])) throw new NavalErro('O IMEI tem 15 algarismos.', 400);
    }
    $serie = naval_atv_txt($d['num_serie'] ?? '', 40);
    if ($tipo === 'SMT' && $serie === null && empty($d['imei1'])) throw new NavalErro('Indique o número de série ou o IMEI do smartphone.', 400);
    if ($tipo === 'IMP' && $mac === null && $serie === null) throw new NavalErro('Indique o endereço MAC Bluetooth da impressora (ou o número de série).', 400);
    return naval_atv_transacao(function () use ($tipo, $d, $mac, $serie) {
        $max = naval_atv_q('SELECT MAX(IdActivo) AS M FROM ATV_ACTIVO WHERE Tipo = :t', [':t' => $tipo]);
        $n = $max && $max[0]['M'] ? (int) substr($max[0]['M'], -4) + 1 : 1;
        if ($n > 9999) throw new NavalErro('Esgotou a numeração deste tipo de equipamento.', 409);
        $id = 'NAV-' . $tipo . '-' . str_pad((string) $n, 4, '0', STR_PAD_LEFT);
        naval_atv_inserir('ATV_ACTIVO', [
            'IdActivo' => $id, 'Tipo' => $tipo, 'Marca' => naval_atv_txt($d['marca'] ?? '', 60), 'Modelo' => naval_atv_txt($d['modelo'] ?? '', 60),
            'NumSerie' => $serie, 'Imei1' => naval_atv_txt($d['imei1'] ?? '', 15), 'Imei2' => naval_atv_txt($d['imei2'] ?? '', 15),
            'MacBluetooth' => $mac, 'NumTelefone' => naval_atv_txt($d['telefone'] ?? '', 20), 'MdmDeviceId' => naval_atv_txt($d['mdm_id'] ?? '', 30),
            'Fornecedor' => naval_atv_txt($d['fornecedor'] ?? '', 120), 'Estado' => 'EM_STOCK', 'CriadoEm' => naval_atv_agora(), 'CriadoPor' => naval_atv_quem(),
        ]);
        naval_atv_evento_inserir($id, 'ENTRADA', ['EstadoDepois' => 'EM_STOCK', 'Observacoes' => naval_atv_txt($d['observacoes'] ?? '', 1000)]);
        return naval_atv_obter($id);
    });
}

/** Fecha a associação em vigor de um equipamento (se houver) e regista DESASSOCIACAO nos dois. */
function naval_atv_fechar_associacao(string $id, string $obs): ?string
{
    $a = naval_atv_q('SELECT * FROM ATV_ASSOCIACAO WHERE (IdSmartphone = :a OR IdImpressora = :b) AND DataFim IS NULL', [':a' => $id, ':b' => $id]);
    if (!$a) return null;
    $outro = $a[0]['IdSmartphone'] === $id ? $a[0]['IdImpressora'] : $a[0]['IdSmartphone'];
    naval_atv_x('UPDATE ATV_ASSOCIACAO SET DataFim = :f WHERE IdAssociacao = :id', [':f' => naval_atv_agora(), ':id' => $a[0]['IdAssociacao']]);
    naval_atv_evento_inserir($id, 'DESASSOCIACAO', ['IdActivoRelacionado' => $outro, 'Observacoes' => $obs]);
    naval_atv_evento_inserir($outro, 'DESASSOCIACAO', ['IdActivoRelacionado' => $id, 'Observacoes' => $obs]);
    return $outro;
}

/** Associa um smartphone a uma impressora (fecha as associações anteriores dos dois). */
function naval_atv_associar(string $a, string $b, string $obs = ''): void
{
    $a = naval_atv_id($a);
    $b = naval_atv_id($b);
    $ea = naval_atv_obter($a);
    $eb = naval_atv_obter($b);
    if (!$ea || !$eb) throw new NavalErro('Equipamento não registado.', 404);
    if ($ea['tipo'] === $eb['tipo'] || !in_array($ea['tipo'], ['SMT', 'IMP'], true) || !in_array($eb['tipo'], ['SMT', 'IMP'], true)) {
        throw new NavalErro('A associação é sempre entre um smartphone e uma impressora.', 400);
    }
    foreach ([$ea, $eb] as $e) if ($e['estado'] === 'ABATIDO') throw new NavalErro('Um equipamento abatido não pode ser associado.', 409);
    $smt = $ea['tipo'] === 'SMT' ? $a : $b;
    $imp = $ea['tipo'] === 'SMT' ? $b : $a;
    if ($ea['associado'] === $b) throw new NavalErro('Estes equipamentos já estão associados.', 409);
    $obs = $obs !== '' ? $obs : null;
    naval_atv_fechar_associacao($smt, 'Troca de associação');
    naval_atv_fechar_associacao($imp, 'Troca de associação');
    naval_atv_inserir('ATV_ASSOCIACAO', ['IdSmartphone' => $smt, 'IdImpressora' => $imp, 'DataInicio' => naval_atv_agora(), 'RegistadoPor' => naval_atv_quem()]);
    naval_atv_evento_inserir($smt, 'ASSOCIACAO', ['IdActivoRelacionado' => $imp, 'Observacoes' => $obs]);
    naval_atv_evento_inserir($imp, 'ASSOCIACAO', ['IdActivoRelacionado' => $smt, 'Observacoes' => $obs]);
}

/** Muda o estado, a rota e o responsável de um equipamento e regista o evento (com o antes e o depois). */
function naval_atv_mudar(array $e, string $tipo, string $estado, ?string $rota, ?int $colab, array $extra = []): void
{
    naval_atv_x('UPDATE ATV_ACTIVO SET Estado = :e, RotaActual = :r, IdColaboradorActual = :c WHERE IdActivo = :id',
        [':e' => $estado, ':r' => $rota, ':c' => $colab, ':id' => $e['id']]);
    naval_atv_evento_inserir($e['id'], $tipo, [
        'EstadoAntes' => $e['estado'], 'EstadoDepois' => $estado, 'RotaAntes' => $e['rota'], 'RotaDepois' => $rota,
        'IdColaboradorAntes' => $e['id_colaborador'], 'IdColaboradorDepois' => $colab,
    ] + $extra);
}

/**
 * Regista um evento na ficha (movimentar; o abate exige administrar). Com «incluir_associado», a atribuição, a
 * devolução, a reparação e a perda aplicam-se também ao equipamento associado em vigor.
 */
function naval_atv_registar_evento(string $id, array $d): array
{
    $id = naval_atv_id($id);
    $tipo = strtoupper((string) ($d['tipo'] ?? ''));
    if ($tipo === 'ASSOCIACAO') {
        naval_atv_transacao(function () use ($id, $d) { naval_atv_associar($id, (string) ($d['outro'] ?? ''), (string) naval_atv_txt($d['observacoes'] ?? '', 1000)); });
        return naval_atv_ficha($id);
    }
    if ($tipo === 'DESASSOCIACAO') {
        naval_atv_transacao(function () use ($id, $d) {
            if (naval_atv_fechar_associacao($id, (string) (naval_atv_txt($d['observacoes'] ?? '', 1000) ?? 'Desassociação')) === null) {
                throw new NavalErro('Este equipamento não tem associação em vigor.', 409);
            }
        });
        return naval_atv_ficha($id);
    }
    if (!isset(NAVAL_ATV_TRANSICOES[$tipo])) throw new NavalErro('Este evento não se regista à mão na ficha.', 400);
    if ($tipo === 'ABATE' && !naval_pode('equipamentos-administrar', 'G')) throw new NavalErro('O abate exige a permissão «Equipamentos – Administrar».', 403);
    [$de, $para] = NAVAL_ATV_TRANSICOES[$tipo];
    $obs = naval_atv_txt($d['observacoes'] ?? '', 1000);
    naval_atv_transacao(function () use ($id, $tipo, $de, $para, $d, $obs) {
        $e = naval_atv_obter($id);
        if (!$e) throw new NavalErro('Equipamento não registado.', 404);
        if (!naval_atv_no_ambito($e)) throw new NavalErro('Este equipamento não pertence às suas rotas.', 403);
        $alvos = [$e];
        if (!empty($d['incluir_associado']) && $e['associado'] && in_array($tipo, ['ATRIBUICAO', 'DEVOLUCAO', 'REPARACAO_ENVIO', 'PERDA_ROUBO'], true)) {
            $o = naval_atv_obter($e['associado']);
            if ($o) $alvos[] = $o;
        }
        if ($de === null) {
            if ($tipo === 'INVENTARIO') naval_atv_x('UPDATE ATV_ACTIVO SET UltimoInventario = :d WHERE IdActivo = :id', [':d' => naval_atv_agora(), ':id' => $id]);
            naval_atv_evento_inserir($id, $tipo, ['Observacoes' => $obs]);
            return;
        }
        $rota = null;
        $colab = null;
        if ($tipo === 'ATRIBUICAO') {
            $colab = (int) ($d['id_colaborador'] ?? 0);
            $c = $colab ? naval_atv_colaborador($colab) : null;
            if (!$c || !(int) $c['Activo']) throw new NavalErro('Escolha o colaborador a quem é atribuído.', 400);
            $rota = naval_atv_txt($d['rota'] ?? '', 10) ?? ($c['RotaActual'] ?: null);
            if ($rota === null) throw new NavalErro('Indique a rota.', 400);
            $rotas = naval_rotas();
            if ($rotas !== null && !in_array($rota, $rotas, true)) throw new NavalErro('Esta rota não é uma das suas.', 403);
        }
        foreach ($alvos as $a) {
            if (!in_array($a['estado'], $de, true)) {
                throw new NavalErro('O equipamento ' . $a['id'] . ' está em «' . $a['estado'] . '»: este evento não se aplica.', 409, ['codigo' => 'atv_estado']);
            }
        }
        foreach ($alvos as $a) {
            naval_atv_mudar($a, $tipo, $para, $rota, $colab, ['Observacoes' => $obs]);
            if ($tipo === 'ABATE') naval_atv_fechar_associacao($a['id'], 'Abate do equipamento');
        }
    });
    return naval_atv_ficha($id);
}

/** Criar ou actualizar um colaborador (administrar). */
function naval_atv_guardar_colaborador(array $d): array
{
    $nome = naval_atv_txt($d['nome'] ?? '', 120);
    if ($nome === null) throw new NavalErro('Indique o nome do colaborador.', 400);
    $email = naval_atv_txt($d['email'] ?? '', 120);
    if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new NavalErro('Correio electrónico inválido.', 400);
    $tel = naval_atv_txt($d['telemovel'] ?? '', 20);
    if ($tel !== null && !preg_match('/^\+?[0-9 ]{9,20}$/', $tel)) throw new NavalErro('Número de telemóvel inválido.', 400);
    $dados = ['Nome' => $nome, 'Funcao' => naval_atv_txt($d['funcao'] ?? '', 80), 'Telemovel' => $tel, 'Email' => $email,
        'UtilizadorPortal' => naval_atv_txt($d['utilizador'] ?? '', 120), 'RotaActual' => naval_atv_txt($d['rota'] ?? '', 10),
        'Activo' => isset($d['activo']) ? ($d['activo'] ? 1 : 0) : 1];
    $id = (int) ($d['id'] ?? 0);
    if ($id) {
        if (!naval_atv_colaborador($id)) throw new NavalErro('Colaborador não encontrado.', 404);
        $sets = implode(', ', array_map(function ($k) { return "$k = :$k"; }, array_keys($dados)));
        $p = [];
        foreach ($dados as $k => $v) $p[':' . $k] = $v;
        naval_atv_x("UPDATE ATV_COLABORADOR SET $sets WHERE IdColaborador = :id", $p + [':id' => $id]);
    } else {
        $id = (int) naval_atv_inserir('ATV_COLABORADOR', $dados + ['CriadoEm' => naval_atv_agora()], 'IdColaborador');
    }
    naval_registar('equipamentos_colaborador', ['id' => $id, 'nome' => $nome]);
    return ['id' => $id];
}

/** Resumo para os quadros da lista. */
function naval_atv_resumo(array $lista): array
{
    $r = ['total' => count($lista), 'por_estado' => array_fill_keys(NAVAL_ATV_ESTADOS, 0), 'sem_termo' => 0];
    foreach ($lista as $e) {
        $r['por_estado'][$e['estado']] = ($r['por_estado'][$e['estado']] ?? 0) + 1;
        if ($e['sem_termo']) $r['sem_termo']++;
    }
    return $r;
}

// Documentos e assinaturas (usa as funções acima)
require_once __DIR__ . '/equipamentos-documentos.php';
