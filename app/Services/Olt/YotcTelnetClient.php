<?php

namespace App\Services\Olt;

use App\Models\Olt;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;

/** Closed command surface: session setup and verified PON/cpu reads only. */
class YotcTelnetClient
{
    private const PROMPT = '/(?:^|\n)[\w.\/:\-]{1,100}(?:\([\w.\/:\- ]{1,120}\))?[#>]\h*$/';
    private $stream = null;
    private ?Lock $sessionLock = null;
    private float $expiresAt = 0;
    private float $commandTimeout = 5;
    private string $iacState = 'data';
    private int $iacCommand = 0;
    private array $secrets = [];
    private array $ports = [];
    private bool $authenticated = false;
    private bool $configContext = false;
    private string $pendingProtocolTail = '';
    private int $negotiationGeneration = 0;

    public function connect(Olt $olt): void
    {
        $this->close();
        $address = (string) $olt->ip_address;
        $port = (int) ($olt->traffic_telnet_port ?: 23);
        $user = (string) $olt->traffic_telnet_user;
        $password = (string) $olt->traffic_telnet_pass_enc;
        $enable = (string) ($olt->traffic_enable_pass_enc ?: $password);
        $ports = $olt->traffic_pon_ports;
        if ($olt->chassis_type !== 'C1600' || $olt->connection_mode !== 'real' || ! $olt->traffic_monitoring_enabled
            || ! $this->allowedAddress($address) || $port < 1 || $port > 65535 || ! is_array($ports)
            || count($ports) < 1 || count($ports) > 4 || count(array_unique($ports)) !== count($ports)) {
            throw new OltConnectionException('Konfigurasi traffic Telnet YOTC belum valid; gunakan IP jaringan internal dan PON yang dipilih.', 'configuration');
        }
        foreach ($ports as $pon) if (! is_string($pon) || ! preg_match('/^[1-9]\d?\/[1-9]\d?$/D', $pon)) {
            throw new OltConnectionException('Alamat PON YOTC tidak valid.', 'configuration');
        }
        if ($password === '' || preg_match('/[\x00-\x1f\x7f\xff]/', $user.$password.$enable)) {
            throw new OltConnectionException('Kredensial traffic Telnet YOTC belum valid.', 'configuration');
        }
        $cooldownKey = $this->authenticationCooldownKey($olt);
        if (Cache::has($cooldownKey)) {
            // Do not extend the deadline or open a socket on repeated polling requests.
            throw new OltConnectionException('Login traffic Telnet YOTC dijeda 10 menit setelah ditolak perangkat. Periksa kredensial sebelum mencoba lagi.', 'authentication_failed');
        }
        $operation = max(1, min(20, (float) config('olt.operation_timeout', 20)));
        $this->expiresAt = microtime(true) + $operation;
        $this->commandTimeout = max(0.1, min(8, (float) config('olt.command_timeout', 5)));
        $this->iacState = 'data';
        $this->ports = $ports;
        $this->secrets = array_values(array_filter([$password, $enable, (string) $olt->telnet_pass_enc, (string) $olt->snmp_community_enc]));
        $lock = Cache::lock('nms:yotc-host:'.hash('sha256', inet_pton($address)), (int) ceil(max($operation, (float) config('olt.operation_timeout', 20))) + 30);
        if (! $lock->get()) throw new OltConnectionException('Sesi YOTC sedang digunakan. Coba lagi setelah pembacaan selesai.', 'read_busy');
        $this->sessionLock = $lock;
        try {
            $host = str_contains($address, ':') ? '['.$address.']' : $address;
            $this->stream = @stream_socket_client('tcp://'.$host.':'.$port, $errno, $error, min($operation, max(0.1, (float) ($olt->connection_timeout ?: 5))));
            if (! is_resource($this->stream)) throw new OltConnectionException('Tidak dapat menghubungi Telnet traffic YOTC. Periksa rute, port, dan ACL.', 'connect_failed');
            stream_set_blocking($this->stream, false);
            $login = $this->readChallenge('/(?:username|login|password)\h*:\h*$/i');
            if (preg_match('/(?:username|login)\h*:\h*$/i', $login)) {
                if ($user === '') throw new OltConnectionException('Perangkat meminta username Telnet traffic.', 'authentication_failed');
                $this->write($user."\r\n");
                $this->readChallenge('/password\h*:\h*$/i');
            }
            // One password attempt only. Never retry a rejected login or enable password.
            $this->write($password."\r\n");
            $prompt = $this->readUntil(self::PROMPT, true);
            $this->authenticated = true;
            $this->configContext = (bool) preg_match('/\(config\)#\h*$/', $prompt);
            if (preg_match('/>\h*$/', $prompt)) {
                $prompt = $this->exchange('enable', '/password\h*:\h*$|'.substr(self::PROMPT, 1, -1).'/i');
                if (preg_match('/password\h*:\h*$/i', $prompt)) {
                    $this->write($enable."\r\n");
                    $prompt = $this->readUntil(self::PROMPT, true);
                }
            }
            if (! preg_match('/#\h*$/', $prompt)) throw new OltConnectionException('Hak baca privileged Telnet YOTC tidak tersedia.', 'authentication_failed');
            if (! preg_match('/\(config\)#\h*$/', $prompt)) {
                $prompt = $this->exchange('configure terminal');
                if (! preg_match('/\(config\)#\h*$/', $prompt)) throw new OltConnectionException('Konteks pembacaan traffic YOTC tidak tersedia.', 'command_rejected');
            }
            $this->configContext = true;
            Cache::forget($cooldownKey);
        } catch (\Throwable $error) {
            // Never send logout commands into a password prompt after rejected authentication.
            if ($error instanceof OltConnectionException && $error->reason === 'authentication_failed') {
                $this->authenticated = false;
                try { $this->pauseAuthentication($olt); } catch (\Throwable) {
                    // A cache outage must not retain the socket/lease or replace the authentication error.
                }
            }
            $this->close();
            if ($error instanceof OltConnectionException) throw $error;
            throw new OltConnectionException('Koneksi Telnet traffic YOTC gagal.', 'connect_failed');
        }
    }

