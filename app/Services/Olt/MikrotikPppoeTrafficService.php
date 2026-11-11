<?php

namespace App\Services\Olt;

use App\Models\{Olt, Onu};
use Illuminate\Support\Facades\{Cache, DB};

class MikrotikPppoeTrafficService
{
    /**
     * Verified historical /ppp secret mappings for Tamansari ONUs whose WAN router MAC
     * differs from the EPON ONU PON MAC or whose OLT ONU name is a raw MAC address.
     */
    private const KNOWN_ONU_PPPOE_MAP = [
        '80F1A80C45DC' => 'kartunettmsr290',
        'E06066626AEC' => 'kartunettmsr461',
        'C0FD848EAB2F' => 'kartunettmsr158',
        'C0FD848EAE6C' => 'kartunettmsr211',
        '64F88A58C960' => 'kartunettmsr224',
        '64F88AB6C4F7' => 'kartunettmsr487',
        'DC6279EEEE75' => 'rw09nanisuryani',
        '001A694329DC' => 'kartunettmsr120',
        '001A694327EE' => 'kartunettmsr1551',
        '64F88A61ECBC' => 'kartunettmsr39',
        '64F88A58BFAC' => 'kartunettmsr232',
        '64F88A5E879C' => 'kartunettmsr329',
        '64F88A58CA5C' => 'kartunettmsr333',
        '64F88A58BC70' => 'kartunettmsr341',
        '64F88A5E6A50' => 'kartunettmsr362',
        '64F88A5E8164' => 'kartunettmsr464',
        '64F88AB6C56F' => 'kartunettmsr473',
        '1062EBA690A1' => '131173190047.CIFO',
        '70A56AD2FF2D' => '131628106165.CIFO',
    ];

    private const MAC_OFFSETS = [0, 1, -1, 2, -2, 3, -3, 4, -4, 5, -5, -6, -7, -8, 6, 7, 8];

    public function isConfiguredFor(Olt $olt): bool
    {
        return $olt->chassis_type === 'C1600'
            && $olt->connection_mode === 'real'
            && count($this->configuredRouters()) > 0;
    }

    public function configuredRouters(): array
    {
        $routers = config('olt.mikrotik_routers', []);
        if (! is_array($routers)) {
            return [];
        }
        $active = [];
        foreach ($routers as $key => $cfg) {
            if (! is_array($cfg)) {
                continue;
            }
            $host = trim((string) ($cfg['host'] ?? ''));
            $user = trim((string) ($cfg['user'] ?? ''));
            $pass = (string) ($cfg['password'] ?? '');
            $port = (int) ($cfg['port'] ?? 60193);
            if ($host !== '' && $user !== '' && $pass !== '' && $port > 0) {
                $active[$key] = [
                    'key' => (string) $key,
                    'name' => (string) ($cfg['name'] ?? strtoupper((string) $key)),
                    'host' => $host,
                    'port' => $port,
                    'user' => $user,
                    'password' => $pass,
                    'timeout' => max(3.0, (float) ($cfg['timeout'] ?? 12.0)),
                    'service_filter' => isset($cfg['service_filter']) ? (string) $cfg['service_filter'] : null,
                ];
            }
        }
        return $active;
    }

