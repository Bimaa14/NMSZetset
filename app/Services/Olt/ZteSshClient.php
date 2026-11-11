<?php

namespace App\Services\Olt;

use App\Models\Olt;
use phpseclib3\Net\SSH2;

class ZteSshClient implements CliClient
{
    use PreparesZteSession;

    private ?SSH2 $ssh = null;

    private float $expiresAt;

    private array $secrets = [];

    private ?float $idleTimeout = null;

    public function connect(Olt $olt): void
    {
        $this->close();
        $this->expiresAt = microtime(true) + config('olt.operation_timeout', 180);
        $this->ssh = $this->createSsh($olt);
        try {
            $key = $this->ssh->getServerPublicHostKey();
            if (! $key) {
                throw new OltConnectionException('Handshake SSH gagal.', 'ssh_handshake');
            }
            $parts = explode(' ', $key);
            $fingerprint = 'SHA256:'.rtrim(base64_encode(hash('sha256', base64_decode($parts[1] ?? '', true), true)), '=');
            if (! $olt->ssh_host_key_sha256 || ! hash_equals($olt->ssh_host_key_sha256, $fingerprint)) {
                throw new OltConnectionException('Host key SSH belum cocok. Fingerprint server: '.$fingerprint.'. Verifikasi dengan pengelola OLT lalu simpan fingerprint.', 'ssh_host_key');
            }
            $this->secrets = array_filter([(string) $olt->telnet_pass_enc, (string) $olt->snmp_community_enc], fn ($v) => $v !== '');
            if (! $this->ssh->login((string) $olt->telnet_user, (string) $olt->telnet_pass_enc)) {
                throw new OltConnectionException('Login SSH OLT gagal. Periksa username/password.', 'authentication_failed');
            }
            $this->read();
            $this->prepareSession((string) $olt->telnet_pass_enc);
        } catch (OltConnectionException $e) {
            $this->close();
            throw $e;
        } catch (\Throwable) {
            $this->close();
            throw new OltConnectionException('Koneksi SSH OLT gagal atau timeout.', 'ssh_connection');
        }
    }

    protected function createSsh(Olt $olt): SSH2
    {
        return new SSH2($olt->ip_address, $olt->telnet_port, $olt->connection_timeout ?: 5);
    }

    private function read(bool $confirmation = false, bool $passwordPrompt = false): string
    {
        $idle = $this->idleTimeout ?? (float) config('olt.command_timeout', 10);
        $deadline = min($this->expiresAt, microtime(true) + $idle);
        $prompt = '(?:^|\n)[\w.\/:\-]{1,100}(?:\([\w.\/:\- ]{1,120}\))?[#>]\s*$';
        $more = '--+\s*More\s*--+\h*$';
        $pattern = '~'.$prompt.'|'.$more.($passwordPrompt ? '|password\h*:\h*$' : '').($confirmation ? '|(?:\[(?:yes/no|y/n)\]|\((?:yes/no|y/n)\))\h*[:?]?\h*$' : '').'~i';
        $buffer = '';
        while (true) {
            $seconds = $deadline - microtime(true);
            if ($seconds <= 0) {
                throw new OltConnectionException('Batas waktu operasi SSH habis.', 'timeout');
            }
            $this->ssh->setTimeout($seconds);
            $output = $this->ssh->read('', SSH2::READ_NEXT);
            if ($this->ssh->isTimeout()) {
                throw new OltConnectionException('Timeout menunggu data SSH.', 'timeout');
            }
            if (! is_string($output) || $output === '') {
                throw new OltConnectionException('Sesi SSH terputus sebelum respons lengkap.', 'disconnected');
            }
            $buffer = ZteTelnetClient::normalize($buffer.$output);
            $deadline = min($this->expiresAt, microtime(true) + $idle);
            if (strlen($buffer) > config('olt.max_output_bytes', 4194304)) {
                throw new OltConnectionException('Output SSH melebihi batas ukuran.', 'output_limit');
            }
            if (preg_match('~'.$more.'~i', $buffer)) {
                $buffer = preg_replace('~'.$more.'~i', '', $buffer);
                if (! $this->ssh->write(' ')) {
                    throw new OltConnectionException('Sesi SSH terputus saat paging.', 'disconnected');
                }
            } elseif (preg_match($pattern, $buffer)) {
                return $buffer;
            }
        }
    }

    private function exchange(string $command, bool $passwordPrompt = false): string
    {
        if (! $this->ssh?->write($command."\n")) {
            throw new OltConnectionException('Sesi SSH terputus.', 'disconnected');
        }

        return $this->read(false, $passwordPrompt);
    }

    public function command(string $command, bool $allowConfirmation = false, ?float $idleTimeout = null, ?float $maxDuration = null, bool $raw = false): string
    {
        if ($command === '' || strlen($command) > 512 || preg_match('/[\x00-\x1f\x7f\xff]/', $command)) {
            throw new OltConnectionException('Kirim satu perintah CLI tanpa karakter kontrol.', 'invalid_command');
        }
        $previousExpiry = $this->expiresAt;
        $previousIdle = $this->idleTimeout;
        $this->idleTimeout = $idleTimeout;
        if ($maxDuration !== null) {
            $this->expiresAt = min($this->expiresAt, microtime(true) + $maxDuration);
        }
        try {
            if (! $this->ssh?->write($command."\n")) {
                throw new OltConnectionException('Sesi SSH terputus.', 'disconnected');
            }
            $output = trim(preg_replace(ZteTelnetClient::PROMPT, '', $this->read($allowConfirmation)));
            $lines = explode("\n", $output);
            if (trim($lines[0] ?? '') === $command) {
                array_shift($lines);
            }
            $original = trim(implode("\n", $lines));
            $output = str_replace($this->secrets, '[REDACTED]', $original);
            ZteTelnetClient::assertCommandAccepted($output, str_replace($this->secrets, '[REDACTED]', $command));

            return $raw ? $original : $output;
        } catch (OltConnectionException $e) {
            if ($e->reason === 'timeout') {
                $safeCommand = str_replace($this->secrets, '[REDACTED]', $command);
                $safeCommand = preg_replace('/\b(password|passwd|community|secret)\b(\h*(?:[:=]\h*)?)(\S+)/i', '$1$2[REDACTED]', $safeCommand);
                $this->close();
                throw new OltConnectionException("Timeout pada perintah '".$safeCommand."'.", 'timeout');
            }
            throw $e;
        } catch (\Throwable) {
            throw new OltConnectionException('Perintah SSH tidak selesai.', 'ssh_connection');
        } finally {
            $this->expiresAt = $previousExpiry;
            $this->idleTimeout = $previousIdle;
        }
    }

    public function close(): void
    {
        $this->ssh?->disconnect();
        $this->ssh = null;
        $this->secrets = [];
    }

    public function __destruct()
    {
        $this->close();
    }
}
