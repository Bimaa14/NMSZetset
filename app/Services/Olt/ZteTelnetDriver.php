<?php

namespace App\Services\Olt;

use App\Models\Olt;
use App\Models\Onu;

class ZteTelnetDriver
{
    public function __construct(private ZteOutputParser $parser) {}

    public function client(Olt $olt): CliClient
    {
        app(OltCapabilities::class)->requireSupported($olt);
        return app($olt->cli_transport === 'ssh' ? ZteSshClient::class : ZteTelnetClient::class);
    }

    public function test(Olt $olt): array
    {
        return $this->session($olt, function (CliClient $client) {
            $warnings = [];
            foreach (['show version-running', 'show system-group', 'show card', 'show version'] as $command) {
                try {
                    $output = $client->command($command);
                    if (trim($output) === '') {
                        continue;
                    }

                    return ['authenticated' => true, 'version' => $this->parser->system($output)['firmware_version'], 'probe_command' => $command, 'warnings' => $warnings];
                } catch (OltConnectionException $e) {
                    if ($e->reason !== 'command_rejected') {
                        throw $e;
                    }
                    $warnings[] = $e->getMessage();
                }
            }

            return ['authenticated' => true, 'version' => null, 'probe_command' => null, 'warnings' => $warnings];
        });
    }

    public function view(Olt $olt): array
    {
        return $this->session($olt, fn (CliClient $client) => $this->system($client));
    }

    private function system(CliClient $client, bool $requireBoards = false): array
    {
        $warnings = [];
        $outputs = [];
        $errors = [];
        $read = function (string $command) use ($client, &$outputs, &$warnings, &$errors): void {
            try {
                $outputs[$command] = $client->command($command);
            } catch (OltConnectionException $e) {
                if ($e->reason !== 'command_rejected') {
                    throw $e;
                }
                $errors[$command] = $e;
                $warnings[] = $e->getMessage();
            }
        };
        foreach (['show version-running', 'show system-group', 'show card'] as $command) {
            $read($command);
        }
        $version = $outputs['show version-running'] ?? '';
        $group = $outputs['show system-group'] ?? '';
        if ($this->parser->system($version, group: $group)['firmware_version'] === null) {
            $read('show version');
            $version .= "\n".($outputs['show version'] ?? '');
        }
        if (! array_filter($outputs, fn ($output) => trim($output) !== '')) {
            throw end($errors) ?: new OltConnectionException('OLT tidak mengembalikan informasi sistem.', 'parse_failed');
        }
        $card = $outputs['show card'] ?? '';
        if ($requireBoards && trim($card) === '') {
            throw $errors['show card'] ?? new OltConnectionException('Daftar board belum tersedia; inventaris tidak diubah.', 'parse_failed');
        }
        $boards = trim($card) !== '' ? $this->parser->cards($card) : [];
        $processor = $this->optional($client, 'show processor', $warnings);
        $temperature = '';
        foreach (['show card-temperature', 'show temp', 'show environment', 'show temperature'] as $command) {
            try {
                $output = $client->command($command);
                if ($this->parser->temperature($output) !== null) {
                    $temperature = $output;
                    break;
                }
            } catch (OltConnectionException $e) {
                if ($e->reason !== 'command_rejected') {
                    throw $e;
                }
            }
        }

        return ['system' => $this->parser->system($version, $processor, $temperature, $group), 'boards' => $boards, 'warnings' => $warnings];
    }

    private function optional(CliClient $client, string $command, array &$warnings): string
    {
        try {
            return $client->command($command);
        } catch (OltConnectionException $e) {
            if ($e->reason !== 'command_rejected') {
                throw $e;
            } $warnings[] = $command.' tidak didukung atau tidak diizinkan; metrik ditampilkan sebagai belum tersedia.';

            return '';
        }
    }

