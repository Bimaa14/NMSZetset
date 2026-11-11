<?php

namespace App\Services\Olt;

/** Only the fields verified on the C1600 web interface enter the NMS snapshot. */
class YotcOutputParser
{
    public function system(array $information = [], array $metrics = []): array
    {
        if ($information && ($information['productType'] ?? null) !== 'C1600') {
            $this->fail('Model perangkat bukan YOTC C1600.');
        }
        $uptime = null;
        if (isset($information['runningTime'])) {
            $ticks = $this->number($information['runningTime'], 0, PHP_INT_MAX);
            $seconds = (int) floor($ticks / 100);
            $uptime = intdiv($seconds, 86400).' hari '.intdiv($seconds % 86400, 3600).' jam '.intdiv($seconds % 3600, 60).' menit';
        }
        return [
            'cpu' => isset($metrics['cpuUsage']) ? (int) round($this->number($metrics['cpuUsage'], 0, 100)) : null,
            'memory_usage' => isset($metrics['memoryUsage']) ? $this->number($metrics['memoryUsage'], 0, 100) : null,
            'temperature' => isset($metrics['temperature']) ? $this->number($metrics['temperature'], -5000, 15000) / 100 : null,
            'uptime' => $uptime,
            'firmware_version' => isset($information['softwareVersion']) ? $this->text($information['softwareVersion'], 255) : null,
        ];
    }

    public function inventory(array $slots, array $ponPorts, array $registered): array
    {
        $boards = []; $warnings = [];
        $boardPorts = config('olt.yotc_board_ports', ['epu8a'=>8, 'scu'=>0]);
        foreach ($this->rows($slots) as $row) {
            if (! is_array($row)) $this->fail();
            $slot = $this->index($row['slotId'] ?? null, 16);
            if (isset($boards[$slot])) $this->fail('Slot duplikat pada inventaris YOTC.');
            $type = $this->text($row['installType'] ?? '', 80);
            if (! array_key_exists(strtolower($type), $boardPorts)) $this->fail('Tipe board YOTC belum diverifikasi; inventaris dipertahankan.');
            $boards[$slot] = ['shelf'=>1, 'slot'=>$slot, 'type'=>$type,
                'real_type'=>$this->text($row['expectType'] ?? '', 80), 'status'=>$this->text($row['runStatus'] ?? '', 80),
                'gpon'=>false, 'port_count'=>0];
        }
        if (! $boards) $this->fail('Daftar slot YOTC kosong; inventaris dipertahankan.');
        $ports = [];
        $rows = $this->rows($ponPorts);
        if (! $rows || count($rows) > (int) config('olt.max_ports', 256)) $this->fail('Jumlah port YOTC tidak valid.');
        foreach ($rows as $row) {
            if (! is_array($row) || ! preg_match('/^(\d{1,2})\/(\d{1,2})$/D', (string) ($row['portStr'] ?? ''), $match)) $this->fail('Alamat port YOTC tidak valid.');
            $slot = $this->index($match[1], 16);
            $port = $this->index($match[2], 32);
            $key = $slot.'/'.$port;
            if (! isset($boards[$slot]) || isset($ports[$key])) $this->fail('Slot tidak dikenal atau port YOTC duplikat.');
            $boards[$slot]['port_count']++;
            $ports[$key] = ['slot_no'=>$slot, 'port_no'=>$port, 'interface_name'=>'epon-olt_'.$key, 'onus'=>[]];
        }
        foreach ($boards as $board) {
            $expected = (int) $boardPorts[strtolower($board['type'])];
            if ($board['port_count'] !== $expected) $this->fail('Daftar port YOTC tidak lengkap untuk board terpasang; inventaris dipertahankan.');
            for ($port=1; $port<=$expected; $port++) if (! isset($ports[$board['slot'].'/'.$port])) $this->fail('Nomor port YOTC tidak lengkap; inventaris dipertahankan.');
        }
        $identities = [];
        $rows = $this->rows($registered);
        if (count($rows) > (int) config('olt.max_onus', 32768)) $this->fail('Jumlah ONU YOTC melebihi batas.');
        foreach ($rows as $row) {
            if (! is_array($row) || ! preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{1,3})$/D', (string) ($row['onuid'] ?? ''), $match)) $this->fail('Alamat ONU YOTC tidak valid.');
            $slot = $this->index($match[1], 16); $port = $this->index($match[2], 32); $onu = $this->index($match[3], 256);
            $key = $slot.'/'.$port;
            $address = $key.':'.$onu;
            if (! isset($ports[$key]) || isset($identities[$address])) $this->fail('Port ONU tidak dikenal atau alamat ONU duplikat.');
            $identities[$address] = true;
            $mac = strtoupper(preg_replace('/[:.\-]/', '', $this->text($row['mac'] ?? '', 32)));
            if (! preg_match('/^[0-9A-F]{12}$/D', $mac) || in_array($mac, ['000000000000','FFFFFFFFFFFF'], true)) $this->fail('MAC ONU YOTC tidak valid.');
            $mac = implode(':', str_split($mac, 2));
            $state = strtolower($this->text($row['status'] ?? '', 20));
            if (! preg_match('/^[a-z][a-z0-9_-]{0,19}$/D', $state)) $this->fail('Format status ONU YOTC tidak valid.');
            if (! in_array($state, ['online','poweroff','offline','restorefail'], true)) $warnings['additional_state'] = 'Status tambahan ONU YOTC dipertahankan sesuai respons perangkat.';
            $label = $this->text($row['usr_label'] ?? '', 255);
            $ports[$key]['onus'][] = ['onu_index'=>$onu, 'sn'=>$mac, 'name'=>$label !== '' ? $label : $mac,
                'device_name'=>$label !== '' ? $label : null, 'phase_state'=>$state === 'online' ? 'working' : $state,
                'admin_state'=>null, 'omcc_state'=>null, 'onu_type'=>$this->text($row['onutype'] ?? '', 80),
                'rx_power'=>null, 'olt_rx_power'=>null];
        }
        foreach ($ports as &$port) usort($port['onus'], fn ($a, $b) => $a['onu_index'] <=> $b['onu_index']);
        unset($port);
        ksort($boards, SORT_NUMERIC);
        uasort($ports, fn ($a, $b) => [$a['slot_no'],$a['port_no']] <=> [$b['slot_no'],$b['port_no']]);
        return ['boards'=>array_values($boards), 'ports'=>array_values($ports), 'complete_inventory'=>true, 'warnings'=>array_values($warnings)];
    }

