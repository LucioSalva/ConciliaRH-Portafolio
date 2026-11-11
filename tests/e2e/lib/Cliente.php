<?php
/**
 * Biblioteca común de las pruebas de la Release Candidate (tests/e2e/*.php): cliente HTTP con sesión y token CSRF, registro
 * de comprobaciones, opciones de línea de órdenes y ejecución de procesos sin intérprete de órdenes.
 *
 * Sin dependencias de Composer. Usa ext-curl si está disponible y, si no (p. ej. dentro del servidor de prueba, que sólo
 * tiene los paquetes de docs/DEPLOYMENT.md), un cliente HTTP/1.1 propio sobre sockets TLS de PHP (funciona con allow_url_fopen=0).
 */

declare(strict_types=1);

final class Cliente
{
    public string $token = '';
    public string $base;
    /** @var array<string,string> */
    private array $cookies = [];
    /** @var array<int,list<string>> */
    private array $crudos = [];

    public function __construct(string $base, private readonly bool $insecure)
    {
        $this->base = rtrim($base, '/');
    }

    /**
     * @param array<string,string> $headers
     * @return array{0:int,1:array<string,string>,2:string}
     */
    public function req(string $method, string $path, ?string $data = null, array $headers = []): array
    {
        if (function_exists('curl_init')) {
            $ch = $this->handle($method, $path, $data, $headers);
            $body = curl_exec($ch);
            return $this->terminar($ch, is_string($body) ? $body : '');
        }
        return $this->porFlujo($method, $path, $data, $headers);
    }