    public function collect(Onu $onu): array
    {
        $olt = $onu->olt;
        abort_unless(
            $olt && $olt->chassis_type === 'C1600' && $olt->connection_mode === 'real' && $olt->traffic_monitoring_enabled && $this->isConfiguredFor($olt),
            422,
            'Monitoring traffic per ONU via MikroTik belum diaktifkan untuk OLT ini.'
        );
        abort_unless(
            $onu->olt_id === $olt->id && $olt->onus()->current()->whereKey($onu->id)->exists(),
            422,
            'ONU YOTC sudah tidak aktif pada inventaris saat ini.'
        );

        if ($recent = $this->recentOnuSample($onu)) {
            return $recent;
        }

        $result = $this->syncOltOnus($olt);
        if (isset($result['samples'][$onu->id])) {
            $sample = $result['samples'][$onu->id];
            $onu->setAttribute('download_bps', $sample['rx_bps']);
            $onu->setAttribute('upload_bps', $sample['tx_bps']);
            $onu->setAttribute('traffic_observed_at', $sample['recorded_at']);
            return $sample;
        }

        if (! empty($result['warnings']) && empty($result['samples'])) {
            throw new OltConnectionException(implode(' ', $result['warnings']), 'connection_failed');
        }

        if ($onu->phase_state !== 'working') {
            throw new OltConnectionException('ONU sedang offline; sesi PPPoE tidak aktif di router MikroTik.', 'traffic_unavailable');
        }

        throw new OltConnectionException('Sesi PPPoE untuk ONU ini tidak ditemukan pada router MikroTik (kemungkinan mode bridge/non-PPPoE).', 'traffic_unavailable');
    }

    public function poll(Olt $olt): array
    {
        if (! $this->isConfiguredFor($olt) || ! $olt->traffic_monitoring_enabled) {
            return [];
        }
        $result = $this->syncOltOnus($olt);
        return $result['warnings'];
    }

    public function syncOltOnus(Olt $olt): array
    {
        $snapshot = $this->fetchRoutersSnapshot($olt);
        $sessions = $snapshot['sessions'];
        $warnings = $snapshot['warnings'];

        if (empty($sessions)) {
            return ['samples' => [], 'warnings' => $warnings];
        }

        $onus = $olt->onus()->current()->where('phase_state', 'working')->get();
        $matches = $this->matchOnusToSessions($onus, $sessions);
        if (empty($matches)) {
            return ['samples' => [], 'warnings' => $warnings];
        }

        $stamp = now();
        $isoStamp = $stamp->toIso8601String();
        $ttl = max(10, min(60, (int) config('olt.yotc_traffic_live_interval', 15)));
        $trafficService = app(OltTrafficService::class);

        $rowsToInsert = [];
        $onuUpdates = [];
        $samplesByOnuId = [];

        foreach ($matches as $onuId => $match) {
            /** @var Onu $onu */
            $onu = $match['onu'];
            $session = $match['session'];

            $rxBytes = $session['rx_bytes'];
            $txBytes = $session['tx_bytes'];
            $rxBps = $session['rx_bps'];
            $txBps = $session['tx_bps'];

            $counterKey = 'nms:mikrotik-pppoe-counter:'.$olt->id.':'.$onu->id;
            $previous = Cache::get($counterKey);
            $entityKey = $session['router_key'].':'.$session['name'];

            if ($rxBytes !== null && $txBytes !== null) {
                $counterSample = [
                    'entity' => $entityKey,
                    'at' => $session['observed_at'],
                    'uptime' => $session['uptime_seconds'],
                    'in' => (string) $txBytes,
                    'out' => (string) $rxBytes,
                    'discontinuity' => $session['session_id'],
                ];
                $deltaRate = $trafficService->rate(is_array($previous) ? $previous : null, $counterSample);
                if ($deltaRate !== null) {
                    $rxBps = $deltaRate['download_bps'];
                    $txBps = $deltaRate['upload_bps'];
                    if ($rxBps == 0.0 && $session['queue_rx_bps'] !== null && $session['queue_rx_bps'] > 0) {
                        $rxBps = $session['queue_rx_bps'];
                    }
                    if ($txBps == 0.0 && $session['queue_tx_bps'] !== null && $session['queue_tx_bps'] > 0) {
                        $txBps = $session['queue_tx_bps'];
                    }
                }
                if (! is_array($previous) || $counterSample['at'] > ($previous['at'] ?? 0)) {
                    Cache::put($counterKey, $counterSample, 900);
                }
            }

            $rxBps = max(0.0, (float) ($rxBps ?? 0.0));
            $txBps = max(0.0, (float) ($txBps ?? 0.0));

            $row = [
                'tenant_id' => $olt->tenant_id,
                'olt_id' => $olt->id,
                'onu_id' => $onu->id,
                'source' => 'cli',
                'rx_bps' => $rxBps,
                'tx_bps' => $txBps,
                'download_bps' => $rxBps,
                'upload_bps' => $txBps,
                'rx_bytes' => $rxBytes !== null ? (string) $rxBytes : null,
                'tx_bytes' => $txBytes !== null ? (string) $txBytes : null,
                'recorded_at' => $stamp,
                'observed_at' => $stamp,
            ];

            $rowsToInsert[] = $row;
            $onuUpdates[$onu->id] = [
                'download_bps' => $rxBps,
                'upload_bps' => $txBps,
                'traffic_observed_at' => $stamp,
                'pppoe_user' => $session['name'],
            ];

            $sampleOut = $row;
            $sampleOut['recorded_at'] = $isoStamp;
            $sampleOut['observed_at'] = $isoStamp;
            $sampleOut['pppoe_user'] = $session['name'];
            $sampleOut['router'] = $session['router_name'];
            $samplesByOnuId[$onu->id] = $sampleOut;
        }

        DB::transaction(function () use ($olt, $rowsToInsert, $onuUpdates, $stamp) {
            foreach (array_chunk($rowsToInsert, 100) as $chunk) {
                DB::table('onu_traffic_logs')->insert($chunk);
            }
            foreach ($onuUpdates as $onuId => $attrs) {
                Onu::whereKey($onuId)->where('olt_id', $olt->id)->update($attrs);
            }
            Olt::whereKey($olt->id)->update(['traffic_observed_at' => $stamp]);
        });

        foreach ($samplesByOnuId as $onuId => $sampleOut) {
            Cache::put($this->recentOnuKey($olt->id, $onuId), [
                'at' => microtime(true),
                'data' => $sampleOut,
            ], $ttl);
        }

        return ['samples' => $samplesByOnuId, 'warnings' => $warnings];
    }

