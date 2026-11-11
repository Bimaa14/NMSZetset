<?php

namespace App\Services\Olt;

use phpseclib3\Math\BigInteger;

class MikrotikWinboxClient
{
    private $socket = null;
    private string $recvBuf = '';
    private string $sendAes = '';
    private string $recvAes = '';
    private string $sendHmac = '';
    private string $recvHmac = '';
    private int $sourceId = 8;
    private ?int $sessionId = null;
    private int $recvCounter = 0;
    private int $sendCounter = 0;

    private BigInteger $zero;
    private BigInteger $one;
    private BigInteger $two;
    private BigInteger $three;
    private BigInteger $p;
    private BigInteger $r;
    private BigInteger $montA;
    private BigInteger $convFromM;
    private BigInteger $convToM;
    private BigInteger $a;
    private BigInteger $b;
    private BigInteger $pMinus1Div2;
    private BigInteger $pPlus3Div8;
    private BigInteger $twoPowPMinus1Div4;
    private array $g;

    public function __construct()
    {
        $this->zero = new BigInteger(0);
        $this->one = new BigInteger(1);
        $this->two = new BigInteger(2);
        $this->three = new BigInteger(3);
        $this->p = new BigInteger('7fffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffed', 16);
        $this->r = new BigInteger('1000000000000000000000000000000014def9dea2f79cd65812631a5cf5d3ed', 16);
        $this->montA = new BigInteger(486662);
        $inv3 = $this->three->modInverse($this->p);
        $this->convFromM = $this->mod($this->montA->multiply($inv3));
        $this->convToM = $this->mod($this->p->subtract($this->convFromM));
        $this->a = new BigInteger('2aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa984914a144', 16);
        $this->b = new BigInteger('7b425ed097b425ed097b425ed097b425ed097b425ed097b4260b5e9c7710c864', 16);
        [$this->pMinus1Div2] = $this->p->subtract($this->one)->divide($this->two);
        [$this->pPlus3Div8] = $this->p->add($this->three)->divide(new BigInteger(8));
        [$pMinus1Div4] = $this->p->subtract($this->one)->divide(new BigInteger(4));
        $this->twoPowPMinus1Div4 = $this->two->powMod($pMinus1Div4, $this->p);
        $this->g = $this->liftX(new BigInteger(9), 0);
    }

    public function connect(string $host, int $port, string $username, string $password, float $timeout = 10.0): void
    {
        $this->close();
        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, $timeout);
        if (! is_resource($socket)) {
            throw new OltConnectionException("Gagal terhubung ke router MikroTik {$host}:{$port}.", 'connection_failed');
        }
        $this->socket = $socket;
        stream_set_timeout($this->socket, max(3, (int) ceil($timeout)));

