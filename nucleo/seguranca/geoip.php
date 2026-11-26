<?php
/**
 * Localização aproximada de um endereço IP a partir de uma base de dados local no formato MaxMind DB
 * (.mmdb): DB-IP «IP to City Lite» (CC BY 4.0, sem conta) ou MaxMind GeoLite2 City (conta gratuita).
 *
 * - Sem serviços externos: o IP de quem entra nunca sai do servidor (protecção de dados, Lei 22/11).
 * - Precisão típica: país quase sempre certo; cidade aproximada (raio de dezenas de km). É a mesma prática
 *   das contas Google e Microsoft («actividade recente»). Nunca é uma morada.
 * - Leitor próprio, só de leitura, conforme a especificação MaxMind DB 2.0; sem dependências (PHP 7.1+).
 * - Ficheiro: .env GEOIP_BD (por omissão dados-privados/geoip/cidades.mmdb); actualização mensal com
 *   ferramentas/actualizar-geoip.php.
 */
defined('NAVAL_NUCLEO') || exit;

final class NavalMmdb
{
    /** @var resource */
    private $f;
    /** @var array<string,mixed> */
    public $meta;
    private $nos;
    private $bits;
    private $tamNo;
    private $inicioDados;
    private $no4 = 0;

    public function __construct(string $ficheiro)
    {
        $f = @fopen($ficheiro, 'rb');
        if (!$f) throw new RuntimeException('Base de dados de localização indisponível.');
        $this->f = $f;
        $tam = (int) fstat($f)['size'];
        $janela = min($tam, 128 * 1024);
        fseek($f, $tam - $janela);
        $fim = (string) fread($f, $janela);
        $p = strrpos($fim, "\xAB\xCD\xEFMaxMind.com");
        if ($p === false) throw new RuntimeException('Ficheiro .mmdb inválido.');
        [$meta] = $this->descodificar($tam - $janela + $p + 14, $tam - $janela + $p + 14);
        $this->meta = $meta;
        $this->nos = (int) $meta['node_count'];
        $this->bits = (int) $meta['record_size'];
        if (!in_array($this->bits, [24, 28, 32], true)) throw new RuntimeException('Tamanho de registo não suportado.');
        $this->tamNo = $this->bits / 4;
        $this->inicioDados = $this->nos * $this->tamNo + 16;
        if ((int) $meta['ip_version'] === 6) {
            $n = 0;
            for ($i = 0; $i < 96 && $n < $this->nos; $i++) $n = $this->registo($n, 0);
            $this->no4 = $n;
        }
    }

    public function __destruct()
    {
        if (is_resource($this->f)) fclose($this->f);
    }

    /** Registo do IP (array) ou null se não houver. */
    public function procurar(string $ip): ?array
    {
        $bin = @inet_pton($ip);
        if ($bin === false) return null;
        $nBits = strlen($bin) * 8;
        if ($nBits === 128 && (int) $this->meta['ip_version'] === 4) return null;
        $n = $nBits === 32 && (int) $this->meta['ip_version'] === 6 ? $this->no4 : 0;
        for ($i = 0; $i < $nBits && $n < $this->nos; $i++) {
            $bit = (ord($bin[$i >> 3]) >> (7 - ($i & 7))) & 1;
            $n = $this->registo($n, $bit);
        }
        if ($n === $this->nos || $n < $this->nos) return null;
        $pos = $n - $this->nos - 16 + $this->inicioDados;
        [$v] = $this->descodificar($pos, $this->inicioDados);
        return is_array($v) ? $v : null;
    }

    private function ler(int $pos, int $n): string
    {
        if ($n === 0) return '';
        fseek($this->f, $pos);
        $s = (string) fread($this->f, $n);
        if (strlen($s) !== $n) throw new RuntimeException('Ficheiro .mmdb truncado.');
        return $s;
    }

