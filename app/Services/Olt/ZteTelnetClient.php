<?php

namespace App\Services\Olt;

use App\Models\Olt;

class ZteTelnetClient implements CliClient
{
    use PreparesZteSession;

    public const PROMPT = '/(?:^|\n)[\w.\/:\-]{1,100}(?:\([\w.\/:\- ]{1,120}\))?[#>]\s*$/';

    public const CONFIRM = '/(?:\[(?:yes\/no|y\/n)\]|\((?:yes\/no|y\/n)\))\h*[:?]?\h*$/i';

    protected $stream = null;

    protected float $expiresAt;

    protected float $commandTimeout;

    private string $iacState = 'data';

    private int $iacCommand = 0;

    private array $secrets = [];

    public function connect(Olt $olt): void
    {
        $this->close();
        $this->expiresAt = microtime(true) + (float) config('olt.operation_timeout', 180);
        $this->commandTimeout = (float) config('olt.command_timeout', 10);
        $this->iacState = 'data';
        if (! filter_var($olt->ip_address, FILTER_VALIDATE_IP)) {
            throw new OltConnectionException('Alamat IP OLT tidak valid.', 'configuration');
        }
        $user = (string) $olt->telnet_user;
        $password = (string) $olt->telnet_pass_enc;
        if ($user === '' || $password === '' || preg_match('/[\x00-\x1f\x7f\xff]/', $user.$password)) {
            throw new OltConnectionException('Username/password koneksi belum valid.', 'configuration');
        }
        $this->secrets = array_filter([$password, (string) $olt->snmp_community_enc], fn ($v) => $v !== '');
        $host = str_contains($olt->ip_address, ':') ? '['.$olt->ip_address.']' : $olt->ip_address;
        $this->stream = @stream_socket_client('tcp://'.$host.':'.$olt->telnet_port, $errno, $error, (float) ($olt->connection_timeout ?: 5));
        if (! is_resource($this->stream)) {
            if (in_array($errno, [60, 110, 10060], true)) {
                throw new OltConnectionException('Timeout koneksi ke OLT. Periksa rute IP, port dan ACL jaringan.', 'timeout');
            }
            throw new OltConnectionException('Tidak dapat menghubungi OLT pada '.$host.':'.$olt->telnet_port.'. Periksa rute, port, ACL, atau timeout koneksi.', 'connect_failed');
        }
        stream_set_blocking($this->stream, false);
        try {
            $this->readUntil('/(?:username|login)\h*:\h*$/i');
            $this->write($user."\r\n");
            $this->readUntil('/password\h*:\h*$/i');
            $this->write($password."\r\n");
            $this->readUntil(self::PROMPT, true);
            $this->prepareSession($password);
        } catch (\Throwable $e) {
            $this->close();
            throw $e;
        }
    }

    public function command(string $command, bool $allowConfirmation = false, ?float $idleTimeout = null, ?float $maxDuration = null, bool $raw = false): string
    {
        if (strlen($command) > 512 || $command === '' || preg_match('/[\x00-\x1f\x7f\xff]/', $command)) {
            throw new OltConnectionException('Kirim satu perintah CLI tanpa karakter kontrol.', 'invalid_command');
        }
        $previousTimeout = $this->commandTimeout;
        $previousExpiry = $this->expiresAt;
        if ($idleTimeout !== null) {
            $this->commandTimeout = $idleTimeout;
        }
        if ($maxDuration !== null) {
            $this->expiresAt = min($this->expiresAt, microtime(true) + $maxDuration);
        }
        try {
            $this->drainCommandBuffer();
            $this->write($command."\r\n");
            $output = $this->readUntil(self::PROMPT, false, $allowConfirmation);
            $output = preg_replace(self::PROMPT, '', $output);
            $lines = explode("\n", trim($output));
            if (trim($lines[0] ?? '') === $command) {
                array_shift($lines);
            }
            $output = trim(implode("\n", $lines));
            $original = $output;
            $output = $this->redact($output);
            self::assertCommandAccepted($output, $this->redact($command));

            return $raw ? $original : $output;
        } catch (OltConnectionException $e) {
            if ($e->reason === 'timeout') {
                $safeCommand = self::diagnostic($this->redact($command));
                // A late response must never be mistaken for the next command's response.
                $this->close();
                throw new OltConnectionException("Timeout pada perintah '".$safeCommand."'.", 'timeout');
            }
            throw $e;
        } finally {
            $this->commandTimeout = $previousTimeout;
            $this->expiresAt = $previousExpiry;
        }
    }

    public static function assertAccepted(string $output, ?string $command = null): void
    {
        if (preg_match('/(?:^|\n)\h*(?:%\h*(?:error|unknown|invalid|unrecognized|incomplete)|error\s*[:\[]|(?:invalid input|command not found|unknown command|unrecognized command|incomplete command|permission denied|authorization failed|access denied))[^\n]*/i', $output, $match)) {
            $detail = self::diagnostic(trim($match[0]));
            $label = $command === null ? 'Perintah' : "Perintah '".self::diagnostic($command)."'";
            throw new OltConnectionException($label.' ditolak OLT: '.substr($detail, 0, 400), 'command_rejected');
        }
    }

