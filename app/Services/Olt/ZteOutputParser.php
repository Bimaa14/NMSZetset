<?php

namespace App\Services\Olt;

class ZteOutputParser
{
    public function cards(string $output): array
    {
        $this->table($output, '/\b(?:Slot|CfgType|RealType)\b/i');
        $boards = [];
        foreach (explode("\n", $output) as $line) {
            if (! preg_match('/^\h*(\d+)\h+(\d+)\h+(?:(\d+)\h+)?([A-Z][A-Z0-9-]+)\h+([A-Z][A-Z0-9-]+)\h+(\d+|N\/A)\h+(.*?)\h+(INSERVICE|STANDBY|OFFLINE|OFFLINE\S*|ONLINE|FAIL\S*|IS|OOS)\h*$/i', $line, $m)) {
                continue;
            }
            $shelf = $m[3] !== '' ? (int) $m[2] : (int) $m[1];
            $slot = $m[3] !== '' ? (int) $m[3] : (int) $m[2];
            $type = strtoupper($m[4]);
            $ports = ctype_digit($m[6]) ? (int) $m[6] : 0;
            $realType = strtoupper($m[5]);
            $gponType = preg_match('/^(?:GTG|GFG)/', $type) ? $type : (preg_match('/^(?:GTG|GFG)/', $realType) ? $realType : null);
            foreach (config('olt.board_ports') as $prefix => $count) {
                if ($gponType !== null && str_starts_with($gponType, $prefix)) {
                    if (! $ports) {
                        $ports = $count;
                    } break;
                }
            }
            $active = in_array(strtoupper($m[8]), ['INSERVICE', 'ONLINE', 'IS'], true);
            if ($gponType !== null && $active && ! $ports) {
                $this->fail('Jumlah port board '.$gponType.' belum tersedia; isi profil olt.board_ports sebelum Sync.');
            }
            if ($ports > 32 || $shelf > 255 || $slot > 255) {
                $this->fail('Nilai slot/port board tidak valid.');
            }
            $boards[] = ['shelf' => $shelf, 'slot' => $slot, 'type' => $type, 'real_type' => $realType, 'port_count' => $ports, 'status' => strtoupper($m[8]), 'gpon' => $gponType !== null && $active];
        }
        if (! $boards) {
            $this->fail('Format show card belum dikenali; inventaris tidak diubah.');
        }

        return $boards;
    }

    public function system(string $version, string $processor = '', string $temperature = '', string $group = ''): array
    {
        $values = ['uptime' => null, 'firmware_version' => null, 'cpu' => null, 'memory_usage' => null, 'temperature' => null];
        $uptimeOutput = preg_replace('/^.*\b(?:Board|Card|Slot)\h+up\h*time\b.*$/mi', '', $version."\n".$group);
        if (preg_match('/(?:up\h*time(?:\s+is)?|started before)\s*[:=]?\h*([^\r\n]+)/i', $uptimeOutput, $m)) {
            $values['uptime'] = mb_substr(trim($m[1]), 0, 255);
        }
        foreach ([$version, $group] as $source) {
            if (preg_match('/(?:Software[\h-]*(?:Version|VER)|Firmware(?:\h+Version)?)[\s:=]*(V?\d+\.\d+[\w.()\-]*)/i', $source, $m)) {
                $values['firmware_version'] = $m[1];
            } else {
                $source = preg_replace('/^.*\b(?:BootROM|Bootloader|Boot\h+Version)\b.*$/mi', '', $source);
                if (preg_match('/\bV\d+\.\d+[\w.()\-]*/i', $source, $m)) {
                    $values['firmware_version'] = $m[0];
                }
            }
            if ($values['firmware_version'] !== null) {
                break;
            }
        }
        if (preg_match('/CPU(?:\s+(?:load|usage|utilization))?\h*[:=]\h*(\d+(?:\.\d+)?)\h*%/i', $processor, $m)) {
            $values['cpu'] = min(100, (float) $m[1]);
        } elseif (str_contains(strtolower($processor), 'cpu') && preg_match_all('/^\h*\d+(?:\h+\d+){1,2}\h+(\d+(?:\.\d+)?)%/m', $processor, $m)) {
            $values['cpu'] = min(100, max(array_map('floatval', $m[1])));
        }
        if (preg_match('/(?:memory|mem)(?:\s+(?:usage|utilization|load))?\h*[:=]\h*(\d+(?:\.\d+)?)\h*%/i', $processor, $m)) {
            $values['memory_usage'] = min(100, (float) $m[1]);
        } elseif (preg_match('/CPU\(5s\).*PhyMem.*Memory/i', $processor) && preg_match_all('/^\h*\d+(?:\h+\d+){1,2}\h+(?:\d+(?:\.\d+)?%\h+){3}\d+(?:\.\d+)?\h+(\d+(?:\.\d+)?)%\h*$/m', $processor, $m)) {
            $values['memory_usage'] = min(100, max(array_map('floatval', $m[1])));
        }
        $values['temperature'] = $this->temperature($temperature);

        return $values;
    }

