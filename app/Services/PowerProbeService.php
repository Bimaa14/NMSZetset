<?php

namespace App\Services;

use Illuminate\Support\Facades\Process;
use Throwable;

class PowerProbeService
{
    /**
     * Execute a safe, shell-free ICMP ping check with 3 packets.
     *
     * @return array{reachable:bool,latency_ms:?float,packet_loss:int,error:?string}
     */
    public function ping(string $ipAddress): array
    {
        $ip = trim($ipAddress);
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6)) {
            return [
                'reachable' => false,
                'latency_ms' => null,
                'packet_loss' => 100,
                'error' => 'Format alamat IP tidak valid.',
            ];
        }

        $isWindows = DIRECTORY_SEPARATOR === '\\';
        $isIpv6 = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;

        $command = $isWindows
            ? ['ping', '-n', '3', '-w', '2000', $ip]
            : ($isIpv6 ? ['ping', '-6', '-n', '-c', '3', '-W', '2', $ip] : ['ping', '-n', '-c', '3', '-W', '2', $ip]);

        try {
            $result = Process::timeout(9)->run($command);
            $output = $result->output()."\n".$result->errorOutput();
        } catch (Throwable $e) {
            return [
                'reachable' => false,
                'latency_ms' => null,
                'packet_loss' => 100,
                'error' => 'Gagal menjalankan ICMP ping: '.$e->getMessage(),
            ];
        }

        $loss = 100;
        if (preg_match('/(\d+(?:\.\d+)?)%\s*(?:packet\s+)?(?:loss|hilang)/i', $output, $m)) {
            $loss = (int) round((float) $m[1]);
        } elseif ($result->successful()) {
            $loss = 0;
        }

        $reachable = $loss < 100 && ($result->successful() || preg_match('/(?:bytes from|Reply from|ttl=)/i', $output));

        $latency = null;
        if (preg_match('/min\/avg\/max\/(?:mdev|stddev)\s*=\s*[\d.]+\/([\d.]+)\//i', $output, $m)) {
            $latency = round((float) $m[1], 2);
        } elseif (preg_match('/(?:Average|Rata-rata)\s*=\s*(\d+(?:\.\d+)?)\s*ms/i', $output, $m)) {
            $latency = round((float) $m[1], 2);
        } elseif (preg_match_all('/time[=<]\s*(\d+(?:\.\d+)?)\s*ms/i', $output, $matches) && ! empty($matches[1])) {
            $values = array_map('floatval', $matches[1]);
            $latency = round(array_sum($values) / count($values), 2);
        }

        return [
            'reachable' => $reachable,
            'latency_ms' => $reachable ? $latency : null,
            'packet_loss' => max(0, min(100, $loss)),
            'error' => $reachable ? null : 'ICMP Ping Timeout (100% packet loss)',
        ];
    }
}