    public function readPort(string $port): string
    {
        if (! in_array($port, $this->ports, true) || ! preg_match('/^[1-9]\d?\/[1-9]\d?$/D', $port)) {
            throw new OltConnectionException('PON belum dipilih untuk monitoring traffic YOTC.', 'configuration');
        }
        return $this->readCommand('show perf current '.$port);
    }

    public function readCpuStatistics(): string { return $this->readCommand('show cpu statistics'); }

    private function readCommand(string $command): string
    {
        try {
            $output = preg_replace(self::PROMPT, '', $this->exchange($command));
            $output = preg_replace('/\A\s*'.preg_quote($command, '/').'\h*\n/', '', $output);
            return trim($this->redact($output));
        } catch (\Throwable $error) {
            $this->close();
            if ($error instanceof OltConnectionException) throw $error;
            throw new OltConnectionException('Pembacaan Telnet traffic YOTC gagal.', 'connection_failed');
        }
    }

    private function exchange(string $command, string $pattern = self::PROMPT): string
    {
        if (! in_array($command, ['enable','configure terminal','show cpu statistics'], true)
            && (! preg_match('/^show perf current ([1-9]\d?\/[1-9]\d?)$/D', $command, $match) || ! in_array($match[1], $this->ports, true))) {
            throw new OltConnectionException('Perintah traffic YOTC tidak diizinkan.', 'unsupported_operation');
        }
        $this->write($command."\r\n");
        $deadline = min($this->expiresAt, microtime(true) + $this->commandTimeout);
        $raw = '';
        $output = $this->readUntil($pattern, false, $deadline, $raw);
        if (preg_match('/password\h*:\h*$/i', $output)) $output = $this->settleChallenge($raw, $pattern, $deadline);
        if (preg_match('/(?:^|\n)\h*(?:%\h*(?:error|invalid|unknown)|error\h*:|invalid (?:input|command)|unknown command|command not found|permission denied|access denied)/i', $output)) {
            throw new OltConnectionException('Perintah baca traffic YOTC ditolak perangkat.', 'command_rejected');
        }
        return $output;
    }

    private function readChallenge(string $pattern): string
    {
        $deadline = min($this->expiresAt, microtime(true) + $this->commandTimeout);
        $raw = '';
        $this->readUntil($pattern, false, $deadline, $raw);
        return $this->settleChallenge($raw, $pattern, $deadline);
    }