    public function temperature(string $output): ?float
    {
        $values = [];
        $column = null;
        foreach (explode("\n", $output) as $line) {
            if (preg_match('/(?:temperature|temp)(?:\h+(?:of\h+)?(?:chassis|board))?\h*[:=]\h*(-?\d+(?:\.\d+)?)/i', $line, $m)) {
                $values[] = (float) $m[1];
            }
            $cells = preg_split('/\h+/', trim(preg_replace('/\([^)]*\)/', '', $line)));
            foreach ($cells as $index => $cell) {
                if (preg_match('/^(?:temp|temperature)(?:\[.*\])?$/i', $cell)) {
                    $column = $index;

                    continue 2;
                }
            }
            if ($column !== null && isset($cells[$column]) && preg_match('/^(-?\d+(?:\.\d+)?)(?:°?C)?$/i', $cells[$column], $m)) {
                $values[] = (float) $m[1];
            }
        }
        $values = array_filter($values, fn ($value) => $value >= -40 && $value <= 125);

        return $values ? max($values) : null;
    }

    public function globalStates(string $output): array
    {
        $this->table($output, '/Onu\s*Index|OnuIndex|ONU\s+ID/i');
        $groups = [];
        $count = 0;
        foreach (explode("\n", $output) as $line) {
            if (! preg_match('/^\h*(?:gpon-onu_)?(\d+\/\d+\/\d+):\d+\h+/', $line, $m)) {
                continue;
            }
            $groups['gpon-olt_'.$m[1]][] = $line;
            $count++;
        }
        $this->verifyRows($output, $count);
        if (! $count && ! $this->empty($output)) {
            $this->fail('State global kosong tanpa bukti jumlah ONU nol; gunakan polling per-port.');
        }
        if ($count > config('olt.max_onus')) {
            $this->fail('Jumlah ONU global melebihi batas.');
        }
        $result = [];
        foreach ($groups as $port => $lines) {
            $result[$port] = $this->states("OnuIndex Admin State OMCC State Phase State\n".implode("\n", $lines)."\nTotal: ".count($lines), $port);
        }

        return $result;
    }