    private function registo(int $no, int $lado): int
    {
        $b = $this->ler($no * $this->tamNo, $this->tamNo);
        if ($this->bits === 24) $v = $lado ? substr($b, 3, 3) : substr($b, 0, 3);
        elseif ($this->bits === 32) $v = $lado ? substr($b, 4, 4) : substr($b, 0, 4);
        else {   // 28 bits: o nibble do meio pertence aos dois registos
            $m = ord($b[3]);
            $v = $lado ? chr($m & 0x0F) . substr($b, 4, 3) : chr($m >> 4) . substr($b, 0, 3);
        }
        return $this->inteiro($v);
    }

    private function inteiro(string $b): int
    {
        $v = 0;
        for ($i = 0, $n = strlen($b); $i < $n; $i++) $v = ($v << 8) | ord($b[$i]);
        return $v;
    }

    /** @return array{0:mixed,1:int} valor e posição seguinte */
    private function descodificar(int $pos, int $base, int $prof = 0)
    {
        if ($prof > 64) throw new RuntimeException('Ficheiro .mmdb inválido (profundidade).');
        $ctrl = ord($this->ler($pos++, 1));
        $tipo = $ctrl >> 5;
        if ($tipo === 1) {   // ponteiro
            $ss = ($ctrl >> 3) & 3;
            $vvv = $ctrl & 7;
            $b = $this->ler($pos, $ss + 1);
            $pos += $ss + 1;
            if ($ss === 0) $p = ($vvv << 8) | ord($b);
            elseif ($ss === 1) $p = (($vvv << 16) | $this->inteiro($b)) + 2048;
            elseif ($ss === 2) $p = (($vvv << 24) | $this->inteiro($b)) + 526336;
            else $p = $this->inteiro($b);
            [$v] = $this->descodificar($base + $p, $base, $prof + 1);
            return [$v, $pos];
        }
        if ($tipo === 0) $tipo = 7 + ord($this->ler($pos++, 1));
        $tam = $ctrl & 0x1F;
        if ($tam >= 29) {
            $extra = $tam - 28;
            $n = $this->inteiro($this->ler($pos, $extra));
            $pos += $extra;
            $tam = [1 => 29, 2 => 285, 3 => 65821][$extra] + $n;
        }
        switch ($tipo) {
            case 2: return [$this->ler($pos, $tam), $pos + $tam];   // texto UTF-8
            case 3: return [unpack('E', $this->ler($pos, 8))[1], $pos + 8];   // double
            case 4: return [$this->ler($pos, $tam), $pos + $tam];   // bytes
            case 5: case 6: case 9: case 10:   // inteiros sem sinal
                return [$tam ? $this->inteiro($this->ler($pos, $tam)) : 0, $pos + $tam];
            case 8:
                $v = $tam ? $this->inteiro($this->ler($pos, $tam)) : 0;
                if ($tam === 4 && $v >= 0x80000000) $v -= 0x100000000;
                return [$v, $pos + $tam];
            case 15: return [unpack('G', $this->ler($pos, 4))[1], $pos + 4];   // float
            case 14: return [$tam !== 0, $pos];
            case 7:
                $m = [];
                for ($i = 0; $i < $tam; $i++) {
                    [$k, $pos] = $this->descodificar($pos, $base, $prof + 1);
                    [$m[(string) $k], $pos] = $this->descodificar($pos, $base, $prof + 1);
                }
                return [$m, $pos];
            case 11:
                $a = [];
                for ($i = 0; $i < $tam; $i++) [$a[], $pos] = $this->descodificar($pos, $base, $prof + 1);
                return [$a, $pos];
        }
        throw new RuntimeException('Ficheiro .mmdb inválido (tipo ' . $tipo . ').');
    }
}

function naval_geoip_ficheiro(): string
{
    return naval_config('GEOIP_BD', naval_pasta_privada() . '/geoip/cidades.mmdb');
}

/** Endereços que não são da internet pública (rede da empresa, VPN, o próprio servidor). */
function naval_ip_privado(string $ip): bool
{
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
}