    private function recentOnuKey(int $oltId, int $onuId): string
    {
        return "nms:mikrotik-onu-recent:{$oltId}:{$onuId}";
    }

    private function recentOnuSample(Onu $onu): ?array
    {
        $recent = Cache::get($this->recentOnuKey($onu->olt_id, $onu->id));
        $interval = max(10, min(60, (int) config('olt.yotc_traffic_live_interval', 15)));
        return is_array($recent) && (microtime(true) - ($recent['at'] ?? 0)) < $interval ? $recent['data'] : null;
    }

    public function fetchRoutersSnapshot(Olt $olt): array
    {
        $cacheKey = 'nms:mikrotik-snapshot:'.$olt->id;
        $ttl = max(5, min(60, (int) config('olt.mikrotik_snapshot_ttl', 12)));
        $cached = Cache::get($cacheKey);
        if (is_array($cached) && (microtime(true) - ($cached['at'] ?? 0)) < $ttl) {
            return $cached['data'];
        }

        $routers = $this->configuredRouters();
        $allSessions = [];
        $warnings = [];

        foreach ($routers as $routerKey => $cfg) {
            try {
                $routerSessions = $this->readSingleRouter($olt, $cfg);
                foreach ($routerSessions as $s) {
                    $allSessions[] = $s;
                }
            } catch (\Throwable $e) {
                $warnings[] = 'MikroTik '.$cfg['name'].': '.$e->getMessage();
            }
        }

        $data = ['sessions' => $allSessions, 'warnings' => $warnings];
        if (! empty($allSessions)) {
            Cache::put($cacheKey, ['at' => microtime(true), 'data' => $data], $ttl);
        }
        return $data;
    }