    public function states(string $output, string $port): array
    {
        // A valid CLI prompt with no table payload is an empty port response.
        if (trim($output) === '') {
            return [];
        }
        $this->table($output, '/Onu\s*Index|OnuIndex|ONU\s+ID|^\h*(?:gpon-onu_)?\d+\/\d+\/\d+:\d+\h+/mi');
        $rows = [];
        foreach (explode("\n", $output) as $line) {
            if (! $r = $this->row($line, $port)) {
                continue;
            }
            $parts = preg_split('/\s+/', trim($r[1]));
            if (count($parts) < 3) {
                $this->fail('Baris state ONU tidak lengkap.');
            }
            $phase = strtolower($parts[2]);
            $phase = match (str_replace(['-', '_'], '', $phase)) {
                'working', 'online' => 'working',
                'los' => 'los',
                'dyinggasp' => 'dying-gasp',
                'offline', 'offline/online' => 'offline',
                'syncmib' => 'sync-mib',
                'logging' => 'logging',
                'auth' => 'auth',
                default => $this->fail('Phase State ONU belum dikenali: '.mb_substr($phase, 0, 40).'.'),
            };
            if (isset($rows[$r[0]])) {
                $this->fail('Index ONU ganda dalam tabel state.');
            }
            $rows[$r[0]] = ['admin_state' => $parts[0], 'omcc_state' => $parts[1], 'phase_state' => $phase];
        }
        $this->verifyRows($output, count($rows));

        return $rows;
    }

    public function baseInfo(string $output, string $port): array
    {
        $this->table($output, '/Onu\s*Index|OnuIndex|ONU\s+ID/i');
        $rows = [];
        $columns = [];
        foreach (explode("\n", $output) as $line) {
            if (preg_match('/^\h*(?:Onu\h*Index|ONU\h+ID)\b/i', $line)) {
                $header = preg_replace(['/Onu\h+Index/i', '/ONU\h+ID/i', '/Serial\h+Number/i', '/ONU\h+Type/i'], ['OnuIndex', 'OnuIndex', 'SN', 'ONUType'], trim($line));
                $columns = array_map('strtolower', preg_split('/\h+/', $header));

                continue;
            }
            if (! $r = $this->row($line, $port)) {
                continue;
            }
            $cells = preg_split('/\h+/', trim($r[1]));
            $snColumn = array_search('sn', $columns, true);
            if ($snColumn !== false && $snColumn > 0) {
                $serial = trim($cells[$snColumn - 1] ?? '', '"');
            } else {
                // Never select the first substring: an ONU model or customer name can resemble a serial.
                $candidates = array_values(array_filter($cells, fn ($cell) => preg_match('/^(?:[A-Z]{4}[A-Z0-9]{6,16}|[A-F0-9]{16})$/i', $cell)));
                if (count($candidates) !== 1) {
                    $this->fail('Kolom SN baseinfo ambigu; gunakan running-config untuk memverifikasi identitas ONU.');
                }
                $serial = $candidates[0];
            }
            if (! preg_match('/^(?:[A-Z]{4}[A-Z0-9]{6,16}|[A-F0-9]{16})$/i', $serial)) {
                $this->fail('Serial number ONU tidak dapat dibaca.');
            }
            $sn = strtoupper($serial);
            if (preg_match('/^[A-F0-9]{16}$/', $sn) && preg_match('/^[A-Z]{4}$/', hex2bin(substr($sn, 0, 8)))) {
                $sn = hex2bin(substr($sn, 0, 8)).substr($sn, 8);
            }
            $typeColumn = array_search('type', $columns, true);
            if ($typeColumn === false) {
                $typeColumn = array_search('onutype', $columns, true);
            }
            $type = $typeColumn !== false && $typeColumn > 0 ? ($cells[$typeColumn - 1] ?? '') : ($columns ? '' : $cells[0]);
            $nameColumn = array_search('name', $columns, true);
            $name = $nameColumn !== false && $nameColumn > 0 ? implode(' ', array_slice($cells, $nameColumn - 1)) : $sn;
            $rows[$r[0]] = ['sn' => $sn, 'onu_type' => mb_substr($type, 0, 255), 'name' => $name !== '' && $name !== '-' ? mb_substr($name, 0, 255) : $sn];
        }
        $this->verifyRows($output, count($rows));

        return $rows;
    }

