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

/**
 * Localização do IP: ['pais' => 'AO', 'pais_nome', 'regiao', 'cidade', 'lat', 'lon', 'raio_km', 'fonte'] ou
 * ['rede' => 'interna'] para endereços privados; [] se não houver base de dados ou registo.
 * Nomes em português quando a base os tem (senão em inglês).
 */
function naval_geoip(string $ip): array
{
    static $bd = false, $cache = [];
    if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) return [];
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