// ── Correcções do portal ─────────────────────────────────────────────────────────────────────────────────────
// A base de dados põe cada bloco de endereços onde a operadora o registou, não onde é usado: uma operadora que serve
// Angola com endereços registados na Namíbia aparece em «Windhoek». O super administrador corrige a rede (ou só o IP)
// uma vez, no portal, e a correcção vale para as entradas novas e para as já registadas (os registos, encadeados, não
// mudam: a correcção aplica-se ao ler). Fica dito «corrigido no portal» e quem corrigiu.

/** Cidades com coordenadas, para o mapa das correcções (capitais de província de Angola e as mais usadas). */
const NAVAL_GEOIP_CIDADES = [
    'Luanda' => ['AO', 'Luanda', -8.839, 13.289], 'Viana' => ['AO', 'Luanda', -8.903, 13.374], 'Cacuaco' => ['AO', 'Luanda', -8.776, 13.371],
    'Talatona' => ['AO', 'Luanda', -8.917, 13.183], 'Benguela' => ['AO', 'Benguela', -12.578, 13.408], 'Lobito' => ['AO', 'Benguela', -12.364, 13.536],
    'Huambo' => ['AO', 'Huambo', -12.776, 15.739], 'Lubango' => ['AO', 'Huíla', -14.917, 13.493], 'Cabinda' => ['AO', 'Cabinda', -5.55, 12.2],
    'Malanje' => ['AO', 'Malanje', -9.54, 16.341], 'Uíge' => ['AO', 'Uíge', -7.608, 15.061], 'Soyo' => ['AO', 'Zaire', -6.135, 12.369],
    "M'banza Kongo" => ['AO', 'Zaire', -6.267, 14.24], 'Namibe' => ['AO', 'Namibe', -15.196, 12.152], 'Kuito' => ['AO', 'Bié', -12.383, 16.933],
    'Saurimo' => ['AO', 'Lunda Sul', -9.661, 20.392], 'Dundo' => ['AO', 'Lunda Norte', -7.38, 20.83], 'Menongue' => ['AO', 'Cuando Cubango', -14.659, 17.691],
    'Ondjiva' => ['AO', 'Cunene', -17.067, 15.733], 'Sumbe' => ['AO', 'Cuanza Sul', -11.206, 13.844], "N'dalatando" => ['AO', 'Cuanza Norte', -9.298, 14.912],
    'Caxito' => ['AO', 'Bengo', -8.578, 13.664], 'Luena' => ['AO', 'Moxico', -11.783, 19.917],
];
const NAVAL_GEOIP_PAISES = ['AO' => 'Angola', 'PT' => 'Portugal', 'NA' => 'Namíbia', 'ZA' => 'África do Sul', 'AE' => 'Emirados Árabes Unidos',
    'CD' => 'República Democrática do Congo', 'CG' => 'República do Congo', 'BR' => 'Brasil', 'ER' => 'Eritreia', 'ET' => 'Etiópia', 'IN' => 'Índia'];

function naval_geoip_correcoes_ficheiro(): string
{
    return naval_pasta_privada() . '/geoip/correcoes.json';
}

/** Correcções gravadas {chave (IP ou rede/24, /48): {pais, pais_nome, regiao, cidade, lat, lon, por, em, nota}}. */
function naval_geoip_correcoes(bool $recarregar = false): array
{
    static $c = null;
    if ($c === null || $recarregar) $c = (array) naval_json_ler(naval_geoip_correcoes_ficheiro());
    return $c;
}

/** Rede de um IP: IPv4 /24 («196.216.51.0/24»), IPv6 /48. */
function naval_geoip_rede(string $ip): string
{
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return preg_replace('/\.\d+$/', '.0', $ip) . '/24';
    $b = @inet_pton($ip);
    if ($b === false || strlen($b) !== 16) return '';
    return inet_ntop(substr($b, 0, 6) . str_repeat("\0", 10)) . '/48';
}