    private function drainCommandBuffer(): void
    {
        if(!is_resource($this->stream))throw new OltConnectionException('Sesi OLT belum terhubung.','disconnected');
        $deadline=min($this->expiresAt,microtime(true)+0.2);$bytes=0;
        do{
            // Nonblocking drain, decoding IAC so option negotiation state remains valid.
            $chunk=stream_get_contents($this->stream,65536);
            if($chunk===false||($chunk===''&&feof($this->stream)))throw new OltConnectionException('Sesi OLT terputus sebelum perintah berikutnya.','disconnected');
            if($chunk!==''){$bytes+=strlen($chunk);$this->decodeTelnet($chunk);}
            if($bytes>config('olt.max_output_bytes',4194304))throw new OltConnectionException('Buffer CLI melebihi batas.','output_limit');
            $read=[$this->stream];$write=[];$except=[];
            // ZTE may send a trailing table fragment/IAC negotiation in a later packet.
            $remaining=min(0.05,$deadline-microtime(true));
            if($remaining<=0)break;
            $ready=@stream_select($read,$write,$except,0,(int)($remaining*1000000));
            if($ready===false)throw new OltConnectionException('Gagal membersihkan buffer CLI.','socket_error');
            if(!$ready)return;
        }while(microtime(true)<$deadline);
        $this->close();
        throw new OltConnectionException('Buffer CLI belum tenang; buka sesi baru.','timeout');
    }

    public static function emptyOnuResponse(string $output): bool
    {
        // Only a standalone, recognized empty response; never hide mixed error/table data.
        return (bool) preg_match('/\A\s*(?:%Code\h+32318:\h*No related information(?:\h+to show)?\.?|No related information(?:\h+to show)?\.?|%Error:\h*Noonu\.?)\s*\z/i', $output);
    }

    public static function assertCommandAccepted(string $output, string $command): void
    {
        if (preg_match('/^show (?:gpon onu state(?: gpon-olt_\d+\/\d+\/\d+)?|(?:gpon onu baseinfo|pon power (?:onu-rx|olt-rx)) gpon-olt_\d+\/\d+\/\d+)$/i', $command) && self::emptyOnuResponse($output)) {
            return;
        }
        self::assertAccepted($output, $command);
    }

    private static function diagnostic(string $text): string
    {
        $text = preg_replace('/\b(password|passwd|community|secret)\b(\h*(?:[:=]\h*)?)(\S+)/i', '$1$2[REDACTED]', $text);

        return preg_replace('/[\x00-\x1f\x7f]/', '', $text);
    }

    private function exchange(string $command, bool $passwordPrompt = false): string
    {
        $this->write($command."\r\n");
        $pattern = $passwordPrompt ? '/(?:^|\n)[\w.\/:()\-]{1,100}[#>]\h*$|password\h*:\h*$/i' : self::PROMPT;
        $output = $this->readUntil($pattern);
        if ($command === '') {
            $output .= $this->drainLoginBuffer();
            preg_match_all('/(?:^|\n)[\w.\/:()\-]{1,100}[#>]\h*(?=\n|$)/', self::normalize($output), $prompts);
            // Ignore any trailing asynchronous banner when deciding privilege mode.
            if ($prompts[0]) {
                return end($prompts[0]);
            }
        }

        return self::normalize($output);
    }

    private function drainLoginBuffer(): string
    {
        $output = '';
        $deadline = min($this->expiresAt, microtime(true) + $this->commandTimeout);
        while (true) {
            $remaining = min(0.05, $deadline - microtime(true));
            if ($remaining <= 0) {
                throw new OltConnectionException('Timeout membersihkan banner login OLT.', 'timeout');
            }
            $read = [$this->stream];
            $write = [];
            $except = [];
            $ready = @stream_select($read, $write, $except, 0, (int) ($remaining * 1000000));
            if ($ready === 0) {
                return $output;
            }
            if ($ready === false) {
                throw new OltConnectionException('Gagal membaca buffer login OLT.', 'socket_error');
            }
            $chunk = fread($this->stream, 8192);
            if ($chunk === '' && feof($this->stream)) {
                throw new OltConnectionException('Sesi OLT terputus setelah login.', 'disconnected');
            }
            $output .= $this->decodeTelnet($chunk);
            if (strlen($output) > config('olt.max_output_bytes', 4194304)) {
                throw new OltConnectionException('Banner OLT melebihi batas ukuran.', 'output_limit');
            }
        }
    }