    protected function readSingleRouter(Olt $olt, array $cfg): array
    {
        $client = app(MikrotikWinboxClient::class);
        $routerKey = $cfg['key'];
        $stateKey = 'nms:mikrotik-router-warm:'.$olt->id.':'.$routerKey;
        $isWarm = Cache::has($stateKey);

        try {
            $client->connect($cfg['host'], $cfg['port'], $cfg['user'], $cfg['password'], $cfg['timeout']);

            $activeCmd = '/ppp active print detail without-paging';
            if (! empty($cfg['service_filter'])) {
                $activeCmd .= ' where service="'.$cfg['service_filter'].'"';
            }
            $activeRaw = $client->runCommand($activeCmd, $cfg['timeout'], 0.25);

            $ifaceCmd = '/interface print stats without-paging where type="pppoe-in"';
            $t1 = microtime(true);
            $ifaceRaw1 = $client->runCommand($ifaceCmd, $cfg['timeout'], 0.25);

            $ifaceStats2 = null;
            $t2 = $t1;
            if (! $isWarm) {
                usleep(1000000);
                $t2 = microtime(true);
                $ifaceRaw2 = $client->runCommand($ifaceCmd, $cfg['timeout'], 0.25);
                $ifaceStats2 = $this->parseInterfaceStats($ifaceRaw2);
            }

            $queueRaw = $client->runCommand('/queue simple print stats without-paging', $cfg['timeout'], 0.25);
            Cache::put($stateKey, true, 600);
        } finally {
            $client->close();
        }

        $activeList = $this->parsePppActiveDetail($activeRaw);
        $ifaceStats1 = $this->parseInterfaceStats($ifaceRaw1);
        $queueStats = $this->parseQueueStats($queueRaw);

        $sessions = [];
        foreach ($activeList as $item) {
            $name = $item['name'] ?? '';
            if ($name === '') {
                continue;
            }
            $if1 = $ifaceStats1[$name] ?? null;
            $if2 = $ifaceStats2 !== null ? ($ifaceStats2[$name] ?? null) : null;
            $q = $queueStats[$name] ?? null;

            $ifFinal = $if2 ?? $if1;
            // On MikroTik PPPoE interface: TX-BYTE = router to ONU (download), RX-BYTE = ONU to router (upload)
            $rxBytes = $ifFinal['tx_byte'] ?? ($q['dl_bytes'] ?? null);
            $txBytes = $ifFinal['rx_byte'] ?? ($q['ul_bytes'] ?? null);

            $queueRxBps = $q['dl_bps'] ?? null;
            $queueTxBps = $q['ul_bps'] ?? null;

            $rxBps = $queueRxBps ?? 0.0;
            $txBps = $queueTxBps ?? 0.0;

            if ($if1 !== null && $if2 !== null && ($t2 - $t1) >= 0.5) {
                $dt = $t2 - $t1;
                $dRx = (float) $if2['tx_byte'] - (float) $if1['tx_byte'];
                $dTx = (float) $if2['rx_byte'] - (float) $if1['rx_byte'];
                if ($dRx >= 0 && $dTx >= 0) {
                    $rxBps = ($dRx * 8.0) / $dt;
                    $txBps = ($dTx * 8.0) / $dt;
                    if ($rxBps == 0.0 && $queueRxBps !== null && $queueRxBps > 0) {
                        $rxBps = $queueRxBps;
                    }
                    if ($txBps == 0.0 && $queueTxBps !== null && $queueTxBps > 0) {
                        $txBps = $queueTxBps;
                    }
                }
            }

            $callerHex = $this->normalizeMacHex($item['caller-id'] ?? '');
            $nameMacHex = null;
            if (preg_match('/^([0-9A-Fa-f]{12})(?:@|$|-)/', $name, $nm)) {
                $nameMacHex = strtoupper($nm[1]);
            }

            $sessions[] = [
                'router_key' => $routerKey,
                'router_name' => $cfg['name'],
                'name' => $name,
                'service' => $item['service'] ?? '',
                'caller_id' => $item['caller-id'] ?? '',
                'caller_hex' => $callerHex,
                'caller_int' => $callerHex !== null ? hexdec($callerHex) : null,
                'name_mac_hex' => $nameMacHex,
                'name_mac_int' => $nameMacHex !== null ? hexdec($nameMacHex) : null,
                'address' => $item['address'] ?? '',
                'uptime' => $item['uptime'] ?? '',
                'uptime_seconds' => $this->parseUptimeSeconds($item['uptime'] ?? ''),
                'session_id' => $item['session-id'] ?? null,
                'comment' => $item['comment'] ?? '',
                'rx_bytes' => $rxBytes,
                'tx_bytes' => $txBytes,
                'rx_bps' => $rxBps,
                'tx_bps' => $txBps,
                'queue_rx_bps' => $queueRxBps,
                'queue_tx_bps' => $queueTxBps,
                'observed_at' => $t2,
            ];
        }

        return $sessions;
    }

