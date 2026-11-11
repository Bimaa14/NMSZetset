<?php

namespace App\Services\Olt;

use App\Models\Olt;
use App\Models\Onu;
use App\Models\OnuOpticalLog;
use Illuminate\Support\Facades\DB;

class RealOltOperations
{
    public function __construct(private ZteTelnetDriver $driver, private ZteSnmpDriver $snmp) {}

    private function driver(Olt $olt): ZteTelnetDriver|YotcHttpDriver
    {
        return $olt->chassis_type === 'C1600' ? app(YotcHttpDriver::class) : $this->driver;
    }

    /** One interactive read session, independent of monitoring and ONU controls. */
    public function readOnly(Olt $olt, callable $action): mixed
    {
        return app(OltSessionCoordinator::class)->read($olt, $action);
    }

    public function locked(Olt $olt, callable $action): mixed
    {
        return app(OltSessionCoordinator::class)->exclusive($olt, $action);
    }

    public function control(Onu $onu, callable $action): mixed
    {
        return app(OltSessionCoordinator::class)->control($onu, $action);
    }

    public function monitoring(Olt $olt, callable $action): mixed
    {
        return app(OltSessionCoordinator::class)->monitor($olt, $action);
    }

    public function sync(Olt $olt): array
    {
        app(OltCapabilities::class)->requireOperation($olt, 'sync');
        return $this->monitoring($olt, function () use ($olt) {
            $signature = $this->signature($olt);
            try {
                @set_time_limit(300);
                $snapshot = $this->driver($olt)->snapshot($olt, fn (array $inventory) => $this->persistSnapshot($olt, $inventory, $signature, false));
                $this->supplement($olt, $snapshot);
                $this->persistSnapshot($olt, $snapshot, $signature, true);

                return $snapshot['warnings'];
            } catch (OltConnectionException $e) {
                $this->failed($olt, $e);
                throw $e;
            }
        });
    }

    public function poll(Olt $olt, string $scope, ?callable $progress = null): array
    {
        app(OltCapabilities::class)->requireOperation($olt, $scope);
        if ($scope === 'backup') return $this->monitoring($olt,function()use($olt){
            $config=$this->driver->backup($olt);
            if(!preg_match('/^\h*(?:hostname|interface|version)\b/m',$config))throw new OltConnectionException('Output konfigurasi tidak lengkap atau belum dikenali.','parse_failed');
            \App\Models\OltBackup::create(['tenant_id'=>$olt->tenant_id,'olt_id'=>$olt->id,'configuration'=>$config,'checksum'=>hash('sha256',$config),'bytes'=>strlen($config),'created_at'=>now()]);
            $olt->update(['last_backup_at'=>now()]);return [];
        });
        if ($scope === 'system') return $this->view($olt);
        if ($scope === 'traffic') return $this->monitoring($olt,fn()=>app(OltTrafficService::class)->poll($olt));
        return $this->monitoring($olt, function () use ($olt, $scope, $progress) {
            @set_time_limit(300);
            $signature = $this->signature($olt);
            try {
                $save = fn(array $data) => $this->persistSnapshot($olt, $data, $signature, false, $scope);
                $snapshot = $this->driver($olt)->snapshot($olt, $save, $scope, $progress);
                $this->persistSnapshot($olt, $snapshot, $signature, $scope === 'optics', $scope);
                return $snapshot['warnings'];
            } catch (OltConnectionException $e) { $this->failed($olt, $e); throw $e; }
        });
    }