    /** Finish late prompt redraws/IAC negotiation before the single credential write. */
    private function settleChallenge(string $raw, string $pattern, float $deadline): string
    {
        // The caller transfers its complete raw response, including the unfinished log tail.
        $this->pendingProtocolTail = '';
        $buffer = $this->protocolVisible($raw);
        $quietDeadline = min($deadline, microtime(true) + 0.25);
        $bytes = strlen($raw);
        $limit = max(1024, min(4 * 1024 * 1024, (int) config('olt.max_output_bytes', 4 * 1024 * 1024)));
        while (true) {
            try {
                $this->wait(false, $quietDeadline);
            } catch (OltConnectionException $error) {
                if ($error->reason !== 'timeout' || $quietDeadline >= $deadline) throw $error;
                if (! preg_match($pattern, $buffer)) throw new OltConnectionException('Prompt autentikasi YOTC belum stabil; kredensial tidak dikirim.', 'connection_failed');
                $this->rememberProtocolTail($raw);
                return $buffer;
            }
            $chunk = @fread($this->stream, 8192);
            if ($chunk === false || ($chunk === '' && feof($this->stream))) throw new OltConnectionException('Sesi Telnet YOTC terputus sebelum pengiriman kredensial.', 'disconnected');
            $bytes += strlen($chunk);
            if ($bytes > $limit) throw new OltConnectionException('Prompt Telnet YOTC melebihi batas ukuran.', 'output_limit');
            $generation = $this->negotiationGeneration;
            $raw .= $this->decodeTelnet($chunk);
            $visible = $this->protocolVisible($raw);
            // Asynchronous DEBUG output must not keep a valid challenge perpetually busy.
            if ($visible !== $buffer || $generation !== $this->negotiationGeneration) $quietDeadline = min($deadline, microtime(true) + 0.25);
            $buffer = $visible;
        }
    }

    private function readUntil(string $pattern, bool $authentication = false, ?float $absoluteDeadline = null, ?string &$rawOutput = null): string
    {
        $raw = $this->pendingProtocolTail; $this->pendingProtocolTail = '';
        $buffer = ''; $bytes = strlen($raw); $handledPagers = [];
        $deadline = min($this->expiresAt, $absoluteDeadline ?? INF, microtime(true) + $this->commandTimeout);
        $authenticationDeadline = $deadline;
        $authenticationPromptPending = false;
        $authenticationQuietDeadline = $deadline;
        $limit = max(1024, min(4 * 1024 * 1024, (int) config('olt.max_output_bytes', 4 * 1024 * 1024)));
        while (true) {
            try {
                $this->wait(false, $authenticationPromptPending ? min($authenticationDeadline, $authenticationQuietDeadline) : $deadline);
            } catch (OltConnectionException $error) {
                if ($authenticationPromptPending && $error->reason === 'timeout') $this->authenticationRejected();
                throw $error;
            }
            $chunk = @fread($this->stream, 8192);
            if ($chunk === false || ($chunk === '' && feof($this->stream))) {
                if ($authenticationPromptPending) $this->authenticationRejected();
                throw new OltConnectionException('Sesi Telnet YOTC terputus sebelum respons lengkap.', 'disconnected');
            }
            $bytes += strlen($chunk);
            if ($bytes > $limit) throw new OltConnectionException('Output Telnet YOTC melebihi batas ukuran.', 'output_limit');
            if ($chunk !== '' && ! $authentication) $deadline = min($this->expiresAt, $absoluteDeadline ?? INF, microtime(true) + $this->commandTimeout);
            $generation = $this->negotiationGeneration;
            $previousVisible = $buffer;
            $raw .= $this->decodeTelnet($chunk);
            $rawOutput = $raw;
            $buffer = $this->protocolVisible($raw);
            if ($authentication) {
                // C1600 can redraw its initial Password prompt in a late packet after the
                // one password write. A valid hostname prompt proves that login completed.
                if (preg_match($pattern, $buffer)) { $this->rememberProtocolTail($raw); return $this->withoutPagers($buffer); }
                if (preg_match('/^\h*(?:%\h*)?(?:authentication failed|login incorrect|login failed|access denied|(?:bad|invalid|incorrect|wrong) password)\h*[.!]?\h*$/mi', $buffer)) {
                    $this->authenticationRejected();
                }
                if (preg_match('/(?:username|login|password)\h*:\h*$/i', $buffer)) $authenticationPromptPending = true;
                if ($authenticationPromptPending && ($buffer !== $previousVisible || $generation !== $this->negotiationGeneration)) {
                    // Send nothing more. Quiet grace is bounded by the ORIGINAL login deadline.
                    $authenticationQuietDeadline = min($authenticationDeadline, microtime(true) + 1);
                }
            }
            if (preg_match('/--+\h*More\h*--+\h*$/i', $buffer)) {
                $page = hash('sha256', $buffer);
                if (! isset($handledPagers[$page])) {
                    if (count($handledPagers) >= 64) throw new OltConnectionException('Jumlah halaman Telnet YOTC melebihi batas.', 'output_limit');
                    $handledPagers[$page] = true;
                    $this->write(' ');
                }
            }
            if (preg_match($pattern, $buffer)) { $this->rememberProtocolTail($raw); return $this->withoutPagers($buffer); }
        }
    }