/** Correcção que vale para este IP (a do próprio IP primeiro, depois a da rede), já no formato da localização. */
function naval_geoip_correcao(string $ip): ?array
{
    if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) return null;
    $c = naval_geoip_correcoes();
    $x = $c[$ip] ?? $c[naval_geoip_rede($ip)] ?? null;
    if (!is_array($x)) return null;
    return array_filter(['pais' => (string) ($x['pais'] ?? ''), 'pais_nome' => (string) ($x['pais_nome'] ?? ''), 'regiao' => (string) ($x['regiao'] ?? ''),
        'cidade' => (string) ($x['cidade'] ?? ''), 'lat' => $x['lat'] ?? null, 'lon' => $x['lon'] ?? null], function ($v) { return $v !== null && $v !== ''; })
        + ['fonte' => 'corrigido no portal por ' . (string) ($x['por'] ?? '') . ' em ' . substr((string) ($x['em'] ?? ''), 0, 10), 'corrigido' => true];
}

/** A localização gravada num registo, com a correcção do portal se houver uma para esse IP. */
function naval_geoip_corrigir_local(string $ip, ?array $local): ?array
{
    $c = naval_geoip_correcao($ip);
    if ($c === null) return $local;
    if ($local && empty($local['corrigido']) && ($local['pais'] ?? '') !== ($c['pais'] ?? '')) $c['antes'] = trim(($local['cidade'] ?? '') . ', ' . ($local['pais_nome'] ?? $local['pais'] ?? ''), ', ');
    return $c;
}

/** Grava ou retira uma correcção (só o super administrador). $ambito: «rede» (o /24 do IP) ou «ip». */
function naval_geoip_gravar_correcao(string $ip, string $ambito, string $pais, string $cidade, string $regiao, string $nota, string $por): array
{
    if (filter_var($ip, FILTER_VALIDATE_IP) === false || naval_ip_privado($ip)) throw new NavalErro('Endereço IP inválido.', 400);
    $pais = strtoupper(trim($pais));
    if (!preg_match('/^[A-Z]{2}$/', $pais)) throw new NavalErro('Escolha o país.', 400);
    $cidade = naval_texto($cidade, 80);
    if ($cidade === '') throw new NavalErro('Indique a cidade.', 400);
    $chave = $ambito === 'ip' ? $ip : naval_geoip_rede($ip);
    $conhecida = NAVAL_GEOIP_CIDADES[$cidade] ?? null;
    $x = ['pais' => $pais, 'pais_nome' => NAVAL_GEOIP_PAISES[$pais] ?? $pais, 'regiao' => naval_texto($regiao, 80) ?: ($conhecida && $conhecida[0] === $pais ? $conhecida[1] : ''),
        'cidade' => $cidade, 'lat' => $conhecida && $conhecida[0] === $pais ? $conhecida[2] : null, 'lon' => $conhecida && $conhecida[0] === $pais ? $conhecida[3] : null,
        'nota' => naval_texto($nota, 200), 'por' => $por, 'em' => date('Y-m-d H:i:s'), 'antes' => naval_geoip_sem_correcao($ip)];
    naval_exclusivo('geoip-correcoes', function () use ($chave, $x) {
        $c = naval_geoip_correcoes(true);
        $c[$chave] = $x;
        if (!is_dir(dirname(naval_geoip_correcoes_ficheiro()))) @mkdir(dirname(naval_geoip_correcoes_ficheiro()), 0770, true);
        naval_json_gravar(naval_geoip_correcoes_ficheiro(), $c);
        naval_geoip_correcoes(true);
    });
    naval_registar('geoip_corrigido', ['chave' => $chave, 'local' => $cidade . ', ' . $x['pais_nome']]);
    return ['chave' => $chave] + $x;
}

