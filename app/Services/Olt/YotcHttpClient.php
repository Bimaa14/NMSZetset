<?php

namespace App\Services\Olt;

use App\Models\Olt;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Psr7\Request;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\{Cache, Http};

/** C1600 HTTP session with a closed set of verified monitoring endpoints. */
class YotcHttpClient
{
    private const READ_PATHS = ['/action/getSystemInformation','/action/getCpuMemoryTemp','/action/getSlotInfo',
        '/action/getPonPortInfoAll','/action/getAuthOnuAll','/action/getOnuOpticalParam'];

    private ?CookieJar $cookies = null;
    private string $baseUrl = '';
    private bool $authenticated = false;
    private int $timeout = 5;
    private ?Lock $sessionLock = null;

    public function connect(Olt $olt): void
    {
        $this->close();
        $scheme = (string) $olt->cli_transport;
        $address = (string) $olt->ip_address;
        $port = (int) $olt->telnet_port;
        if (! in_array($scheme, ['http','https'], true) || ! $this->allowedAddress($address, $scheme === 'http') || $port < 1 || $port > 65535) {
            throw new OltConnectionException('Alamat atau transport web YOTC tidak diizinkan. HTTP hanya untuk IP jaringan internal.', 'configuration');
        }
        if (! $olt->telnet_user || ! $olt->telnet_pass_enc) {
            throw new OltConnectionException('Username dan password web YOTC diperlukan.', 'configuration');
        }
        // C1600's web session capacity is shared by saved OLTs and unsaved probes.
        $lock = Cache::lock('nms:yotc-host:'.hash('sha256', inet_pton($address)), max(1, (int) config('olt.operation_timeout', 300)) + 30);
        abort_unless($lock->get(), 409, 'Sesi web YOTC sedang digunakan. Coba lagi setelah pembacaan selesai.');
        $this->sessionLock = $lock;
        $host = str_contains($address, ':') ? '['.$address.']' : $address;
        $this->baseUrl = $scheme.'://'.$host.':'.$port;
        $this->timeout = max(1, min(30, (int) ($olt->connection_timeout ?: 5)));
        $this->cookies = new CookieJar();
        try {
            $this->request('POST', '/action/login', ['username'=>(string) $olt->telnet_user, 'password'=>(string) $olt->telnet_pass_enc]);
            if (! count($this->cookies)) {
                throw new OltConnectionException('Login web YOTC gagal; sesi tidak diberikan perangkat.', 'authentication_failed');
            }
            $this->authenticated = true;
        } catch (\Throwable $error) {
            $this->cookies = null; $this->baseUrl = ''; $this->authenticated = false;
            $this->releaseLock();
            throw $error;
        }
    }

    public function read(string $path, array $parameters = []): array
    {
        if (! in_array($path, self::READ_PATHS, true)) {
            throw new OltConnectionException('Endpoint web YOTC tidak diizinkan.', 'unsupported_operation');
        }
        if ($path === '/action/getOnuOpticalParam') {
            $limits = ['slotId'=>16,'ponId'=>32,'onuId'=>256];
            if (array_diff(array_keys($parameters), array_keys($limits)) || count($parameters) !== 3) $this->invalidParameters();
            foreach ($limits as $name => $max) {
                $value = $parameters[$name] ?? null;
                if ((! is_int($value) && ! is_string($value)) || ! preg_match('/^\d+$/D', (string) $value) || (int) $value < 1 || (int) $value > $max) $this->invalidParameters();
                $parameters[$name] = (int) $value;
            }
        } elseif ($parameters) $this->invalidParameters();
        if (! $this->authenticated) throw new OltConnectionException('Sesi web YOTC belum login.', 'authentication_failed');
        return $this->request('GET', $path, $parameters);
    }

    public function close(): void
    {
        try {
            if ($this->authenticated) $this->request('POST', '/action/logout');
        } catch (\Throwable) {
            // Logout redirects to the login page on this firmware. Never mask a read error.
        } finally {
            $this->authenticated = false; $this->cookies = null; $this->baseUrl = '';
            $this->releaseLock();
        }
    }

    private function releaseLock(): void
    {
        $lock = $this->sessionLock;
        $this->sessionLock = null;
        $lock?->release();
    }