    private function authenticationRejected(): never
    {
        throw new OltConnectionException('Login atau enable Telnet traffic YOTC ditolak perangkat.', 'authentication_failed');
    }

    private function wait(bool $writing, float $deadline): void
    {
        if (! is_resource($this->stream)) throw new OltConnectionException('Sesi Telnet traffic YOTC belum terhubung.', 'disconnected');
        $remaining = min($deadline, $this->expiresAt) - microtime(true);
        if ($remaining <= 0) throw new OltConnectionException('Timeout pembacaan traffic YOTC; data terakhir dipertahankan.', 'timeout');
        $read = $writing ? [] : [$this->stream]; $write = $writing ? [$this->stream] : []; $except = [];
        $seconds = (int) floor($remaining);
        $ready = @stream_select($read, $write, $except, $seconds, (int) (($remaining-$seconds) * 1_000_000));
        if ($ready === false) throw new OltConnectionException('Socket Telnet traffic YOTC gagal.', 'socket_error');
        if ($ready === 0) throw new OltConnectionException('Timeout pembacaan traffic YOTC; data terakhir dipertahankan.', 'timeout');
    }

    private function write(string $data): void
    {
        $deadline = min($this->expiresAt, microtime(true) + $this->commandTimeout);
        while ($data !== '') {
            $this->wait(true, $deadline);
            $written = @fwrite($this->stream, $data);
            if (! $written) throw new OltConnectionException('Pengiriman Telnet traffic YOTC terputus.', 'disconnected');
            $data = substr($data, $written);
        }
    }

    private function decodeTelnet(string $bytes): string
    {
        $plain = '';
        foreach (str_split($bytes) as $byte) {
            $n = ord($byte);
            switch ($this->iacState) {
                case 'data': if ($n === 255) $this->iacState = 'iac'; else $plain .= $byte; break;
                case 'iac':
                    if ($n === 255) { $plain .= $byte; $this->iacState = 'data'; }
                    elseif (in_array($n, [251,252,253,254], true)) { $this->iacCommand = $n; $this->iacState = 'option'; }
                    else $this->iacState = $n === 250 ? 'sub' : 'data';
                    break;
                case 'option':
                    $this->negotiationGeneration++;
                    if ($this->iacCommand === 251) $this->write(chr(255).chr(in_array($n, [1,3], true) ? 253 : 254).$byte);
                    elseif ($this->iacCommand === 253) $this->write(chr(255).chr(252).$byte);
                    $this->iacState = 'data'; break;
                case 'sub': if ($n === 255) $this->iacState = 'sub_iac'; break;
                case 'sub_iac': if ($n === 240) $this->negotiationGeneration++; $this->iacState = $n === 240 ? 'data' : 'sub'; break;
            }
        }
        return $plain;
    }

    private static function normalize(string $text): string
    {
        $text = preg_replace('/\x1b\][^\x07\x1b]*(?:\x07|\x1b\\\\)|\x1b\[[0-?]*[ -\/]*[@-~]/', '', $text);
        $text = str_replace(["\r\n","\r\0","\r","\0"], ["\n",'',"\n",''], $text);
        while (str_contains($text, "\x08")) $text = preg_replace('/[^\n]?\x08/', '', $text);
        return preg_replace('/[\x01-\x07\x0b\x0c\x0e-\x1a\x1c-\x1f\x7f]/', '', $text);
    }

    private function protocolVisible(string $raw): string
    {
        $text = self::normalize($raw);
        // Only the observed own-line, timestamped module DEBUG format is asynchronous noise.
        $text = preg_replace('/^\d{4}\/\d{2}\/\d{2} \d{2}:\d{2}:\d{2}\h+\[[A-Za-z0-9_.:-]{1,32}\]\h+\[DEBUG\](?:\h+[^\n]*)?(?:\n|$)/m', '', $text);
        $last = strrpos($text, "\n"); $tail = $last === false ? $text : substr($text, $last+1);
        if ($this->partialDebugPrefix($tail)) $text = $last === false ? '' : substr($text, 0, $last+1);
        return rtrim($text);
    }