    public function power(string $output, string $port): array
    {
        $this->table($output, '/Onu\s*Index|OnuIndex|ONU\s+ID|Rx\s*power|Power\s*\(dBm\)/i');
        $rows = [];
        foreach (explode("\n", $output) as $line) {
            if (! $r = $this->row($line, $port)) {
                continue;
            }
            if (preg_match('/(?:N\/A|--|no\s+signal|offline|LOS)/i', $r[1])) {
                $value = null;
            } elseif (preg_match('/^\h*(-?\d+(?:\.\d+)?)(?:\h*(?:\(?(?:dBm)?\)?))?\h*$/i', $r[1], $m)) {
                $value = (float) $m[1];
                if ($value < -100 || $value > 10) {
                    $value = null;
                }
            } else {
                $this->fail('Format redaman optik belum dikenali.');
            }
            $rows[$r[0]] = $value;
        }
        $this->verifyRows($output, count($rows));

        return $rows;
    }

    public function configuredOnus(string $output, string $port): array
    {
        ZteTelnetClient::assertAccepted($output);
        if (! preg_match('/^\h*interface\h+'.preg_quote($port, '/').'\h*$/mi', $output)) {
            $this->fail('Running-config tidak sesuai interface GPON yang diminta.');
        }
        $rows = [];
        foreach (explode("\n", $output) as $line) {
            if (! preg_match('/^\h*onu\h+\d+\b/i', $line)) {
                continue;
            }
            if (! preg_match('/^\h*onu\h+(\d+)\h+type\h+(\S+)\h+sn\h+"?([A-Z]{4}[A-Z0-9]{6,16}|[A-F0-9]{16})"?\h*$/i', $line, $m)) {
                $this->fail('Baris konfigurasi ONU tidak lengkap atau SN belum dikenali.');
            }
            $index = (int) $m[1];
            if ($index < 1 || $index > 256 || isset($rows[$index])) {
                $this->fail('Index ONU tidak valid atau ganda dalam running-config.');
            }
            $sn = strtoupper($m[3]);
            if (preg_match('/^[A-F0-9]{16}$/', $sn) && preg_match('/^[A-Z]{4}$/', hex2bin(substr($sn, 0, 8)))) {
                $sn = hex2bin(substr($sn, 0, 8)).substr($sn, 8);
            }
            $rows[$index] = ['sn' => $sn, 'onu_type' => mb_substr($m[2], 0, 255), 'name' => $sn];
        }
        $result = [];
        foreach ($rows as $index => $row) {
            $result[str_replace('gpon-olt_', '', $port).':'.$index] = $row;
        }

        return $result;
    }

    public function onuLabels(string $output): array
    {
        ZteTelnetClient::assertAccepted($output);
        $labels = [];
        $interface = null;
        foreach (explode("\n", $output) as $line) {
            if (preg_match('/^\h*interface\h+gpon-onu_(\d+\/\d+\/\d+:\d+)\h*$/i', $line, $m)) {
                $interface = $m[1];

                continue;
            }
            if (preg_match('/^\h*(?:!|exit|interface\b|pon-onu-mng\b)/i', $line)) {
                $interface = null;

                continue;
            }
            if ($interface !== null && preg_match('/^\h*(name|description)\h+(.+?)\h*$/i', $line, $m)) {
                $value = trim($m[2]);
                if (strlen($value) >= 2 && (($value[0] === '"' && str_ends_with($value, '"')) || ($value[0] === "'" && str_ends_with($value, "'")))) {
                    $value = substr($value, 1, -1);
                }
                if ($value === '' || $value === '-') {
                    continue;
                }
                $field = strtolower($m[1]) === 'name' ? 'device_name' : 'device_description';
                $labels[$interface][$field] = mb_substr($value, 0, $field === 'device_name' ? 255 : 4096);
            }
        }

        return $labels;
    }