    /** @param array<string,string> $headers */
    public function handle(string $method, string $path, ?string $data = null, array $headers = []): \CurlHandle
    {
        $ch = curl_init($this->base . $path);
        $raw = [];
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => $this->lineas($headers),
            CURLOPT_SSL_VERIFYPEER => !$this->insecure,
            CURLOPT_SSL_VERIFYHOST => $this->insecure ? 0 : 2,
            CURLOPT_HEADERFUNCTION => static function ($c, string $linea) use (&$raw): int {
                $raw[] = $linea;
                return strlen($linea);
            },
        ]);
        if ($data !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        }
        $this->crudos[spl_object_id($ch)] = &$raw;
        return $ch;
    }

    /** @return array{0:int,1:array<string,string>,2:string} */
    public function terminar(\CurlHandle $ch, string $body): array
    {
        $id = spl_object_id($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $raw = $this->crudos[$id] ?? [];
        unset($this->crudos[$id]);
        return $this->procesar($status, $raw, $body);
    }

    /**
     * @param array<string,string> $headers
     * @return array{0:int,1:array<string,string>,2:string}
     */
    private function porFlujo(string $method, string $path, ?string $data, array $headers): array
    {
        $u = parse_url($this->base);
        $tls = ($u['scheme'] ?? 'http') === 'https';
        $host = (string) ($u['host'] ?? '127.0.0.1');
        $port = (int) ($u['port'] ?? ($tls ? 443 : 80));
        $ctx = stream_context_create(['ssl' => ['verify_peer' => !$this->insecure, 'verify_peer_name' => !$this->insecure,
                                                'allow_self_signed' => $this->insecure, 'peer_name' => $host]]);
        $fp = @stream_socket_client(($tls ? 'tls' : 'tcp') . "://$host:$port", $errno, $errstr, 60, STREAM_CLIENT_CONNECT, $ctx);
        if ($fp === false) {
            return [0, [], ''];
        }
        stream_set_timeout($fp, 60);
        $lineas = [...$this->lineas($headers), 'Host: ' . $host . (isset($u['port']) ? ":$port" : ''), 'Connection: close'];
        if ($data !== null) {
            $lineas[] = 'Content-Length: ' . strlen($data);
        }
        fwrite($fp, "$method " . rtrim((string) ($u['path'] ?? ''), '/') . "$path HTTP/1.1\r\n" . implode("\r\n", $lineas) . "\r\n\r\n" . ($data ?? ''));
        $resp = stream_get_contents($fp);
        fclose($fp);
        $p = strpos((string) $resp, "\r\n\r\n");
        if ($resp === false || $p === false) {
            return [0, [], ''];
        }
        $raw = explode("\r\n", substr($resp, 0, $p));
        $body = substr($resp, $p + 4);
        $status = preg_match('#^HTTP/\S+\s+(\d{3})#', $raw[0], $m) ? (int) $m[1] : 0;
        if (preg_grep('/^transfer-encoding:\s*chunked/i', $raw)) {
            $plano = '';
            while (($q = strpos($body, "\r\n")) !== false) {
                $n = hexdec(trim(explode(';', substr($body, 0, $q))[0]));
                if ($n === 0) {
                    break;
                }
                $plano .= substr($body, $q + 2, (int) $n);
                $body = substr($body, $q + 2 + (int) $n + 2);
            }
            $body = $plano;
        }
        return $this->procesar($status, array_slice($raw, 1), $body);
    }

    /**
     * @param list<string> $raw
     * @return array{0:int,1:array<string,string>,2:string}
     */
    private function procesar(int $status, array $raw, string $body): array
    {
        $h = [];
        foreach ($raw as $linea) {
            $linea = rtrim($linea, "\r\n");
            if (preg_match('#^HTTP/\S+\s+\d{3}#', $linea)) {
                $h = [];
                continue;
            }
            $p = strpos($linea, ':');
            if ($p === false) {
                continue;
            }
            $k = strtolower(trim(substr($linea, 0, $p)));
            $v = trim(substr($linea, $p + 1));
            $h[$k] = $v;
            if ($k === 'set-cookie') {
                $this->guardarCookie($v);
            }
        }
        if (preg_match('/name="(?:_token|csrf-token)"[^>]*(?:value|content)="([0-9a-f]{64})"/', $body, $m)) {
            $this->token = $m[1];
        }
        return [$status, $h, $body];
    }

    private function guardarCookie(string $v): void
    {
        $partes = array_map('trim', explode(';', $v));
        $par = explode('=', array_shift($partes), 2);
        $nombre = $par[0];
        $valor = $par[1] ?? '';
        $borrar = $valor === '' || $valor === 'deleted';
        foreach ($partes as $a) {
            if (preg_match('/^max-age=(-?\d+)/i', $a, $m) && (int) $m[1] <= 0) {
                $borrar = true;
            }
            if (preg_match('/^expires=(.+)$/i', $a, $m) && ($t = strtotime($m[1])) !== false && $t < time()) {
                $borrar = true;
            }
        }
        if ($borrar) {
            unset($this->cookies[$nombre]);
        } else {
            $this->cookies[$nombre] = $valor;
        }
    }

    /**
     * @param array<string,string> $headers
     * @return list<string>
     */
    private function lineas(array $headers): array
    {
        $out = [];
        foreach ($headers as $k => $v) {
            $out[] = "$k: $v";
        }
        if ($this->cookies !== []) {
            $out[] = 'Cookie: ' . implode('; ', array_map(static fn ($k, $v) => "$k=$v", array_keys($this->cookies), $this->cookies));
        }
        $out[] = 'Expect:';
        return $out;
    }

    /** @return array{0:int,1:array<string,string>,2:string} */
    public function get(string $path): array
    {
        return $this->req('GET', $path);
    }

    /** @return array<string,mixed> */
    public function api(string $path): array
    {
        [$s, , $b] = $this->req('GET', $path, null, ['Accept' => 'application/json']);
        $d = $s === 200 ? json_decode($b, true) : null;
        return is_array($d) ? $d : [];
    }

    /**
     * @param array<string,string|list<string>> $fields
     * @param list<array{0:string,1:string,2:string}>|null $files [campo, nombre de archivo, contenido]
     * @return array{0:int,1:array<string,string>,2:string}
     */
    public function post(string $path, array $fields, ?array $files = null): array
    {
        [$ct, $body] = $this->cuerpo($path, $fields, $files);
        return $this->req('POST', $path, $body, ['Content-Type' => $ct]);
    }

    /**
     * @param array<string,string|list<string>> $fields
     * @param list<array{0:string,1:string,2:string}>|null $files
     * @return array{0:string,1:string}
     */
    public function cuerpo(string $path, array $fields, ?array $files = null): array
    {
        if ($this->token === '') {
            $this->get('/');
        }
        $fields = ['_token' => $this->token, ...$fields];
        if ($files) {
            $bnd = '----crh' . bin2hex(random_bytes(8));
            $body = '';
            foreach ($fields as $k => $v) {
                foreach (is_array($v) ? $v : [$v] as $item) {
                    $body .= "--$bnd\r\nContent-Disposition: form-data; name=\"$k\"\r\n\r\n$item\r\n";
                }
            }
            foreach ($files as [$campo, $nombre, $datos]) {
                $nombre = str_replace(['"', "\r", "\n"], ['%22', '%0D', '%0A'], $nombre);
                $body .= "--$bnd\r\nContent-Disposition: form-data; name=\"$campo\"; filename=\"$nombre\"\r\n"
                    . "Content-Type: application/octet-stream\r\n\r\n" . $datos . "\r\n";
            }
            return ["multipart/form-data; boundary=$bnd", $body . "--$bnd--\r\n"];
        }
        $pares = [];
        foreach ($fields as $k => $v) {
            foreach (is_array($v) ? $v : [$v] as $item) {
                $pares[] = rawurlencode((string) $k) . '=' . rawurlencode((string) $item);
            }
        }
        return ['application/x-www-form-urlencoded', implode('&', $pares)];
    }

    public function login(string $user, string $password): string
    {
        $this->get('/login');
        [$s, $h] = $this->post('/login', ['username' => $user, 'password' => $password]);
        $this->token = '';
        return in_array($s, [302, 303], true) ? ($h['location'] ?? '') : '';
    }
}