    private function partialDebugPrefix(string $line): bool
    {
        if ($line === '') return false;
        $format = '0000/00/00 00:00:00'; $length = strlen($line);
        for ($i=0; $i<min(19, $length); $i++) {
            if ($format[$i] === '0' ? ! ctype_digit($line[$i]) : $format[$i] !== $line[$i]) return false;
        }
        if ($length <= 19) return true;
        $rest = substr($line, 19); $spaces = strspn($rest, " \t");
        if (! $spaces) return false;
        $rest = substr($rest, $spaces);
        if ($rest === '') return true;
        if ($rest[0] !== '[') return false;
        $rest = substr($rest, 1);
        if ($rest === '') return true;
        preg_match('/^[A-Za-z0-9_.:-]{0,32}/', $rest, $module); $count = strlen($module[0]);
        if ($count === strlen($rest)) return true;
        if (! $count || $rest[$count] !== ']') return false;
        $rest = substr($rest, $count+1); $spaces = strspn($rest, " \t");
        if ($rest === '') return true;
        if (! $spaces) return false;
        $rest = substr($rest, $spaces);
        return strlen($rest) <= 7 && str_starts_with('[DEBUG]', $rest);
    }

    private function rememberProtocolTail(string $raw): void
    {
        $normalized = self::normalize($raw); $last = strrpos($normalized, "\n");
        $tail = $last === false ? $normalized : substr($normalized, $last+1);
        if ($this->partialDebugPrefix($tail) || preg_match('/^\d{4}\/\d{2}\/\d{2} \d{2}:\d{2}:\d{2}\h+\[[A-Za-z0-9_.:-]{1,32}\]\h+\[DEBUG\](?:\h+[^\n]*)?$/D', $tail)) {
            $rawLast = strrpos($raw, "\n"); $this->pendingProtocolTail = $rawLast === false ? $raw : substr($raw, $rawLast+1);
        } else $this->pendingProtocolTail = '';
    }

    private function withoutPagers(string $text): string { return trim(preg_replace('/--+\h*More\h*--+/i', '', $text)); }

    private function redact(string $output): string { return $this->secrets ? str_replace($this->secrets, '[REDACTED]', $output) : $output; }

    /** Pause a known rejected credential profile without another device login attempt. */
    public function pauseAuthentication(Olt $olt): void
    {
        Cache::put($this->authenticationCooldownKey($olt), true, 600);
    }

    private function authenticationCooldownKey(Olt $olt): string
    {
        $address = (string) $olt->ip_address;
        $port = (int) ($olt->traffic_telnet_port ?: 23);
        if (! $this->allowedAddress($address) || $port < 1 || $port > 65535) {
            throw new OltConnectionException('Alamat atau port Telnet traffic YOTC tidak valid.', 'configuration');
        }
        $attributes = $olt->getAttributes();
        $credentials = hash('sha256', (string) $olt->traffic_telnet_user."\0".
            (string) ($attributes['traffic_telnet_pass_enc'] ?? '')."\0".(string) ($attributes['traffic_enable_pass_enc'] ?? ''));
        return 'nms:yotc-auth-cooldown:'.hash('sha256', inet_pton($address).'|'.$port.'|'.$credentials);
    }

    private function allowedAddress(string $address): bool
    {
        if (! filter_var($address, FILTER_VALIDATE_IP)) return false;
        $binary = inet_pton($address);
        if (strlen($binary) === 4) {
            $a = ord($binary[0]); $b = ord($binary[1]);
            return $a === 10 || $a === 127 || ($a === 172 && $b >= 16 && $b <= 31) || ($a === 192 && $b === 168);
        }
        $hex = bin2hex($binary);
        return $hex !== 'fd000ec2000000000000000000000254' && ($hex === str_repeat('0',31).'1' || (ord($binary[0]) & 0xfe) === 0xfc);
    }

    public function close(): void
    {
        try {
            if (is_resource($this->stream) && $this->authenticated) {
                $this->authenticated = false;
                // Some embedded VTY servers retain sessions after a bare TCP close.
                // These fixed session commands never save or change device configuration.
                $this->expiresAt = microtime(true) + 0.25;
                $this->commandTimeout = 0.25;
                try {
                    if ($this->configContext) $this->write("end\r\n");
                    $this->write("exit\r\n");
                    $bytes = 0;
                    while (microtime(true) < $this->expiresAt && $bytes < 65536) {
                        $this->wait(false, $this->expiresAt);
                        $chunk = @fread($this->stream, 8192);
                        if ($chunk === false || ($chunk === '' && feof($this->stream))) break;
                        $bytes += strlen($chunk);
                        $this->decodeTelnet($chunk);
                    }
                } catch (\Throwable) {
                    // Logout is best effort and must never replace a read/authentication error.
                }
            }
        } finally {
            if (is_resource($this->stream)) @fclose($this->stream);
            $this->stream = null; $this->ports = []; $this->secrets = [];
            $this->authenticated = false; $this->configContext = false;
            $this->pendingProtocolTail = '';
            $lock = $this->sessionLock; $this->sessionLock = null; $lock?->release();
        }
    }

    public function __destruct() { $this->close(); }
}