    public function onuDetailLabels(string $output, string $index, string $sn): array
    {
        ZteTelnetClient::assertAccepted($output);
        if (! preg_match('/^\h*ONU interface:\h*gpon-onu_'.preg_quote($index, '/').'\h*$/mi', $output)) {
            $this->fail('Interface detail ONU tidak cocok.');
        }
        if (! preg_match('/^\h*Serial number:\h*(\S+)\h*$/mi', $output, $serial) || strtoupper($serial[1]) !== strtoupper($sn)) {
            $this->fail('Serial detail ONU tidak cocok dengan inventaris.');
        }
        $labels = [];
        foreach (['Name' => 'device_name', 'Description' => 'device_description'] as $field => $key) {
            if (preg_match('/^\h*'.$field.':\h*(.*?)\h*$/mi', $output, $m) && ! in_array(trim($m[1]), ['', '-', 'N/A', $sn], true)) {
                $labels[$key] = mb_substr(trim($m[1]), 0, $key === 'device_name' ? 255 : 4096);
            }
        }

        return $labels;
    }

    private function row(string $line, string $port): ?array
    {
        if (! preg_match('/^\h*(?:(?:gpon-onu_)?(\d+\/\d+\/\d+):)?(\d+)\h+(.+?)\h*$/i', $line, $m)) {
            return null;
        }
        $index = ($m[1] ?: str_replace('gpon-olt_', '', $port)).':'.$m[2];
        if (! str_starts_with($index, str_replace('gpon-olt_', '', $port).':') || (int) $m[2] < 1 || (int) $m[2] > 256) {
            $this->fail('Interface ONU tidak sesuai port yang diminta.');
        }

        return [$index, $m[3]];
    }

    private function table(string $output, string $header): void
    {
        if (! ZteTelnetClient::emptyOnuResponse($output)) {
            ZteTelnetClient::assertAccepted($output);
        }
        if (! preg_match($header, $output) && ! $this->empty($output)) {
            $this->fail('Header tabel OLT tidak dikenali; snapshot lama dipertahankan.');
        }
    }

    private function empty(string $output): bool
    {
        $totals = $this->totals($output);
        if ($totals) {
            return count(array_filter($totals, fn ($total) => $total !== 0)) === 0;
        }

        return ZteTelnetClient::emptyOnuResponse($output) || (bool) preg_match('/no (?:related|matching|ONU|information|entries)/i', $output);
    }

    private function totals(string $output): array
    {
        preg_match_all('/^\h*(?:ONU\h+Number|Total(?:\h+(?:ONUs?(?:\h+Number)?|number(?:\h+of\h+ONUs?)?|entries))?)\h*[:=]\h*(\d+)(?:\h*\/\h*(\d+))?\b/im', $output, $matches, PREG_SET_ORDER);
        $totals = [];
        foreach ($matches as $match) {
            $total = isset($match[2]) && $match[2] !== '' ? (int) $match[2] : (int) $match[1];
            if (isset($match[2]) && (int) $match[1] > $total) {
                $this->fail('Rekap ONU online melebihi total perangkat.');
            }
            $totals[] = $total;
        }

        return $totals;
    }

    private function verifyRows(string $output, int $count): void
    {
        preg_match_all('/^\h*(?:gpon-onu_)?\d+(?:\/\d+\/\d+:\d+)?\h+.+$/m', $output, $candidates);
        if (count($candidates[0]) !== $count) {
            $this->fail('Sebagian baris ONU tidak dapat diurai; snapshot lama dipertahankan.');
        }
        foreach ($this->totals($output) as $total) {
            if ($total !== $count) {
                $this->fail('Jumlah baris ONU tidak sesuai total perangkat (diterima '.$count.', total '.$total.').');
            }
        }
        if (! $count && ! $this->empty($output) && preg_match('/^\h*\d/m', $output)) {
            $this->fail('Ada baris OLT yang tidak berhasil diurai.');
        }
    }

    private function fail(string $message): never
    {
        throw new OltConnectionException($message, 'parse_failed');
    }
}