    public function snapshot(Olt $olt, ?callable $inventoryReady = null, string $scope = 'full', ?callable $progress = null): array
    {
        $deadline = microtime(true) + (float) config('olt.operation_timeout', 300) - (float) config('olt.save_reserve', 10);

        return $this->session($olt, function (CliClient $client) use ($olt, $inventoryReady, $deadline, $scope, $progress) {
            if ($scope === 'full') $snapshot = $this->system($client, true);
            elseif ($scope === 'inventory') {
                $snapshot = ['system'=>array_replace($this->parser->system(''), $olt->boards['metrics'] ?? []), 'boards'=>$this->parser->cards($client->command('show card')), 'warnings'=>[], 'ports'=>[]];
            } else $snapshot = $this->storedSnapshot($olt);
            if (in_array($scope, ['full','inventory'], true)) $snapshot['ports'] = [];
            $snapshot['complete_inventory'] = true;
            $onuCount = 0;
            foreach (in_array($scope, ['full','inventory'], true) ? $snapshot['boards'] : [] as $board) {
                if (! $board['gpon']) {
                    continue;
                }
                for ($number = 1; $number <= $board['port_count']; $number++) {
                    if (count($snapshot['ports']) >= config('olt.max_ports')) {
                        throw new OltConnectionException('Jumlah port melebihi batas sinkronisasi.', 'output_limit');
                    }
                    $name = 'gpon-olt_'.$board['shelf'].'/'.$board['slot'].'/'.$number;
                    if (microtime(true) >= $deadline) {
                        $snapshot['complete_inventory'] = false;
                        $snapshot['warnings'][] = 'Batas waktu inventaris mendekati habis sebelum '.$name.'. Port yang belum dibaca dipertahankan.';
                        break 2;
                    }
                    try {
                        if ($progress) $progress((int)(count($snapshot['ports']) / max(1,array_sum(array_column($snapshot['boards'],'port_count'))) * 100), 'Membaca state '.$name);
                        $states = $this->parser->states($client->command('show gpon onu state '.$name, maxDuration: $deadline - microtime(true)), $name);
                        $base = [];
                        if ($states) {
                            try {
                                $base = $this->parser->baseInfo($client->command('show gpon onu baseinfo '.$name, maxDuration: $deadline - microtime(true)), $name);
                            } catch (OltConnectionException $e) {
                                if (! in_array($e->reason, ['command_rejected', 'parse_failed'], true)) {
                                    throw $e;
                                }
                                $base = $this->parser->configuredOnus($client->command('show running-config interface '.$name, maxDuration: $deadline - microtime(true)), $name);
                            }
                        }
                    } catch (OltConnectionException $e) {
                        if (! in_array($e->reason, ['timeout', 'disconnected'], true) || ! $snapshot['ports']) {
                            throw $e;
                        }
                        $client->close();
                        $snapshot['complete_inventory'] = false;
                        $snapshot['warnings'][] = $e->getMessage().' Inventaris parsial disimpan; port yang belum dibaca dipertahankan.';
                        break 2;
                    }
                    if (array_diff_key($states, $base) || array_diff_key($base, $states)) {
                        throw new OltConnectionException('Tabel ONU berubah atau tidak lengkap saat polling. Ulangi Sync.', 'inconsistent_snapshot');
                    }
                    $onus = [];
                    foreach ($states as $index => $state) {
                        if (++$onuCount > config('olt.max_onus')) {
                            throw new OltConnectionException('Jumlah ONU melebihi batas sinkronisasi.', 'output_limit');
                        }
                        $onus[] = $state + $base[$index] + ['onu_index' => (int) explode(':', $index)[1], 'rx_power' => null, 'olt_rx_power' => null];
                    }
                    $snapshot['ports'][] = ['slot_no' => $board['slot'], 'port_no' => $number, 'interface_name' => $name, 'onus' => $onus];
                }
            }
            if (! $snapshot['complete_inventory'] && ! $snapshot['ports']) {
                throw new OltConnectionException('Waktu Sync habis sebelum inventaris port dapat dibaca.', 'timeout');
            }
            // Commit identity/status before any slow OMCI query can exhaust the request.
            if ($inventoryReady) {
                $inventoryReady($snapshot);
            }
            if (! $snapshot['complete_inventory'] || $scope === 'inventory') {
                return $snapshot;
            }
            // Names live under ONU interface blocks, independently of registration/SN tables.
            // One bulk read avoids adding a request for every subscriber (including offline ONUs).
            if ($scope !== 'optics') {
            if ($progress) $progress(5, 'Membaca nama ONU dari konfigurasi');
            $labelsImported = false;
            if (microtime(true) < $deadline) {
                try {
                    $labels = $this->parser->onuLabels($client->command('show running-config', maxDuration: $deadline - microtime(true)));
                    foreach ($snapshot['ports'] as &$port) {
                        foreach ($port['onus'] as &$onu) {
                            $key = str_replace('gpon-olt_', '', $port['interface_name']).':'.$onu['onu_index'];
                            $onu += $labels[$key] ?? [];
                            if (! empty($labels[$key])) {
                                $labelsImported = true;
                            }
                        }
                        unset($onu);
                    }
                    unset($port);
                } catch (OltConnectionException $e) {
                    if ($e->reason !== 'command_rejected') {
                        $snapshot['warnings'][] = $e->getMessage().' Inventaris tersimpan; nama yang belum terbaca dipertahankan.';
                        $client->close();

                        return $snapshot;
                    }
                }
            }
            // Some firmware omits ONU blocks from the global config. Use the proven
            // per-interface commands, prioritizing entries whose names are still unknown.
            $known = $olt->onus()->where('observation_source', 'real')->whereNotNull('device_name')->with('port')->get()
                ->mapWithKeys(fn ($onu) => [$onu->port->interface_name.':'.$onu->onu_index.'|'.$onu->sn => true])->all();
            $targets = [];
            foreach ($snapshot['ports'] as $p => $port) {
                foreach ($port['onus'] as $o => $onu) {
                    if (! empty($onu['device_name']) || $onu['name'] !== $onu['sn']) {
                        continue;
                    }
                    $key = $port['interface_name'].':'.$onu['onu_index'].'|'.$onu['sn'];
                    $targets[] = ['p' => $p, 'o' => $o, 'known' => isset($known[$key])];
                }
            }
            usort($targets, fn ($a, $b) => $a['known'] <=> $b['known']);
            $nameDeadline = min($deadline, microtime(true) + (float) config('olt.name_poll_budget', 90));
            $missing = 0;
            foreach ($targets as $targetNumber => $target) {
                if ($progress) $progress((int)($targetNumber / max(1,count($targets))*100), 'Nama ONU '.($targetNumber+1).'/'.count($targets));
                if (microtime(true) >= $nameDeadline) {
                    $snapshot['warnings'][] = 'Anggaran pembacaan nama habis. Nama yang sudah diperoleh tersimpan; Sync berikutnya mendahulukan ONU yang belum bernama.';
                    break;
                }
                $onu = &$snapshot['ports'][$target['p']]['onus'][$target['o']];
                $index = str_replace('gpon-olt_', '', $snapshot['ports'][$target['p']]['interface_name']).':'.$onu['onu_index'];
                $labels = array_intersect_key($onu, array_flip(['device_name', 'device_description']));
                foreach (['show running-config interface gpon-onu_'.$index, 'show gpon onu detail-info gpon-onu_'.$index] as $command) {
                    if (microtime(true) >= $nameDeadline) {
                        break;
                    }
                    try {
                        $output = $client->command($command, idleTimeout: (float) config('olt.name_idle_timeout', 5), maxDuration: $nameDeadline - microtime(true));
                        $found = str_starts_with($command, 'show running-config') ? ($this->parser->onuLabels($output)[$index] ?? []) : $this->parser->onuDetailLabels($output, $index, $onu['sn']);
                        $labels = array_replace($labels, $found);
                        if (! empty($labels['device_name'])) {
                            break;
                        }
                    } catch (OltConnectionException $e) {
                        if (! in_array($e->reason, ['command_rejected', 'parse_failed'], true)) {
                            $snapshot['warnings'][] = $e->getMessage().' Inventaris dan nama yang sudah diperoleh tetap tersimpan.';
                            $client->close();
                            unset($onu);

                            return $snapshot;
                        }
                    }
                }
                $onu = array_replace($onu, $labels);
                if ($labels) {
                    $labelsImported = true;
                }
                if (! $labels && ! $target['known']) {
                    $missing++;
                }
                unset($onu);
            }
            if ($missing) {
                $snapshot['warnings'][] = $missing.' ONU belum memberikan nama/deskripsi dari perangkat.';
            }
            if ($inventoryReady && $labelsImported) {
                $inventoryReady($snapshot);
            }
            }
            if ($scope === 'names') return $snapshot;
            if ($scope === 'optics') usort($snapshot['ports'],fn($a,$b)=>($a['optical_polled_at']??0)<=>($b['optical_polled_at']??0));
            foreach ($snapshot['ports'] as $portNumber => &$port) {
                if ($progress) $progress((int)($portNumber/max(1,count($snapshot['ports']))*100), 'Optik '.$port['interface_name']);
                if (! array_filter($port['onus'], fn ($onu) => $onu['phase_state'] === 'working')) {
                    $port['optical_attempted']=true;
                    continue;
                }
                $command = 'show pon power onu-rx '.$port['interface_name'];
                if (microtime(true) >= $deadline) {
                    $snapshot['warnings'][] = 'Batas waktu Sync mendekati habis; redaman yang belum dibaca ditampilkan Belum tersedia.';
                    break;
                }
                try {
                    $port['optical_attempted']=true;
                    $output = $client->command($command, idleTimeout: (float) config('olt.optical_idle_timeout', 30), maxDuration: $deadline - microtime(true));
                    $rx = $this->parser->power($output, $port['interface_name']);
                    foreach ($port['onus'] as &$onu) {
                        $key = str_replace('gpon-olt_', '', $port['interface_name']).':'.$onu['onu_index'];
                        if ($onu['phase_state'] === 'working') {
                            $onu['rx_power'] = $rx[$key] ?? null;
                        }
                    }
                    unset($onu);
                } catch (OltConnectionException $e) {
                    $snapshot['warnings'][] = $e->getMessage().' Inventaris tersimpan; redaman yang belum dibaca bernilai null.';
                    if (in_array($e->reason, ['timeout', 'disconnected', 'socket_error', 'ssh_connection'], true)) {
                        $client->close();
                        break;
                    }
                }
            }
            unset($port);

            return $snapshot;
        });
    }