    private function request(string $method, string $path, array $parameters = []): array
    {
        $limit = max(1024, min(8 * 1024 * 1024, (int) config('olt.max_output_bytes', 4 * 1024 * 1024)));
        $stream = null;
        try {
            $request = Http::acceptJson()->withHeaders(['ClientCallMode'=>'ajax'])
                ->connectTimeout($this->timeout)->timeout($this->timeout)
                ->withOptions(['allow_redirects'=>false, 'proxy'=>'', 'verify'=>true, 'cookies'=>$this->cookies, 'stream'=>true,
                    'on_headers'=>function ($response) use ($limit, &$stream) {
                        // Keep ownership even when the transport rejects headers before returning a response.
                        $stream = $response->getBody();
                        if ((int) $response->getHeaderLine('Content-Length') > $limit) {
                            throw new OltConnectionException('Respons web YOTC melebihi batas ukuran.', 'output_limit');
                        }
                    }]);
            $url = $this->baseUrl.$path;
            $response = $method === 'POST' ? $request->asForm()->post($url, $parameters) : $request->get($url, $parameters);
            $psr = $response->toPsrResponse();
            $stream = $psr->getBody();
            $this->cookies?->extractCookies(new Request($method, $url), $psr);
            if ($path === '/action/logout' && in_array($response->status(), [200,302], true)) return [];
            if (in_array($response->status(), [401,403], true) || ($response->redirect() && $path === '/action/login')) {
                throw new OltConnectionException('Login atau sesi web YOTC ditolak perangkat.', 'authentication_failed');
            }
            if ($response->redirect()) throw new OltConnectionException('Redirect web YOTC ditolak; periksa sesi dan alamat perangkat.', 'redirect_refused');
            if ($response->status() !== 200) throw new OltConnectionException('Permintaan baca web YOTC gagal (HTTP '.$response->status().').', 'http_error');
            if ((int) $psr->getHeaderLine('Content-Length') > $limit) throw new OltConnectionException('Respons web YOTC melebihi batas ukuran.', 'output_limit');
            $body = '';
            while (! $stream->eof()) {
                $chunk = $stream->read(min(8192, $limit + 1 - strlen($body)));
                if ($chunk === '' && ! $stream->eof()) throw new OltConnectionException('Respons web YOTC terhenti sebelum selesai.', 'http_connection');
                $body .= $chunk;
                if (strlen($body) > $limit) throw new OltConnectionException('Respons web YOTC melebihi batas ukuran.', 'output_limit');
            }
            if ($path === '/action/login') {
                if (trim($body) !== '') throw new OltConnectionException('Login web YOTC tidak diterima perangkat.', 'authentication_failed');
                return [];
            }
            $data = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
            if (! is_array($data) || array_is_list($data)) throw new OltConnectionException('Format respons web YOTC belum dikenali.', 'parse_failed');
            return $data;
        } catch (OltConnectionException $error) {
            throw $error;
        } catch (\JsonException) {
            throw new OltConnectionException('Respons web YOTC bukan JSON yang valid; periksa sesi perangkat.', 'parse_failed');
        } catch (\Throwable) {
            throw new OltConnectionException('Koneksi web YOTC gagal, timeout, atau sertifikat HTTPS tidak valid.', 'http_connection');
        } finally {
            // Streaming bodies own sockets; EOF and logout's early return do not release them.
            try { $stream?->close(); } catch (\Throwable) { /* Preserve the original response/error. */ }
        }
    }

    private function invalidParameters(): never
    {
        throw new OltConnectionException('Alamat optik ONU YOTC tidak valid.', 'configuration');
    }

    private function allowedAddress(string $address, bool $privateOnly): bool
    {
        if (! filter_var($address, FILTER_VALIDATE_IP)) return false;
        $binary = inet_pton($address);
        if (strlen($binary) === 4) {
            $a = ord($binary[0]); $b = ord($binary[1]);
            if ($a === 0 || $a >= 224 || ($a === 169 && $b === 254) || $address === '100.100.100.200') return false;
            return ! $privateOnly || $a === 10 || $a === 127 || ($a === 172 && $b >= 16 && $b <= 31) || ($a === 192 && $b === 168);
        }
        $hex = bin2hex($binary);
        // Reject unspecified, multicast, link-local and IPv4-mapped IPv6 addresses.
        if ($hex === str_repeat('0', 32) || $hex === 'fd000ec2000000000000000000000254' || str_starts_with($hex, 'ff') || (ord($binary[0]) === 0xfe && (ord($binary[1]) & 0xc0) === 0x80) || str_starts_with($hex, '00000000000000000000ffff')) return false;
        return ! $privateOnly || $hex === str_repeat('0',31).'1' || (ord($binary[0]) & 0xfe) === 0xfc;
    }
}