function naval_geoip_remover_correcao(string $chave): void
{
    naval_exclusivo('geoip-correcoes', function () use ($chave) {
        $c = naval_geoip_correcoes(true);
        if (!isset($c[$chave])) throw new NavalErro('Correcção desconhecida.', 404);
        unset($c[$chave]);
        naval_json_gravar(naval_geoip_correcoes_ficheiro(), $c);
        naval_geoip_correcoes(true);
    });
    naval_registar('geoip_correcao_retirada', ['chave' => $chave]);
}

/** O que a base de dados diz do IP, sem as correcções (para mostrar o «antes»). */
function naval_geoip_sem_correcao(string $ip): string
{
    $l = naval_geoip($ip, false);
    return trim(($l['cidade'] ?? '') . ', ' . ($l['pais_nome'] ?? ($l['pais'] ?? '')), ', ');
}

/**
 * Localização do IP: ['pais' => 'AO', 'pais_nome', 'regiao', 'cidade', 'lat', 'lon', 'raio_km', 'fonte'] ou
 * ['rede' => 'interna'] para endereços privados; [] se não houver base de dados ou registo.
 * Nomes em português quando a base os tem (senão em inglês).
 */
function naval_geoip(string $ip, bool $comCorrecoes = true): array
{
    static $bd = false, $cache = [];
    if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) return [];
    if ($comCorrecoes && !naval_ip_privado($ip) && ($c = naval_geoip_correcao($ip)) !== null) return $c;
    if (isset($cache[$ip])) return $cache[$ip];
    if (naval_ip_privado($ip)) return $cache[$ip] = ['rede' => 'interna'];
    if ($bd === false) {
        $bd = null;
        $f = naval_geoip_ficheiro();
        if (is_file($f)) {
            try { $bd = new NavalMmdb($f); } catch (Throwable $e) { $bd = null; }
        }
    }
    if (!$bd) return $cache[$ip] = [];
    try { $r = $bd->procurar($ip); } catch (Throwable $e) { $r = null; }
    if (!$r) return $cache[$ip] = [];
    $nome = function ($x) {
        $n = (array) ($x['names'] ?? []);
        return (string) ($n['pt-BR'] ?? $n['pt'] ?? $n['en'] ?? '');
    };
    $sub = (array) (($r['subdivisions'] ?? [])[0] ?? []);
    $loc = (array) ($r['location'] ?? []);
    $out = array_filter([
        'pais' => (string) ($r['country']['iso_code'] ?? ''),
        'pais_nome' => $nome($r['country'] ?? []),
        'regiao' => $nome($sub),
        'cidade' => $nome($r['city'] ?? []),
        'lat' => isset($loc['latitude']) ? round((float) $loc['latitude'], 3) : null,
        'lon' => isset($loc['longitude']) ? round((float) $loc['longitude'], 3) : null,
        'raio_km' => isset($loc['accuracy_radius']) ? (int) $loc['accuracy_radius'] : null,
    ], function ($v) { return $v !== null && $v !== ''; });
    if ($out) $out['fonte'] = (string) ($bd->meta['database_type'] ?? 'mmdb') . ' ' . date('Y-m', (int) ($bd->meta['build_epoch'] ?? time()));
    return $cache[$ip] = $out;
}

/**
 * Descarrega (DB-IP «IP to City Lite» do mês, ou do mês anterior) ou instala um ficheiro local, valida e substitui a
 * base de dados actual. Devolve ['ok' => bool, 'mensagem' => texto, 'tipo', 'edicao'].
 * Usado pela tarefa automática do servidor (todos os meses, sem intervenção) e por ferramentas/actualizar-geoip.php.
 */