    private function persistSnapshot(Olt $olt, array $snapshot, string $signature, bool $logOptics, string $scope = 'full'): void
    {
        DB::transaction(function () use ($olt, $snapshot, $signature, $logOptics, $scope) {
            $fresh = Olt::whereKey($olt->id)->lockForUpdate()->firstOrFail();
            if ($this->signature($fresh) !== $signature) {
                throw new OltConnectionException('Konfigurasi OLT berubah selama polling. Ulangi Sync.', 'configuration_changed');
            }
            $stamp = now();
            $yotc = $olt->chassis_type === 'C1600';
            $opticalSamples = 0;
            if (in_array($scope, ['full','inventory'], true)) {
            if ($snapshot['complete_inventory'] ?? true) {
                $olt->onus()->update(['is_present' => false]);
                $olt->ports()->update(['is_present' => false]);
            } else {
                $portIds = $olt->ports()->whereIn('interface_name', array_column($snapshot['ports'], 'interface_name'))->pluck('id');
                $olt->onus()->whereIn('olt_port_id', $portIds)->update(['is_present' => false]);
            }
            }
            foreach ($snapshot['ports'] as $data) {
                if($scope==='optics' && empty($data['optical_attempted']))continue;
                if ($yotc && in_array($scope, ['names', 'optics'], true)) {
                    $port = $olt->ports()->where('interface_name', $data['interface_name'])->first();
                    if (!$port) continue;
                } else {
                $port = $olt->ports()->updateOrCreate(['interface_name' => $data['interface_name']], [
                    'slot_no' => $data['slot_no'], 'port_no' => $data['port_no'], 'total_onu' => count($data['onus']),
                    'online_onu' => count(array_filter($data['onus'], fn ($onu) => $onu['phase_state'] === 'working')),
                    'observation_source' => 'real', 'is_present' => true, 'last_seen_at' => $stamp,
                ]);
                }
                if(!empty($data['optical_attempted']))$port->update(['optical_polled_at'=>$stamp]);
                foreach ($data['onus'] as $sample) {
                    $sampleOptics = $logOptics && (!$yotc || !empty($sample['optical_attempted']));
                    if ($yotc && $scope === 'optics' && !$sampleOptics) continue;
                    // A serial may appear at several addresses. Preserve each observation independently.
                    $onu = $olt->onus()->where('tenant_id', $olt->tenant_id)
                        ->where('olt_port_id', $port->id)->where('onu_index', $sample['onu_index'])
                        ->where('observation_source', 'real')->where('sn', $sample['sn'])->first();
                    // Name/optical samples cannot create a replacement identity without an inventory observation.
                    if (!$onu && $yotc && in_array($scope, ['names', 'optics'], true)) continue;
                    if (! $onu) {
                        $onu = new Onu(['tenant_id' => $olt->tenant_id, 'sn' => $sample['sn'], 'name' => $sample['name']]);
                    }
                    // Refresh imported labels and SN placeholders while keeping local customer overrides.
                    $label = $sample['device_name'] ?? ($sample['name'] !== $sample['sn'] ? $sample['name'] : ($sample['device_description'] ?? null));
                    $description = $sample['device_description'] ?? null;
                    if ($scope === 'optics') { $label = null; $description = null; }
                    if ($label !== null && ($onu->observation_source !== 'real' || ! $onu->name || $onu->name === $sample['sn'] || in_array($onu->name, array_filter([$onu->device_name, $onu->device_description]), true))) {
                        $onu->name = mb_substr($label, 0, 255);
                    }
                    if ($description !== null && (! $onu->description || $onu->description === $onu->device_description)) {
                        $onu->description = $description;
                    }
                    $values = array_diff_key($sample, array_flip(['name', 'sn', 'optical_attempted']));
                    if ($label !== null) {
                        $values['device_name'] = mb_substr($label, 0, 255);
                    }
                    $oldPhase = $onu->phase_state;
                    if ($sample['phase_state'] === 'working' && in_array($scope,['full','inventory'],true)) {
                        $values['last_online_at'] = $stamp;
                    }
                    if ($scope === 'inventory') unset($values['rx_power'], $values['olt_rx_power']);
                    if ($yotc && !$sampleOptics) unset($values['rx_power'], $values['olt_rx_power']);
                    if ($scope === 'names') $values = array_intersect_key($values, array_flip(['device_name','device_description']));
                    if ($scope === 'optics') $values = array_intersect_key($values, array_flip(['rx_power','olt_rx_power']));
                    if (in_array($scope,['full','inventory'],true)) {
                        $values['state_observed_at'] = $stamp;
                        if ($sample['phase_state'] !== 'working' && $oldPhase === 'working') $values['last_offline_at'] = $stamp;
                    }
                    if ($sampleOptics) $values['optical_observed_at'] = $stamp;
                    $onu->fill($values + [
                        'olt_id' => $olt->id, 'olt_port_id' => $port->id, 'observation_source' => 'real', 'is_present' => true,
                        'last_seen_at' => $stamp, 'reboot_complete_at' => null, 'reboot_restore_state' => null,
                    ])->save();
                    if (in_array($scope,['full','inventory'],true) && $oldPhase !== $sample['phase_state']) {
                        DB::table('onu_status_logs')->insert(['onu_id'=>$onu->id,'phase_state'=>$sample['phase_state'],'previous_state'=>$oldPhase,'source'=>'real','observed_at'=>$stamp]);
                    }
                    if ($sampleOptics) {
                        $opticalSamples++;
                        OnuOpticalLog::create(['onu_id' => $onu->id, 'rx_power' => $onu->rx_power, 'olt_rx_power' => $onu->olt_rx_power, 'calculated_at' => $stamp, 'observation_source' => 'real']);
                    }
                }
            }
            $updates = ['status'=>'Online','last_connection_error'=>null,'last_connection_check_at'=>$stamp];
            if ($scope === 'full') {
                $updates += $this->systemValues($snapshot) + ['last_sync_at'=>$stamp,'state_observed_at'=>$stamp,'names_observed_at'=>$stamp];
                if (!$yotc || $opticalSamples > 0) $updates['optical_observed_at'] = $stamp;
            }
            elseif ($scope === 'inventory') {
                $updates += ['last_sync_at'=>$stamp,'state_observed_at'=>$stamp,'boards'=>['items'=>$snapshot['boards'],'metrics'=>$olt->boards['metrics'] ?? []]];
                if ($yotc) $updates = array_replace($updates, $this->systemValues($snapshot));
            }
            elseif ($scope === 'names') $updates['names_observed_at']=$stamp;
            elseif ($scope === 'optics' && (!$yotc || $opticalSamples > 0)) $updates['optical_observed_at']=$stamp;
            $fresh->update($updates);
        });
    }

