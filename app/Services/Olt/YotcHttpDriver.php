<?php

namespace App\Services\Olt;

use App\Models\Olt;

/** Monitoring only. No configuration, provisioning, reboot, or arbitrary URL API. */
class YotcHttpDriver
{
    public function __construct(private YotcOutputParser $parser) {}

    public function test(Olt $olt): array
    {
        return $this->session($olt, function (YotcHttpClient $client) use ($olt) {
            $system = $this->parser->system($client->read('/action/getSystemInformation'));
            return ['authenticated'=>true, 'version'=>$system['firmware_version'],
                'probe_command'=>'/action/getSystemInformation', 'warnings'=>$this->warnings($olt)];
        });
    }

    public function view(Olt $olt): array
    {
        return $this->session($olt, function (YotcHttpClient $client) use ($olt) {
            $system = $this->parser->system($client->read('/action/getSystemInformation'), $client->read('/action/getCpuMemoryTemp'));
            $inventory = $this->parser->inventory($client->read('/action/getSlotInfo'), $client->read('/action/getPonPortInfoAll'), ['data'=>[]]);
            return ['system'=>$system, 'boards'=>$inventory['boards'], 'warnings'=>$this->warnings($olt)];
        });
    }

    public function snapshot(Olt $olt, ?callable $inventoryReady = null, string $scope = 'full', ?callable $progress = null): array
    {
        if (! in_array($scope, ['full','inventory','names','optics'], true)) {
            throw new OltConnectionException('Operasi monitoring YOTC belum didukung.', 'unsupported_operation');
        }
        $deadline = microtime(true) + max(1, (float) config('olt.operation_timeout', 300) - (float) config('olt.save_reserve', 10));
        return $this->session($olt, function (YotcHttpClient $client) use ($olt, $scope, $inventoryReady, $progress, $deadline) {
            if ($scope === 'optics') {
                $snapshot = $this->storedSnapshot($olt);
            } else {
                if ($progress) $progress(5, 'Membaca inventaris YOTC C1600');
                $system = $this->parser->system($client->read('/action/getSystemInformation'), $client->read('/action/getCpuMemoryTemp'));
                $snapshot = $this->parser->inventory($client->read('/action/getSlotInfo'), $client->read('/action/getPonPortInfoAll'), $client->read('/action/getAuthOnuAll'));
                $snapshot['system'] = $system;
                $snapshot['warnings'] = array_merge($snapshot['warnings'], $this->warnings($olt));
                if ($inventoryReady) $inventoryReady($snapshot);
            }
            if (in_array($scope, ['inventory','names'], true)) return $snapshot;
            $known = $olt->onus()->where('observation_source', 'real')->where('is_present', true)->with('port')->get()
                ->mapWithKeys(fn ($onu) => [($onu->port?->interface_name ?? '').':'.$onu->onu_index.'|'.$onu->sn => $onu->optical_observed_at?->getTimestamp() ?? 0])->all();
            $targets = [];
            foreach ($snapshot['ports'] as $p => $port) {
                foreach ($port['onus'] as $o => $onu) {
                    if ($onu['phase_state'] !== 'working') continue;
                    $key = $port['interface_name'].':'.$onu['onu_index'].'|'.$onu['sn'];
                    $targets[] = ['p'=>$p, 'o'=>$o, 'observed'=>$known[$key] ?? 0];
                }
            }
            usort($targets, fn ($a, $b) => [$a['observed'],$a['p'],$a['o']] <=> [$b['observed'],$b['p'],$b['o']]);
            $batch = max(1, min(64, (int) config('olt.yotc_optical_batch', 12)));
            $delay = max(0, min(5000, (int) config('olt.yotc_request_delay_ms', 250)));
            $selected = array_slice($targets, 0, $batch);
            foreach ($selected as $number => $target) {
                if (microtime(true) >= $deadline) {
                    $snapshot['warnings'][] = 'Anggaran waktu optik YOTC habis; sampel yang belum dibaca dipertahankan.';
                    break;
                }
                if ($number && $delay) usleep($delay * 1000);
                $port = &$snapshot['ports'][$target['p']];
                $onu = &$port['onus'][$target['o']];
                if ($progress) $progress((int) ($number / max(1, count($selected)) * 100), 'Optik YOTC '.($number+1).'/'.count($selected));
                try {
                    $optical = $this->parser->optical($client->read('/action/getOnuOpticalParam', ['slotId'=>$port['slot_no'], 'ponId'=>$port['port_no'], 'onuId'=>$onu['onu_index']]));
                    $onu = array_replace($onu, $optical, ['optical_attempted'=>true]);
                    $port['optical_attempted'] = true;
                } catch (OltConnectionException $error) {
                    $snapshot['warnings'][] = $error->getMessage().' Nilai optik sebelumnya dipertahankan.';
                    if (! in_array($error->reason, ['parse_failed','http_error'], true)) {
                        unset($onu, $port);
                        break;
                    }
                }
                unset($onu, $port);
            }
            if (count($targets) > $batch) $snapshot['warnings'][] = 'Optik YOTC dibaca bertahap maksimal '.$batch.' ONU per polling; sampel terlama didahulukan.';
            return $snapshot;
        });
    }

    private function storedSnapshot(Olt $olt): array
    {
        $ports = $olt->ports()->current()->with(['onus'=>fn ($query) => $query->current()->orderBy('onu_index')])->orderBy('slot_no')->orderBy('port_no')->get()->map(fn ($port) => [
            'slot_no'=>$port->slot_no, 'port_no'=>$port->port_no, 'interface_name'=>$port->interface_name,
            'onus'=>$port->onus->map(fn ($onu) => $onu->only(['onu_index','sn','name','phase_state','admin_state','omcc_state','onu_type','device_name','device_description','rx_power','olt_rx_power']))->all(),
        ])->all();
        return ['system'=>array_replace($this->parser->system(), $olt->boards['metrics'] ?? []), 'boards'=>$olt->boards['items'] ?? [],
            'warnings'=>$this->warnings($olt), 'ports'=>$ports, 'complete_inventory'=>true];
    }

    private function warnings(Olt $olt): array
    {
        return $olt->cli_transport === 'http' ? ['HTTP YOTC tidak terenkripsi; gunakan hanya pada jaringan manajemen internal yang dibatasi.'] : [];
    }

    private function session(Olt $olt, callable $action): mixed
    {
        $client = app(YotcHttpClient::class);
        try {
            $client->connect($olt);
            return $action($client);
        } finally {
            $client->close();
        }
    }
}