    public function matchOnusToSessions(iterable $onus, array $sessions): array
    {
        $onuList = [];
        foreach ($onus as $onu) {
            if (isset($onu->phase_state) && $onu->phase_state !== 'working') {
                continue;
            }
            $snHex = $this->normalizeMacHex((string) $onu->sn);
            $onuList[$onu->id] = [
                'onu' => $onu,
                'sn_hex' => $snHex,
                'sn_int' => $snHex !== null ? hexdec($snHex) : null,
            ];
        }

        $sessionsByName = [];
        foreach ($sessions as $idx => $s) {
            $key = strtolower($s['name']);
            if (! isset($sessionsByName[$key]) || $this->sessionScore($s) > $this->sessionScore($sessionsByName[$key]['session'])) {
                $sessionsByName[$key] = ['idx' => $idx, 'session' => $s];
            }
        }

        $matches = [];
        $usedSessions = [];

        // Tier 1: Multi-pass MAC offset matching (0 first, then ±1..±8) so exact matches claim their session first
        foreach (self::MAC_OFFSETS as $targetOffset) {
            foreach ($onuList as $onuId => $info) {
                if (isset($matches[$onuId]) || $info['sn_int'] === null) {
                    continue;
                }
                $snInt = $info['sn_int'];
                $bestIdx = null;
                $bestSession = null;

                foreach ($sessions as $sIdx => $s) {
                    if (isset($usedSessions[$sIdx])) {
                        continue;
                    }
                    foreach (['caller_int', 'name_mac_int'] as $macField) {
                        $pInt = $s[$macField];
                        if ($pInt === null || (($pInt >> 24) !== ($snInt >> 24))) {
                            continue;
                        }
                        if (($pInt - $snInt) === $targetOffset) {
                            if ($bestSession === null || $this->sessionScore($s) > $this->sessionScore($bestSession)) {
                                $bestSession = $s;
                                $bestIdx = $sIdx;
                            }
                        }
                    }
                }

                if ($bestSession !== null && $bestIdx !== null) {
                    $matches[$onuId] = ['onu' => $info['onu'], 'session' => $bestSession, 'tier' => 'mac'];
                    $usedSessions[$bestIdx] = true;
                }
            }
        }

        // Tier 2: Existing pppoe_user column on ONU
        foreach ($onuList as $onuId => $info) {
            if (isset($matches[$onuId])) {
                continue;
            }
            $onu = $info['onu'];
            if (! empty($onu->pppoe_user)) {
                $lookup = strtolower(trim((string) $onu->pppoe_user));
                if (isset($sessionsByName[$lookup])) {
                    $matches[$onuId] = ['onu' => $onu, 'session' => $sessionsByName[$lookup]['session'], 'tier' => 'pppoe_user'];
                    $usedSessions[$sessionsByName[$lookup]['idx']] = true;
                }
            }
        }

        // Tier 3: Known historical /ppp secret MAC bridge map
        foreach ($onuList as $onuId => $info) {
            if (isset($matches[$onuId])) {
                continue;
            }
            $snHex = $info['sn_hex'];
            if ($snHex !== null && isset(self::KNOWN_ONU_PPPOE_MAP[$snHex])) {
                $knownUser = strtolower(self::KNOWN_ONU_PPPOE_MAP[$snHex]);
                if (isset($sessionsByName[$knownUser])) {
                    $matches[$onuId] = ['onu' => $info['onu'], 'session' => $sessionsByName[$knownUser]['session'], 'tier' => 'known_map'];
                    $usedSessions[$sessionsByName[$knownUser]['idx']] = true;
                }
            }
        }

        // Tier 4: Customer name / comment match
        foreach ($onuList as $onuId => $info) {
            if (isset($matches[$onuId])) {
                continue;
            }
            $onu = $info['onu'];
            $normOnu = $this->normalizeCustomerLabel((string) ($onu->name ?: $onu->description));
            if (strlen($normOnu) < 4 || preg_match('/^[0-9a-f]{12}$/', $normOnu)) {
                continue;
            }
            $bestNameSession = null;
            $bestNameIdx = null;
            foreach ($sessions as $sIdx => $s) {
                if (isset($usedSessions[$sIdx])) {
                    continue;
                }
                $normComment = $this->normalizeCustomerLabel(explode(',', (string) ($s['comment'] ?? ''))[0] ?? '');
                $normPppName = $this->normalizeCustomerLabel(explode('@', (string) $s['name'])[0] ?? '');
                $matched = false;
                if ($normComment !== '' && strlen($normComment) >= 4) {
                    if ($normOnu === $normComment || (strlen($normOnu) >= 6 && (str_contains($normOnu, $normComment) || str_contains($normComment, $normOnu)))) {
                        $matched = true;
                    }
                }
                if (! $matched && $normPppName !== '' && strlen($normPppName) >= 4) {
                    if ($normOnu === $normPppName || (strlen($normOnu) >= 6 && (str_contains($normOnu, $normPppName) || str_contains($normPppName, $normOnu)))) {
                        $matched = true;
                    }
                }
                if ($matched && ($bestNameSession === null || $this->sessionScore($s) > $this->sessionScore($bestNameSession))) {
                    $bestNameSession = $s;
                    $bestNameIdx = $sIdx;
                }
            }
            if ($bestNameSession !== null && $bestNameIdx !== null) {
                $matches[$onuId] = ['onu' => $onu, 'session' => $bestNameSession, 'tier' => 'name'];
                $usedSessions[$bestNameIdx] = true;
            }
        }

        return $matches;
    }