        try {
            $this->authenticateAndOpenTerminal($username, $password);
        } catch (\Throwable $e) {
            $this->close();
            if ($e instanceof OltConnectionException) {
                throw $e;
            }
            throw new OltConnectionException('Gagal membuka sesi terminal MikroTik: '.$e->getMessage(), 'authentication_failed');
        }
    }

    public function runCommand(string $command, float $timeout = 8.0, float $idleAfterPrompt = 0.25): string
    {
        if (! is_resource($this->socket) || $this->sessionId === null) {
            throw new OltConnectionException('Sesi MikroTik belum terbuka.', 'connection_failed');
        }
        $_ = $idleAfterPrompt;
        @fwrite(
            $this->socket,
            $this->encryptFrame($this->buildMeptyPacket($this->sourceId, $this->sessionId, $this->sendCounter, $command."\r"))
        );
        $this->sendCounter += \strlen($command) + 1;

        $out = '';
        $wait = microtime(true) + $timeout;
        while (microtime(true) < $wait) {
            $r = [$this->socket];
            $w = [];
            $e = [];
            if (@stream_select($r, $w, $e, 0, 80000)) {
                $chunk = @fread($this->socket, 65536);
                if ($chunk !== false && $chunk !== '') {
                    $this->recvBuf .= $chunk;
                }
            }
            $got = false;
            foreach ($this->extractMessages() as $m) {
                $p = $this->parseM2($m);
                if (isset($p[0x000002]) && \is_string($p[0x000002])) {
                    $out .= $p[0x000002];
                    $this->recvCounter += \strlen($p[0x000002]);
                    $got = true;
                }
            }
            if ($got) {
                @fwrite(
                    $this->socket,
                    $this->encryptFrame($this->buildMeptyPacket($this->sourceId, $this->sessionId, $this->recvCounter))
                );
            }
            $clean = $this->stripAnsi($out);
            if (\strlen($clean) > \strlen($command) + 10 && preg_match('/\[[^\]]+@[^\]]+\]\s*>\s*$/', $clean)) {
                break;
            }
        }
        return $this->stripAnsi($out);
    }

    public function close(): void
    {
        if (is_resource($this->socket)) {
            @fclose($this->socket);
        }
        $this->socket = null;
        $this->recvBuf = '';
        $this->sessionId = null;
        $this->recvCounter = 0;
        $this->sendCounter = 0;
    }

    private function authenticateAndOpenTerminal(string $user, string $pass): void
    {
        $s_a = random_bytes(32);
        [$x_w_a, $x_w_a_parity] = $this->genPublicKey($s_a);
        $msg = $user."\x00".$x_w_a.\chr($x_w_a_parity);
        @fwrite($this->socket, \chr(\strlen($msg))."\x06".$msg);
        $resp = (string) @fread($this->socket, 1024);
        if (\strlen($resp) < 51) {
            throw new OltConnectionException('Handshake MikroTik EC-SRP5 gagal.', 'authentication_failed');
        }
        $body = substr($resp, 2);
        $x_w_b = substr($body, 0, 32);
        $x_w_b_parity = \ord($body[32]);
        $salt = substr($body, 33, 16);

        $i = hash('sha256', $salt.hash('sha256', $user.':'.$pass, true), true);
        [$x_gamma] = $this->genPublicKey($i);
        $v = $this->redp1($x_gamma, 1);
        $w_b = $this->pointAdd($this->liftX(new BigInteger($x_w_b, 256), $x_w_b_parity), $v);
        $j = hash('sha256', $x_w_a.$x_w_b, true);
        $ptScalar = (new BigInteger($i, 256))->multiply(new BigInteger($j, 256))->add(new BigInteger($s_a, 256));
        [, $ptScalar] = $ptScalar->divide($this->r);
        $pt = $this->pointMul($w_b, $ptScalar);
        [$z] = $this->toMontgomery($pt);
        $secret = hash('sha256', $z, true);

        $client_cc = hash('sha256', $j.$z, true);
        @fwrite($this->socket, \chr(\strlen($client_cc))."\x06".$client_cc);
        $resp2 = (string) @fread($this->socket, 1024);
        if ($resp2 === '') {
            throw new OltConnectionException('Autentikasi MikroTik ditolak.', 'authentication_failed');
        }
        [$this->sendAes, $this->recvAes, $this->sendHmac, $this->recvHmac] = $this->deriveStreamKeys($secret);

        $this->sourceId = 8;
        $initM2 = "M2"
            ."\x01\x00\xff\x88\x02\x00\x0d\x00\x00\x00\x04\x00\x00\x00"
            ."\x02\x00\xff\x88\x02\x00\x00\x00\x00\x00".\pack('V', $this->sourceId)
            ."\x05\x00\xff\x01"
            ."\x06\x00\xff\x09\x00"
            ."\x07\x00\xff\x09\x07";
        @fwrite($this->socket, $this->encryptFrame($initM2));
        $this->recvBuf = (string) @fread($this->socket, 65536);
        foreach ($this->extractMessages() as $m) {
            $p = $this->parseM2($m);
            if (! empty($p[0xFF0001]) && \is_array($p[0xFF0001]) && isset($p[0xFF0001][1])) {
                $this->sourceId = (int) $p[0xFF0001][1];
            }
        }

        $meptyLogin = "M2"
            ."\x01\x00\xff\x88\x01\x00\x4c\x00\x00\x00"
            ."\x02\x00\xff\x88\x02\x00\x00\x00\x00\x00".\pack('V', $this->sourceId)
            ."\x05\x00\xff\x01"
            ."\x05\x00\x00\x08".\pack('V', 200)
            ."\x06\x00\x00\x08".\pack('V', 50)
            ."\x08\x00\x00\x09\x00"
            ."\x06\x00\xff\x09\x01"
            ."\x07\x00\xff\x08".\pack('V', 0x0A0065)
            ."\x0b\x00\x00\x29\x07M2\x01\x00\x00\x09\x00"
            ."\x07\x00\x00\x21\x05vt102"
            ."\x01\x00\x00\x21".\chr(\strlen($pass)).$pass;
        @fwrite($this->socket, $this->encryptFrame($meptyLogin));

        $this->sessionId = null;
        $deadline = microtime(true) + 4.0;
        while (microtime(true) < $deadline && $this->sessionId === null) {
            $chunk = @fread($this->socket, 65536);
            if ($chunk !== false && $chunk !== '') {
                $this->recvBuf .= $chunk;
            }
            foreach ($this->extractMessages() as $m) {
                $p = $this->parseM2($m);
                if (isset($p[0xFE0001]) && ! empty($p[0xFF0002]) && \in_array(76, (array) $p[0xFF0002], true)) {
                    $this->sessionId = (int) $p[0xFE0001];
                }
            }
        }

        if ($this->sessionId === null) {
            throw new OltConnectionException('Gagal membuka sesi terminal mepty pada router MikroTik.', 'authentication_failed');
        }

        $this->recvCounter = 0;
        $this->sendCounter = 0;
        stream_set_blocking($this->socket, false);
        @fwrite(
            $this->socket,
            $this->encryptFrame($this->buildMeptyPacket($this->sourceId, $this->sessionId, $this->sendCounter))
        );
        $waitPrompt = microtime(true) + 3.0;
        $banner = '';
        while (microtime(true) < $waitPrompt) {
            $r = [$this->socket];
            $w = [];
            $e = [];
            if (@stream_select($r, $w, $e, 0, 80000)) {
                $chunk = @fread($this->socket, 65536);
                if ($chunk !== false && $chunk !== '') {
                    $this->recvBuf .= $chunk;
                }
            }
            $got = false;
            foreach ($this->extractMessages() as $m) {
                $p = $this->parseM2($m);
                if (isset($p[0x000002]) && \is_string($p[0x000002])) {
                    $banner .= $p[0x000002];
                    $this->recvCounter += \strlen($p[0x000002]);
                    $got = true;
                }
            }
            if ($got) {
                @fwrite(
                    $this->socket,
                    $this->encryptFrame($this->buildMeptyPacket($this->sourceId, $this->sessionId, $this->recvCounter))
                );
            }
            if (str_contains($banner, '>')) {
                break;
            }
        }
    }

    private function buildMeptyPacket(int $sourceId, int $sessionId, int $counter, ?string $data = null): string
    {
        $sessField = $sessionId <= 255
            ? "\x01\x00\xfe\x09".\chr($sessionId)
            : "\x01\x00\xfe\x08".\pack('V', $sessionId);
        $m = "M2"
            ."\x01\x00\xff\x88\x01\x00\x4c\x00\x00\x00"
            ."\x02\x00\xff\x88\x02\x00\x00\x00\x00\x00".\pack('V', $sourceId)
            ."\x03\x00\x00\x08".\pack('V', $counter)
            .$sessField
            ."\x07\x00\xff\x08".\pack('V', 0x0A0067);
        if ($data !== null) {
            if (\strlen($data) <= 255) {
                $m .= "\x02\x00\x00\x31".\chr(\strlen($data)).$data;
            } else {
                $m .= "\x02\x00\x00\x30".\pack('v', \strlen($data)).$data;
            }
        }
        return $m;
    }

    private function hkdfWinbox(string $seed): string
    {
        $h1 = hash_hmac('sha1', $seed, str_repeat("\x00", 64), true);
        $h2 = '';
        $res = '';
        for ($i = 0; $i < 2; $i++) {
            $h2 = hash_hmac('sha1', $h2.\chr($i + 1), $h1, true);
            $res .= $h2;
        }
        return substr($res, 0, 36);
    }

    private function deriveStreamKeys(string $secret): array
    {
        $m2 = 'On the client side, this is the send key; on the server side, it is the receive key.';
        $m3 = 'On the client side, this is the receive key; on the server side, it is the send key.';
        $txSeed = substr(sha1($secret.str_repeat("\x00", 40).$m2.str_repeat("\xf2", 40), true), 0, 16);
        $rxSeed = substr(sha1($secret.str_repeat("\x00", 40).$m3.str_repeat("\xf2", 40), true), 0, 16);
        $sendKey = $this->hkdfWinbox($txSeed);
        $recvKey = $this->hkdfWinbox($rxSeed);
        return [substr($sendKey, 0, 16), substr($recvKey, 0, 16), substr($sendKey, 16, 20), substr($recvKey, 16, 20)];
    }

    private function encryptFrame(string $m2): string
    {
        $h = hash_hmac('sha1', $m2, $this->sendHmac, true);
        $iv = random_bytes(16);
        $padByte = 0x0F - (\strlen($m2.$h) % 16);
        $plain = $m2.$h.str_repeat(\chr($padByte), $padByte + 1);
        $enc = openssl_encrypt($plain, 'aes-128-cbc', $this->sendAes, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $iv);
        $full = \pack('n', \strlen($enc)).$iv.$enc;
        $out = '';
        $rem = $full;
        $idx = "\x06";
        while (true) {
            $fLen = \strlen($rem) >= 0xFF ? 0xFF : \strlen($rem);
            $out .= \chr($fLen).$idx;
            if (\strlen($rem) >= 0xFF) {
                $out .= substr($rem, 0, 0xFF);
                $rem = substr($rem, 0xFF);
            } else {
                $out .= $rem;
                break;
            }
            $idx = "\xff";
        }
        return $out;
    }

    private function extractMessages(): array
    {
        $msgs = [];
        while (\strlen($this->recvBuf) >= 2) {
            $pos = 0;
            $assembled = '';
            $complete = false;
            while ($pos < \strlen($this->recvBuf)) {
                if ($pos + 2 > \strlen($this->recvBuf)) {
                    break;
                }
                $cLen = \ord($this->recvBuf[$pos]);
                $pos += 2;
                $pBytes = $cLen === 0xFF ? 0xFF : $cLen;
                if ($pos + $pBytes > \strlen($this->recvBuf)) {
                    break;
                }
                $assembled .= substr($this->recvBuf, $pos, $pBytes);
                $pos += $pBytes;
                if ($cLen < 0xFF) {
                    $complete = true;
                    break;
                }
            }
            if (! $complete) {
                break;
            }
            $this->recvBuf = substr($this->recvBuf, $pos);
            if (\strlen($assembled) < 18) {
                continue;
            }
            $iv = substr($assembled, 2, 16);
            $cipher = substr($assembled, 18);
            if ($cipher === '' || \strlen($cipher) % 16 !== 0) {
                continue;
            }
            $dec = openssl_decrypt($cipher, 'aes-128-cbc', $this->recvAes, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $iv);
            if ($dec === false || \strlen($dec) < 21) {
                continue;
            }
            $last = \ord($dec[\strlen($dec) - 1]);
            $dec = substr($dec, 0, \strlen($dec) - ($last + 1));
            if (\strlen($dec) < 20) {
                continue;
            }
            $msgs[] = substr($dec, 0, -20);
        }
        return $msgs;
    }

    private function parseM2(string $data): array
    {
        if (\strlen($data) < 2 || substr($data, 0, 2) !== 'M2') {
            return [];
        }
        $res = [];
        $pos = 2;
        $len = \strlen($data);
        while ($pos + 4 <= $len) {
            $kl = \ord($data[$pos]);
            $kh = \ord($data[$pos + 1]);
            $ns = \ord($data[$pos + 2]);
            $tp = \ord($data[$pos + 3]);
            $pos += 4;
            $fk = ($ns << 16) | ($kh << 8) | $kl;
            if ($tp === 0x00) {
                $res[$fk] = false;
            } elseif ($tp === 0x01) {
                $res[$fk] = true;
            } elseif ($tp === 0x09 && $pos < $len) {
                $res[$fk] = \ord($data[$pos]);
                $pos++;
            } elseif ($tp === 0x08 && $pos + 4 <= $len) {
                $res[$fk] = \unpack('V', substr($data, $pos, 4))[1];
                $pos += 4;
            } elseif ($tp === 0x10 && $pos + 8 <= $len) {
                $res[$fk] = \unpack('P', substr($data, $pos, 8))[1];
                $pos += 8;
            } elseif ($tp === 0x88 && $pos + 2 <= $len) {
                $cnt = \unpack('v', substr($data, $pos, 2))[1];
                $pos += 2;
                $arr = [];
                for ($i = 0; $i < $cnt && $pos + 4 <= $len; $i++) {
                    $arr[] = \unpack('V', substr($data, $pos, 4))[1];
                    $pos += 4;
                }
                $res[$fk] = $arr;
            } elseif (($tp === 0x20 || $tp === 0x30 || $tp === 0x28) && $pos + 2 <= $len) {
                $slen = \unpack('v', substr($data, $pos, 2))[1];
                $pos += 2;
                $res[$fk] = substr($data, $pos, $slen);
                $pos += $slen;
            } elseif (($tp === 0x21 || $tp === 0x31 || $tp === 0x29) && $pos < $len) {
                $slen = \ord($data[$pos]);
                $pos++;
                $res[$fk] = substr($data, $pos, $slen);
                $pos += $slen;
            } elseif ($tp === 0xA0 && $pos + 2 <= $len) {
                $cnt = \unpack('v', substr($data, $pos, 2))[1];
                $pos += 2;
                $arr = [];
                for ($i = 0; $i < $cnt && $pos + 2 <= $len; $i++) {
                    $slen = \unpack('v', substr($data, $pos, 2))[1];
                    $pos += 2;
                    $arr[] = substr($data, $pos, $slen);
                    $pos += $slen;
                }
                $res[$fk] = $arr;
            } elseif ($tp === 0xA8 && $pos + 2 <= $len) {
                $cnt = \unpack('v', substr($data, $pos, 2))[1];
                $pos += 2;
                $arr = [];
                for ($i = 0; $i < $cnt && $pos + 2 <= $len; $i++) {
                    $mlen = \unpack('v', substr($data, $pos, 2))[1];
                    $pos += 2;
                    $arr[] = $this->parseM2(substr($data, $pos, $mlen));
                    $pos += $mlen;
                }
                $res[$fk] = $arr;
            } else {
                break;
            }
        }
        return $res;
    }

    private function stripAnsi(string $s): string
    {
        return preg_replace('/\x1b\[[0-?]*[ -\/]*[@-~]/', '', $s) ?? $s;
    }

    private function mod(BigInteger $n): BigInteger
    {
        [, $rem] = $n->divide($this->p);
        if ($rem->compare($this->zero) < 0) {
            $rem = $rem->add($this->p);
        }
        return $rem;
    }

    private function sqrtModP(BigInteger $a): ?array
    {
        $a = $this->mod($a);
        if ($a->equals($this->zero)) {
            return [$this->zero, $this->zero];
        }
        if (! $a->powMod($this->pMinus1Div2, $this->p)->equals($this->one)) {
            return null;
        }
        $x = $a->powMod($this->pPlus3Div8, $this->p);
        if (! $this->mod($x->multiply($x))->equals($a)) {
            $x = $this->mod($x->multiply($this->twoPowPMinus1Div4));
        }
        return [$x, $this->p->subtract($x)];
    }

    private function liftX(BigInteger $x, int $parity): ?array
    {
        $x = $this->mod($x);
        $x2 = $this->mod($x->multiply($x));
        $x3 = $this->mod($x2->multiply($x));
        $y2 = $this->mod($x3->add($this->montA->multiply($x2))->add($x));
        $wx = $this->mod($x->add($this->convFromM));
        $ys = $this->sqrtModP($y2);
        if ($ys === null) {
            return null;
        }
        foreach ($ys as $y) {
            if (($y->isOdd() ? 1 : 0) === ($parity ? 1 : 0)) {
                return [$wx, $y];
            }
        }
        return [$wx, $ys[1]];
    }

    private function pointAdd(?array $P, ?array $Q): ?array
    {
        if ($P === null) {
            return $Q;
        }
        if ($Q === null) {
            return $P;
        }
        [$x1, $y1] = $P;
        [$x2, $y2] = $Q;
        if ($x1->equals($x2)) {
            if ($this->mod($y1->add($y2))->equals($this->zero)) {
                return null;
            }
            $num = $this->mod($this->three->multiply($x1)->multiply($x1)->add($this->a));
            $den = $this->mod($this->two->multiply($y1))->modInverse($this->p);
        } else {
            $num = $this->mod($y2->subtract($y1));
            $den = $this->mod($x2->subtract($x1))->modInverse($this->p);
        }
        $slope = $this->mod($num->multiply($den));
        $rx = $this->mod($slope->multiply($slope)->subtract($x1)->subtract($x2));
        $ry = $this->mod($slope->multiply($x1->subtract($rx))->subtract($y1));
        return [$rx, $ry];
    }

    private function pointMul(array $P, BigInteger $k): ?array
    {
        $res = null;
        $addend = $P;
        $bits = $k->toBits();
        for ($i = \strlen($bits) - 1; $i >= 0; $i--) {
            if ($bits[$i] === '1') {
                $res = $this->pointAdd($res, $addend);
            }
            $addend = $this->pointAdd($addend, $addend);
        }
        return $res;
    }

    private function toMontgomery(array $pt): array
    {
        $mx = $this->mod($pt[0]->add($this->convToM));
        return [str_pad($mx->toBytes(), 32, "\x00", STR_PAD_LEFT), $pt[1]->isOdd() ? 1 : 0];
    }

    private function genPublicKey(string $privBytes): array
    {
        return $this->toMontgomery($this->pointMul($this->g, new BigInteger($privBytes, 256)));
    }

    private function redp1(string $xBytes, int $parity): array
    {
        $x = hash('sha256', $xBytes, true);
        while (true) {
            $x2 = hash('sha256', $x, true);
            $pt = $this->liftX(new BigInteger($x2, 256), $parity);
            if ($pt !== null) {
                return $pt;
            }
            $n = (new BigInteger($x, 256))->add($this->one);
            $x = str_pad($n->toBytes(), 32, "\x00", STR_PAD_LEFT);
        }
    }
}