function naval_geoip_instalar(?string $ficheiroLocal = null): array
{
    $destino = naval_geoip_ficheiro();
    $pasta = dirname($destino);
    if (!is_dir($pasta) && !@mkdir($pasta, 0750, true)) return ['ok' => false, 'mensagem' => 'Não foi possível criar a pasta ' . $pasta];
    $tmp = $destino . '.novo';
    $origem = '';
    if ($ficheiroLocal !== null) {
        if (!is_file($ficheiroLocal) || !copy($ficheiroLocal, $tmp)) return ['ok' => false, 'mensagem' => 'Ficheiro não encontrado: ' . $ficheiroLocal];
        $origem = basename($ficheiroLocal);
    } else {
        foreach ([date('Y-m'), date('Y-m', strtotime('first day of last month'))] as $mes) {
            $url = "https://download.db-ip.com/free/dbip-city-lite-{$mes}.mmdb.gz";
            $ctx = stream_context_create(['http' => ['timeout' => 300, 'user_agent' => 'PortalRTM-GeoIP/1.0', 'follow_location' => 1],
                'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
            $in = @fopen('compress.zlib://' . $url, 'rb', false, $ctx);
            if (!$in) continue;
            $out = fopen($tmp, 'wb');
            $n = $out ? stream_copy_to_stream($in, $out) : 0;
            fclose($in);
            if ($out) fclose($out);
            if ($n > 1000000) {
                $origem = basename($url);
                break;
            }
        }
        if ($origem === '') {
            @unlink($tmp);
            return ['ok' => false, 'mensagem' => 'Não foi possível descarregar a base de dados (rede, proxy ou edição ainda não publicada).'];
        }
    }
    // validação antes de substituir: abre, tem registos e localiza um IP de teste
    try {
        $bd = new NavalMmdb($tmp);
        $ok = (int) ($bd->meta['node_count'] ?? 0) > 1000 && $bd->procurar('8.8.8.8') !== null;
        $tipo = (string) ($bd->meta['database_type'] ?? '?');
        $edicao = date('d-m-Y', (int) ($bd->meta['build_epoch'] ?? 0));
        unset($bd);
    } catch (Throwable $e) {
        $ok = false;
        $tipo = $edicao = '';
    }
    if (!$ok) {
        @unlink($tmp);
        return ['ok' => false, 'mensagem' => 'O ficheiro não é uma base de dados de localização válida. A actual mantém-se.'];
    }
    if (is_file($destino)) {
        if (is_file($destino . '.anterior')) @unlink($destino . '.anterior');
        @rename($destino, $destino . '.anterior');
    }
    if (!@rename($tmp, $destino)) return ['ok' => false, 'mensagem' => 'Não foi possível instalar ' . $destino];
    naval_registar('geoip_actualizado', ['origem' => $origem, 'tipo' => $tipo, 'edicao' => $edicao], 'sistema');
    return ['ok' => true, 'mensagem' => 'Instalado: ' . $destino . ' (' . $tipo . ', edição de ' . $edicao . ')', 'tipo' => $tipo, 'edicao' => $edicao];
}

/**
 * Tarefa automática (de 5 em 5 minutos, ferramentas/actualizar.php urgente): instala a base de dados quando falta e
 * renova-a todos os meses (com mais de 32 dias), de madrugada; uma tentativa por dia (com a base em falta, a cada
 * 6 horas). GEOIP_AUTOMATICO=0 desliga. Devolve o resultado ou null quando não havia nada a fazer.
 */
function naval_geoip_manter(?int $agora = null): ?array
{
    $agora = $agora ?? time();
    if (naval_config('GEOIP_AUTOMATICO', '1') === '0') return null;
    $f = naval_geoip_ficheiro();
    $existe = is_file($f);
    if ($existe && $agora - (int) filemtime($f) < 32 * 86400) return null;
    if ($existe && !(date('H', $agora) >= '03' && date('H', $agora) < '06')) return null;
    $marca = naval_pasta('geoip-tentativas') . '/ultima.json';
    $ultima = (int) (naval_json_ler($marca)['quando'] ?? 0);
    if ($agora - $ultima < ($existe ? 20 : 6) * 3600) return null;
    naval_json_gravar($marca, ['quando' => $agora], false);
    $r = naval_geoip_instalar();
    if (!$r['ok']) error_log('[geoip] ' . $r['mensagem']);
    return $r;
}