    private function sessionScore(array $s): float
    {
        $bps = (float) ($s['rx_bps'] ?? 0) + (float) ($s['tx_bps'] ?? 0);
        $bytes = (float) ($s['rx_bytes'] ?? 0) + (float) ($s['tx_bytes'] ?? 0);
        return ($bps * 1000.0) + ($bytes > 0 ? log10($bytes + 1) : 0.0);
    }

    public function parsePppActiveDetail(string $raw): array
    {
        $lines = preg_split('/\r?\n/', $raw) ?: [];
        $entries = [];
        $current = null;
        $pendingComment = '';

        foreach ($lines as $line) {
            $line = preg_replace('/^.*\r/', '', $line) ?? $line;
            $trim = trim($line);
            if ($trim === '' || str_starts_with($trim, '[') || str_starts_with($trim, 'Flags:') || str_starts_with($trim, '/ppp')) {
                continue;
            }
            if (str_starts_with($trim, ';;;')) {
                $pendingComment = trim($pendingComment.' '.trim(substr($trim, 3)));
                continue;
            }
            if (preg_match('/^\d+\s+(?:[A-Z]+\s+)?;;;\s*(.*)$/', $trim, $cm)) {
                if ($current !== null) {
                    $entries[] = $current;
                }
                $current = ['comment' => trim($cm[1]), '_raw' => ''];
                $pendingComment = '';
                continue;
            }
            if (preg_match('/^\d+\s+(?:[A-Z]+\s+)?(.*)$/', $trim, $m)) {
                if ($current !== null) {
                    $entries[] = $current;
                }
                $current = ['comment' => $pendingComment, '_raw' => $m[1]];
                $pendingComment = '';
            } elseif ($current !== null) {
                if (str_contains($trim, '=')) {
                    $current['_raw'] .= ' '.$trim;
                } elseif ($current['comment'] !== '') {
                    $current['comment'] .= ' '.$trim;
                }
            }
        }
        if ($current !== null) {
            $entries[] = $current;
        }

        $result = [];
        foreach ($entries as $e) {
            $item = ['comment' => trim($e['comment'])];
            if (preg_match_all('/([a-zA-Z0-9-]+)=("[^"]*"|\S+)/', $e['_raw'], $matches, PREG_SET_ORDER)) {
                foreach ($matches as $m) {
                    $item[$m[1]] = trim($m[2], '"');
                }
            }
            if (! empty($item['name'])) {
                $result[] = $item;
            }
        }
        return $result;
    }