    private function storedSnapshot(Olt $olt): array
    {
        $ports = $olt->ports()->current()->with(['onus'=>fn($q)=>$q->current()])->get()->map(fn($port)=>[
            'slot_no'=>$port->slot_no, 'port_no'=>$port->port_no, 'interface_name'=>$port->interface_name,'optical_polled_at'=>$port->optical_polled_at?->getTimestamp()??0,
            'onus'=>$port->onus->map(fn($onu)=>$onu->only(['onu_index','sn','name','phase_state','admin_state','omcc_state','onu_type']) + ['rx_power'=>null,'olt_rx_power'=>null])->all(),
        ])->all();
        return ['system'=>array_replace($this->parser->system(''), $olt->boards['metrics'] ?? []), 'boards'=>$olt->boards['items'] ?? [], 'warnings'=>[], 'ports'=>$ports, 'complete_inventory'=>true];
    }

    public function backup(Olt $olt): string { return $this->session($olt,fn(CliClient $client)=>$client->command('show running-config',raw:true)); }

    public function cli(Olt $olt, string $command): string
    {
        // A fresh authenticated session for each request; no command is passed to an OS shell.
        return $this->session($olt, fn (CliClient $client) => $client->command($command));
    }

    public function reboot(Onu $onu): string
    {
        $port = $onu->port;
        if (! $port || $port->olt_id !== $onu->olt_id || ! preg_match('/^gpon-olt_\d+\/\d+\/\d+$/', $port->interface_name) || $onu->onu_index < 1 || $onu->onu_index > 256) {
            throw new OltConnectionException('Interface ONU tidak valid untuk reboot.', 'configuration');
        }
        $interface = str_replace('gpon-olt_', 'gpon-onu_', $port->interface_name).':'.$onu->onu_index;

        return $this->session($onu->olt, function (CliClient $client) use ($interface, $onu, $port) {
            // Resolve identity again inside the control session, immediately before any write.
            try {
                $registered = $this->parser->baseInfo($client->command('show gpon onu baseinfo '.$port->interface_name), $port->interface_name);
            } catch (OltConnectionException $e) {
                if (!in_array($e->reason, ['command_rejected','parse_failed'], true)) throw $e;
                $registered = $this->parser->configuredOnus($client->command('show running-config interface '.$port->interface_name), $port->interface_name);
            }
            $serial = $registered[substr($interface, 9)]['sn'] ?? null;
            if (!$serial || strcasecmp($serial, $onu->sn) !== 0) {
                throw new OltConnectionException('Serial ONU pada perangkat berubah atau belum dapat diverifikasi. Jalankan Sync sebelum reboot.', 'identity_changed');
            }
            $client->command('configure terminal');
            $client->command('pon-onu-mng '.$interface);
            // Never retry a write automatically: timeout after confirmation is an uncertain outcome.
            try {
                $output = $client->command('reboot', true);
                if (preg_match(ZteTelnetClient::CONFIRM, $output)) {
                    $output = $client->command('yes');
                }
                if (! preg_match('/\b(?:success(?:ful(?:ly)?)?|accepted|rebooting|reboot\s+(?:ONU\s+)?(?:is\s+)?(?:sent|started)|OK)\b/i', $output)) {
                    throw new OltConnectionException('OLT tidak memberikan bukti penerimaan reboot. Periksa status ONU sebelum mencoba ulang.', 'reboot_uncertain');
                }
            } catch (OltConnectionException $e) {
                if ($e->reason === 'command_rejected') {
                    throw $e;
                }
                throw new OltConnectionException('Hasil reboot belum dapat dipastikan. Jangan ulangi otomatis; periksa ONU melalui Sync.', 'reboot_uncertain');
            }
            try {
                $client->command('exit');
            } catch (OltConnectionException) { /* Reboot already acknowledged. */
            }

            return $output;
        });
    }

    private function session(Olt $olt, callable $work): mixed
    {
        $client = $this->client($olt);
        try {
            $client->connect($olt);

            return $work($client);
        } finally {
            $client->close();
        }
    }
}