final class Resultados
{
    /** @var list<array{0:bool,1:string}> */
    public static array $lista = [];

    public static function ok(): int
    {
        return count(array_filter(self::$lista, static fn ($r) => $r[0]));
    }

    public static function total(): int
    {
        return count(self::$lista);
    }
}

function check(bool $ok, string $text, bool $critico = false): bool
{
    Resultados::$lista[] = [$ok, $text];
    echo ($ok ? '  ✔ ' : '  ✘ ') . $text . "\n";
    if ($critico && !$ok) {
        echo "Paso crítico fallido: la prueba se detiene.\n";
        exit(1);
    }
    return $ok;
}

/** @param array<string,string> $h */
function location_id(array $h, string $prefix): ?int
{
    return preg_match('#' . preg_quote($prefix, '#') . '/(\d+)$#', $h['location'] ?? '', $m) ? (int) $m[1] : null;
}

/** @return list<string> */
function shlex_split(string $s): array
{
    $out = [];
    $cur = '';
    $hay = false;
    $q = null;
    $n = strlen($s);
    for ($i = 0; $i < $n; $i++) {
        $c = $s[$i];
        if ($q === "'") {
            if ($c === "'") {
                $q = null;
            } else {
                $cur .= $c;
            }
        } elseif ($q === '"') {
            if ($c === '"') {
                $q = null;
            } elseif ($c === '\\' && $i + 1 < $n && in_array($s[$i + 1], ['"', '\\', '$', '`'], true)) {
                $cur .= $s[++$i];
            } else {
                $cur .= $c;
            }
        } elseif ($c === "'" || $c === '"') {
            $q = $c;
            $hay = true;
        } elseif ($c === '\\' && $i + 1 < $n) {
            $cur .= $s[++$i];
            $hay = true;
        } elseif (ctype_space($c)) {
            if ($hay || $cur !== '') {
                $out[] = $cur;
            }
            $cur = '';
            $hay = false;
        } else {
            $cur .= $c;
        }
    }
    if ($hay || $cur !== '') {
        $out[] = $cur;
    }
    return $out;
}

/**
 * @param list<string> $cmd
 * @param array<string,string>|null $env
 * @return array{0:int,1:string,2:string}
 */
function ejecutar(array $cmd, string $stdin = '', ?array $env = null, ?string $cwd = null, int $timeout = 600): array
{
    $p = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, $env);
    if (!is_resource($p)) {
        return [127, '', 'no se pudo ejecutar ' . ($cmd[0] ?? '')];
    }
    if ($stdin !== '') {
        fwrite($pipes[0], $stdin);
    }
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $out = $err = '';
    $fin = time() + $timeout;
    while (true) {
        $r = [$pipes[1], $pipes[2]];
        $w = $e = null;
        if (@stream_select($r, $w, $e, 1) > 0) {
            foreach ($r as $fd) {
                $chunk = fread($fd, 65536);
                if ($fd === $pipes[1]) {
                    $out .= (string) $chunk;
                } else {
                    $err .= (string) $chunk;
                }
            }
        }
        if (feof($pipes[1]) && feof($pipes[2])) {
            break;
        }
        if (time() > $fin) {
            proc_terminate($p, 9);
            break;
        }
    }
    fclose($pipes[1]);
    fclose($pipes[2]);
    $rc = proc_close($p);
    return [$rc, $out, $err];
}

/**
 * @param array<string,string|bool|null> $spec  nombre => true (obligatoria), false (bandera) o valor por omisión
 * @return array<string,string|bool>
 */
function opciones(array $spec, string $uso): array
{
    $largas = [];
    foreach ($spec as $k => $v) {
        $largas[] = $v === false ? $k : "$k:";
    }
    $g = getopt('h', [...$largas, 'help']);
    if ($g === false || isset($g['h']) || isset($g['help'])) {
        fwrite(STDOUT, $uso);
        exit(0);
    }
    $out = [];
    $faltan = [];
    foreach ($spec as $k => $v) {
        if ($v === false) {
            $out[$k] = array_key_exists($k, $g);
        } elseif (array_key_exists($k, $g)) {
            $out[$k] = is_array($g[$k]) ? (string) end($g[$k]) : (string) $g[$k];
        } elseif ($v === true) {
            $faltan[] = "--$k";
        } else {
            $out[$k] = (string) $v;
        }
    }
    if ($faltan !== []) {
        fwrite(STDERR, 'Faltan opciones obligatorias: ' . implode(', ', $faltan) . "\n\n" . $uso);
        exit(2);
    }
    return $out;
}

function leer(string $ruta): string
{
    $d = @file_get_contents($ruta);
    if ($d === false) {
        fwrite(STDERR, "No se pudo leer $ruta\n");
        exit(2);
    }
    return $d;
}
