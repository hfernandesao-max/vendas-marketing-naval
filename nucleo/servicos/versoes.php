<?php
/**
 * Identificação e classificação das versões do portal (gestão da configuração: ISO 10007, ISO/IEC/IEEE 12207 e
 * ISO/IEC 27001:2022 A.8.32 gestão das alterações; formato de calendário CalVer, https://calver.org).
 *
 *  - Identificador: v<AAAA.MM.DD>.<N> — data de publicação (hora de Luanda, ordem ISO 8601) e N, número sequencial
 *    próprio do portal, sem falhas: a versão anterior + 1 (nunca o contador de execuções do GitHub, que conta também
 *    as execuções sem versão e recomeça se o processo for recriado). É o número usado no dia-a-dia («v.108»).
 *  - Commit (revisão do código) em cada pacote, na nota da versão e no portal (Actualizações), para ligar a versão ao
 *    código exacto.
 *  - Classificação obrigatória de cada alteração no CHANGELOG.md (<!-- tipo: … -->): correcção, melhoria, nova
 *    funcionalidade ou alteração com impacto. Alterações em ficheiros sensíveis (base de dados, ficheiro e integração
 *    SAP, segurança e acessos, actualizador, Apache) têm de estar classificadas como «alteração com impacto»: a
 *    verificação dos pedidos de integração (scripts/verificar-versao.php) recusa-as de outra forma.
 */
defined('NAVAL_NUCLEO') || exit;

/** Tipos de alteração, do menos para o mais exigente (a versão tem o tipo mais exigente das suas alterações). */
const NAVAL_VERSAO_TIPOS = [
    'correcao' => 'Correcção',
    'melhoria' => 'Melhoria',
    'funcionalidade' => 'Nova funcionalidade',
    'impacto' => 'Alteração com impacto',
];

/** Classificação obrigatória a partir deste dia (as secções anteriores do registo ficam como estavam). */
const NAVAL_VERSAO_CLASSIFICACAO_DESDE = '2026-11-16';

/** Ficheiros cuja alteração é sempre «alteração com impacto» (expressões sobre o caminho no repositório). */
const NAVAL_VERSAO_IMPACTO = [
    '#^ferramentas/sql/#' => 'base de dados',
    '#^nucleo/servicos/(sap|sap-odata)\.php$#' => 'ficheiro e integração SAP',
    '#^reports/js/paginas/sap\.js$#' => 'ficheiro e integração SAP',
    '#^nucleo/seguranca/#' => 'segurança e acessos',
    '#^nucleo/servicos/(gestao|funcoes)\.php$#' => 'segurança e acessos',
    '#^geo/(login|auth/)#' => 'segurança e acessos',
    '#^nucleo/servicos/actualizacao\.php$#' => 'actualizador do servidor',
    '#^ferramentas/actualizar\.php$#' => 'actualizador do servidor',
    '#^(\.htaccess|ferramentas/apache/)#' => 'configuração do Apache',
];

/** Próxima versão: v<dia>.<maior N das versões existentes + 1>. $dia no formato AAAA.MM.DD. */
function naval_versao_proxima(array $etiquetas, string $dia): string
{
    if (!preg_match('/^\d{4}\.\d{2}\.\d{2}$/', $dia)) throw new InvalidArgumentException('Dia inválido: ' . $dia);
    $n = 0;
    foreach ($etiquetas as $e) {
        if (preg_match('/^v\d{4}\.\d{2}\.\d{2}\.(\d+)$/', trim((string) $e), $m)) $n = max($n, (int) $m[1]);
    }
    return 'v' . $dia . '.' . ($n + 1);
}

/** O tipo mais exigente de uma lista (ou '' se nenhum for válido). */
function naval_versao_tipo_maior(array $tipos): string
{
    $ordem = array_flip(array_keys(NAVAL_VERSAO_TIPOS));
    $maior = '';
    foreach ($tipos as $t) {
        if (isset($ordem[$t]) && ($maior === '' || $ordem[$t] > $ordem[$maior])) $maior = $t;
    }
    return $maior;
}

/** Data AAAA-MM-DD do cabeçalho «## dd-mm-aaaa» (ou '' se não for uma data). */
function naval_versao_data_seccao(string $data): string
{
    return preg_match('/^(\d{2})-(\d{2})-(\d{4})$/', trim($data), $m) ? $m[3] . '-' . $m[2] . '-' . $m[1] : '';
}

/** Áreas sensíveis tocadas pelos ficheiros alterados: [caminho => área]. */
function naval_versao_impacto(array $ficheiros): array
{
    $out = [];
    foreach ($ficheiros as $f) {
        $f = (string) preg_replace('#^\./#', '', str_replace('\\', '/', trim((string) $f)));
        foreach (NAVAL_VERSAO_IMPACTO as $re => $area) {
            if ($f !== '' && preg_match($re, $f)) {
                $out[$f] = $area;
                break;
            }
        }
    }
    return $out;
}

/**
 * Verificação de um pedido de integração: as secções novas do registo (naval_nov_seccoes) e os ficheiros alterados.
 * Devolve a lista de erros (vazia quando está tudo certo).
 */
function naval_versao_verificar(array $novas, array $ficheiros): array
{
    $erros = [];
    $tipos = [];
    foreach ($novas as $s) {
        $tipo = (string) ($s['tipo'] ?? '');
        if (!isset(NAVAL_VERSAO_TIPOS[$tipo])) {
            $erros[] = 'Secção «' . $s['titulo'] . '» sem classificação válida: acrescente <!-- tipo: ' . implode(' | ', array_keys(NAVAL_VERSAO_TIPOS)) . ' -->.';
        }
        $tipos[] = $tipo;
    }
    $imp = naval_versao_impacto($ficheiros);
    if ($imp && !in_array('impacto', $tipos, true)) {
        $lista = [];
        foreach ($imp as $f => $area) $lista[] = $f . ' (' . $area . ')';
        $erros[] = ($novas ? 'Nenhuma secção nova está classificada como «impacto»' : 'Falta a secção no CHANGELOG.md, classificada como «impacto»')
            . ', mas foram alterados ficheiros sensíveis: ' . implode(', ', $lista) . '.';
    }
    return $erros;
}