    public function parseInterfaceStats(string $raw): array
    {
        $lines = preg_split('/\r?\n/', $raw) ?: [];
        $stats = [];
        $rxEnd = null;
        $txEnd = null;

        foreach ($lines as $line) {
            $line = preg_replace('/^.*\r/', '', $line) ?? $line;
            if (str_contains($line, 'RX-BYTE') && str_contains($line, 'TX-BYTE')) {
                $rxEnd = strpos($line, 'RX-BYTE') + strlen('RX-BYTE');
                $txEnd = strpos($line, 'TX-BYTE') + strlen('TX-BYTE');
                continue;
            }
            if (preg_match('/name="?(<pppoe-[^">]+>)"?.*?\brx-byte=([0-9 ]+)\s+tx-byte=([0-9 ]+)/i', $line, $kv)) {
                $pppUser = substr($kv[1], 7, -1);
                $stats[$pppUser] = [
                    'rx_byte' => preg_replace('/\D+/', '', $kv[2]) ?: '0',
                    'tx_byte' => preg_replace('/\D+/', '', $kv[3]) ?: '0',
                ];
                continue;
            }
            if ($rxEnd !== null && $txEnd !== null && preg_match('/^\s*\d+\s+[A-Z]+\s+(<pppoe-[^>]+>)/', $line, $m, PREG_OFFSET_CAPTURE)) {
                $tag = $m[1][0];
                $pppUser = substr($tag, 7, -1);
                $nameEnd = $m[1][1] + strlen($tag);
                if (strlen($line) >= $txEnd && $nameEnd < $rxEnd) {
                    $rxStr = substr($line, $nameEnd, $rxEnd - $nameEnd);
                    $txStr = substr($line, $rxEnd, $txEnd - $rxEnd);
                    $rxClean = preg_replace('/\D+/', '', $rxStr);
                    $txClean = preg_replace('/\D+/', '', $txStr);
                    if ($rxClean !== '' && $txClean !== '') {
                        $stats[$pppUser] = [
                            'rx_byte' => $rxClean,
                            'tx_byte' => $txClean,
                        ];
                        continue;
                    }
                }
            }
            if (preg_match('/^\s*\d+\s+[A-Z]+\s+<pppoe-([^>]+)>\s+([0-9][0-9 ]*?)\s{2,}([0-9][0-9 ]*?)\s{2,}/', $line, $m2)) {
                $stats[$m2[1]] = [
                    'rx_byte' => preg_replace('/\D+/', '', $m2[2]) ?: '0',
                    'tx_byte' => preg_replace('/\D+/', '', $m2[3]) ?: '0',
                ];
            }
        }

        return $stats;
    }