    private function readUntil(string $pattern, bool $login = false, bool $allowConfirmation = false): string
    {
        $buffer = '';
        $deadline = min($this->expiresAt, microtime(true) + $this->commandTimeout);
        while (true) {
            $this->wait(false, $deadline);
            $chunk = @fread($this->stream, 8192);
            if ($chunk === false || ($chunk === '' && feof($this->stream))) {
                throw new OltConnectionException('Sesi OLT terputus sebelum respons lengkap diterima.', 'disconnected');
            }
            if ($chunk !== '') {
                $deadline = min($this->expiresAt, microtime(true) + $this->commandTimeout);
            }
            $buffer .= $this->decodeTelnet($chunk);
            $buffer = self::normalize($buffer);
            if (strlen($buffer) > (int) config('olt.max_output_bytes', 4194304)) {
                throw new OltConnectionException('Output OLT melebihi batas ukuran. Persempit perintah atau sesuaikan batas output.', 'output_limit');
            }
            if ($login && preg_match('/(?:authentication failed|login incorrect|login failed|access denied|bad password|invalid password)|(?:username|login|password)\h*:\h*$/i', $buffer)) {
                throw new OltConnectionException('Login OLT gagal. Periksa username/password dan izin akun.', 'authentication_failed');
            }
            if (preg_match('/(?:--\s*More\s*--|----\s*More\s*----)\h*$/i', $buffer)) {
                $buffer = preg_replace('/(?:--+\s*More\s*--+)\h*$/i', '', $buffer);
                $this->write(' ');
            }
            if (preg_match($pattern, $buffer) || ($allowConfirmation && preg_match(self::CONFIRM, $buffer))) {
                return $buffer;
            }
        }
    }

    protected function wait(bool $writing, float $deadline): void
    {
        $remaining = min($deadline, $this->expiresAt) - microtime(true);
        if ($remaining <= 0) {
            throw new OltConnectionException('Timeout menunggu respons OLT. Data terakhir tidak diganti.', 'timeout');
        }
        if (! is_resource($this->stream)) {
            throw new OltConnectionException('Sesi OLT belum terhubung.', 'disconnected');
        }
        $read = $writing ? [] : [$this->stream];
        $write = $writing ? [$this->stream] : [];
        $except = [];
        $seconds = (int) floor($remaining);
        $micros = (int) (($remaining - $seconds) * 1000000);
        $ready = @stream_select($read, $write, $except, $seconds, $micros);
        if ($ready === 0) {
            throw new OltConnectionException('Timeout menunggu respons OLT. Data terakhir tidak diganti.', 'timeout');
        }
        if ($ready === false) {
            throw new OltConnectionException('Koneksi socket OLT mengalami kesalahan.', 'socket_error');
        }
    }

    protected function write(string $data): void
    {
        $deadline = min($this->expiresAt, microtime(true) + $this->commandTimeout);
        while ($data !== '') {
            $this->wait(true, $deadline);
            $written = @fwrite($this->stream, $data);
            if (! $written) {
                throw new OltConnectionException('Gagal mengirim data ke OLT.', 'disconnected');
            }
            $data = substr($data, $written);
        }
    }

    private function decodeTelnet(string $bytes): string
    {
        $plain = '';
        foreach (str_split($bytes) as $byte) {
            $n = ord($byte);
            switch ($this->iacState) {
                case 'data': if ($n === 255) {
                    $this->iacState = 'iac';
                } else {
                    $plain .= $byte;
                } break;
                case 'iac':
                    if ($n === 255) {
                        $plain .= $byte;
                        $this->iacState = 'data';
                    } elseif (in_array($n, [251, 252, 253, 254], true)) {
                        $this->iacCommand = $n;
                        $this->iacState = 'option';
                    } else {
                        $this->iacState = $n === 250 ? 'sub' : 'data';
                    }
                    break;
                case 'option':
                    if ($this->iacCommand === 251) {
                        $this->write(chr(255).chr(in_array($n, [1, 3], true) ? 253 : 254).$byte);
                    } elseif ($this->iacCommand === 253) {
                        $this->write(chr(255).chr(252).$byte);
                    }
                    $this->iacState = 'data';
                    break;
                case 'sub': if ($n === 255) {
                    $this->iacState = 'sub_iac';
                } break;
                case 'sub_iac': $this->iacState = $n === 240 ? 'data' : 'sub';
                    break;
            }
        }

        return $plain;
    }

    public static function normalize(string $text): string
    {
        $text = preg_replace('/\x1b\][^\x07\x1b]*(?:\x07|\x1b\\\\)|\x1b\[[0-?]*[ -\/]*[@-~]/', '', $text);
        $text = str_replace(["\r\n", "\r\0", "\r", "\0"], ["\n", '', "\n", ''], $text);
        while (str_contains($text, "\x08")) {
            $text = preg_replace('/[^\n]?\x08/', '', $text);
        }
        $text = preg_replace('/[\x01-\x07\x0b\x0c\x0e-\x1a\x1c-\x1f\x7f]/', '', $text);

        return $text;
    }

    private function redact(string $text): string
    {
        return str_replace($this->secrets, '[REDACTED]', $text);
    }

    public function close(): void
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        } $this->stream = null;
        $this->secrets = [];
    }

    public function __destruct()
    {
        $this->close();
    }
}