    public function optical(array $response): array
    {
        $values = [];
        foreach ($this->rows($response) as $row) {
            if (! is_array($row) || count($row) % 2 !== 0) $this->fail('Format optik YOTC tidak lengkap.');
            $row = array_values($row);
            for ($i=0; $i<count($row); $i+=2) {
                $label = $this->text($row[$i], 80);
                if (! in_array($label, ['Pon received power','Onu received power'], true)) continue;
                if (array_key_exists($label, $values)) $this->fail('Nilai optik YOTC duplikat.');
                $value = $row[$i+1];
                $values[$label] = in_array(strtolower(trim((string) $value)), ['', 'n/a', 'na', '--', 'null'], true)
                    ? null : $this->number($value, -60, 15);
            }
        }
        if (! array_key_exists('Pon received power', $values) || ! array_key_exists('Onu received power', $values)) $this->fail('Daya optik YOTC belum tersedia.');
        return ['rx_power'=>$values['Onu received power'], 'olt_rx_power'=>$values['Pon received power']];
    }

    private function rows(array $response): array
    {
        if (! isset($response['data']) || ! is_array($response['data']) || ! array_is_list($response['data'])) $this->fail();
        return $response['data'];
    }

    private function index(mixed $value, int $max): int
    {
        if ((! is_int($value) && ! is_string($value)) || ! preg_match('/^\d+$/D', (string) $value)) $this->fail();
        $number = (int) $value;
        if ($number < 1 || $number > $max) $this->fail();
        return $number;
    }

    private function text(mixed $value, int $max): string
    {
        if (! is_scalar($value) || is_bool($value)) $this->fail();
        $value = trim((string) $value);
        if (mb_strlen($value) > $max || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value)) $this->fail();
        return $value;
    }

    private function number(mixed $value, float $min, float $max): float
    {
        if (! is_scalar($value) || is_bool($value) || ! is_numeric($value) || ! is_finite((float) $value) || (float) $value < $min || (float) $value > $max) $this->fail();
        return (float) $value;
    }

    private function fail(string $message = 'Format respons YOTC tidak valid; inventaris dipertahankan.'): never
    {
        throw new OltConnectionException($message, 'parse_failed');
    }
}