    public function parseQueueStats(string $raw): array
    {
        $lines = preg_split('/\r?\n/', $raw) ?: [];
        $blocks = [];
        $current = '';

        foreach ($lines as $line) {
            $line = preg_replace('/^.*\r/', '', $line) ?? $line;
            $trim = trim($line);
            if ($trim === '' || str_starts_with($trim, 'Flags:') || str_starts_with($trim, '[') || str_starts_with($trim, '/queue')) {
                continue;
            }
            if (preg_match('/^\d+\s+[A-Z]*\s*name=/', $trim)) {
                if ($current !== '') {
                    $blocks[] = $current;
                }
                $current = $trim;
            } elseif ($current !== '') {
                $current .= ' '.$trim;
            }
        }
        if ($current !== '') {
            $blocks[] = $current;
        }

        $queues = [];
        foreach ($blocks as $b) {
            if (! preg_match('/name="<pppoe-([^>]+)>"/', $b, $nm)) {
                continue;
            }
            $user = $nm[1];
            $rateStr = preg_match('/\brate=([^\s]+)/', $b, $rm) ? $rm[1] : '0bps/0bps';
            $bytesStr = preg_match('/\bbytes=([0-9]+)\/([0-9]+)/', $b, $bm) ? [$bm[1], $bm[2]] : [null, null];
            $rateParts = explode('/', $rateStr);
            $ulBps = $this->parseRateToBps($rateParts[0] ?? '0bps');
            $dlBps = $this->parseRateToBps($rateParts[1] ?? '0bps');

            $baseUser = preg_replace('/-\d+$/', '', $user) ?? $user;
            foreach (array_unique([$user, $baseUser]) as $targetUser) {
                if (! isset($queues[$targetUser]) || ($dlBps + $ulBps) >= ($queues[$targetUser]['dl_bps'] + $queues[$targetUser]['ul_bps'])) {
                    $queues[$targetUser] = [
                        'ul_bps' => $ulBps,
                        'dl_bps' => $dlBps,
                        'ul_bytes' => $bytesStr[0],
                        'dl_bytes' => $bytesStr[1],
                    ];
                }
            }
        }

        return $queues;
    }

    public function parseRateToBps(string $s): float
    {
        $s = trim($s);
        if (preg_match('/^([0-9.]+)\s*(bps|kbps|Mbps|Gbps)$/i', $s, $m)) {
            $v = (float) $m[1];
            return match (strtolower($m[2])) {
                'gbps' => $v * 1000000000.0,
                'mbps' => $v * 1000000.0,
                'kbps' => $v * 1000000.0 / 1000.0,
                default => $v,
            };
        }
        return 0.0;
    }

    public function parseUptimeSeconds(string $uptime): int
    {
        if ($uptime === '') {
            return 0;
        }
        $seconds = 0;
        if (preg_match('/(\d+)w/i', $uptime, $m)) {
            $seconds += (int) $m[1] * 604800;
        }
        if (preg_match('/(\d+)d/i', $uptime, $m)) {
            $seconds += (int) $m[1] * 86400;
        }
        if (preg_match('/(\d+)h/i', $uptime, $m)) {
            $seconds += (int) $m[1] * 3600;
        }
        if (preg_match('/(\d+)m/i', $uptime, $m)) {
            $seconds += (int) $m[1] * 60;
        }
        if (preg_match('/(\d+)s/i', $uptime, $m)) {
            $seconds += (int) $m[1];
        }
        if ($seconds === 0 && preg_match('/^(\d+):(\d{2}):(\d{2})$/', $uptime, $m)) {
            $seconds = ((int) $m[1] * 3600) + ((int) $m[2] * 60) + (int) $m[3];
        }
        return $seconds;
    }

    private function normalizeMacHex(string $mac): ?string
    {
        $hex = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', $mac) ?? '');
        return strlen($hex) === 12 ? $hex : null;
    }

    private function normalizeCustomerLabel(string $s): string
    {
        $s = preg_replace('/-\d{6,}$/', '', trim($s)) ?? $s;
        $s = preg_replace('/^(RETAIL|FWW|POP|LEBAK|WINFREE|TAMSAR)-?/i', '', $s) ?? $s;
        return strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $s) ?? '');
    }
}