    public function view(Olt $olt): array
    {
        app(OltCapabilities::class)->requireOperation($olt, 'system');
        return $this->monitoring($olt, function () use ($olt) {
            $signature = $this->signature($olt);
            try {
                $snapshot = $this->driver($olt)->view($olt);
                $this->supplement($olt, $snapshot);
                $fresh = $olt->fresh();
                if (! $fresh || $this->signature($fresh) !== $signature) {
                    throw new OltConnectionException('Konfigurasi OLT berubah. Buka ulang detail.', 'configuration_changed');
                }
                $fresh->update($this->systemValues($snapshot));

                return $snapshot['warnings'];
            } catch (OltConnectionException $e) {
                $this->failed($olt, $e);
                throw $e;
            }
        });
    }

    private function supplement(Olt $olt, array &$snapshot): void
    {
        if ($olt->chassis_type === 'C1600' || ! $olt->snmp_enabled) {
            return;
        }
        try {
            $system = $this->snmp->system($olt);
            if (! $snapshot['system']['uptime'] && $system['uptime_seconds'] !== null) {
                $snapshot['system']['uptime'] = (string) $system['uptime_seconds'].' detik (SNMP)';
            }
            foreach ($this->snmp->metrics($olt) as $key => $value) {
                if ($snapshot['system'][$key] === null) {
                    $snapshot['system'][$key] = $value;
                }
            }
        } catch (OltConnectionException $e) {
            $snapshot['warnings'][] = $e->getMessage();
        }
    }

    private function systemValues(array $snapshot): array
    {
        // Existing CPU/temperature columns are NOT NULL in the original schema; availability is in boards metrics.
        $system = array_filter($snapshot['system'], fn ($v) => $v !== null);

        return $system + ['boards' => ['items' => $snapshot['boards'], 'metrics' => $snapshot['system']], 'status' => 'Online', 'system_observed_at' => now(), 'telemetry_source' => 'real', 'last_connection_error' => null, 'last_connection_check_at' => now()];
    }

    private function signature(Olt $olt): string
    {
        return hash('sha256', json_encode(array_intersect_key($olt->getAttributes(), array_flip(['chassis_type', 'connection_mode', 'cli_transport', 'connection_timeout', 'ip_address', 'telnet_port', 'telnet_user', 'telnet_pass_enc', 'snmp_port', 'snmp_community_enc', 'snmp_enabled', 'ssh_host_key_sha256']))));
    }

    private function failed(Olt $olt, OltConnectionException $error): void
    {
        $updates = ['last_connection_error' => $error->getMessage(), 'last_connection_check_at' => now()];
        // Offline describes the management connection, not the physical ONU state.
        // Parser/optional-command failures do not prove that the OLT is unreachable.
        if (in_array($error->reason, ['connect_failed', 'connection_failed', 'timeout', 'disconnected', 'socket_error', 'ssh_connection', 'http_connection', 'authentication_failed'], true)) {
            $updates['status'] = 'Offline';
        }
        Olt::whereKey($olt->id)->update($updates);
    }
}
