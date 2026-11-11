<?php

namespace App\Services\Olt;

use App\Models\{Olt, OltPort, Onu};
use Illuminate\Support\Facades\{Cache, DB};

/** C1600 PON rates only. It never changes performance sampling or ONU configuration. */
class YotcTrafficCollectorService
{
    public function __construct(private YotcTrafficParser $parser) {}

    public function collect(Onu|OltPort $entity): array
    {
        if ($entity instanceof Onu) {
            return app(MikrotikPppoeTrafficService::class)->collect($entity);
        }
        $this->assertEntity($entity);
        return app(OltSessionCoordinator::class)->read($entity->olt, fn () => $this->collectUnlocked($entity));
    }

    public function collectUnlocked(Onu|OltPort $entity, ?YotcTelnetClient $client = null): array
    {
        if ($entity instanceof Onu) {
            return app(MikrotikPppoeTrafficService::class)->collect($entity);
        }
        $port = $this->assertEntity($entity); $olt = $entity->olt; $signature = $this->signature($olt);
        $this->assertSignature($olt, $signature);
        if ($recent = $this->recent($entity)) return $recent;
        $own = $client === null; $client ??= app(YotcTelnetClient::class);
        try {
            if ($own) $client->connect($olt);
            $data = $this->parser->parse($client->readPort($port), $port);
            return $this->persist($entity, $data, $signature);
        } finally { if ($own) $client->close(); }
    }

    private function assertEntity(Onu|OltPort $entity): string
    {
        abort_unless($entity instanceof OltPort, 422, 'Traffic YOTC tersedia per PON; traffic per ONU belum didukung.');
        $olt = $entity->olt;
        abort_unless($olt && $olt->chassis_type === 'C1600' && $olt->connection_mode === 'real'
            && $olt->traffic_monitoring_enabled, 422, 'Monitoring traffic PON YOTC belum diaktifkan.');
        abort_unless(preg_match('/^epon-olt_([1-9]\d?\/[1-9]\d?)$/D', (string) $entity->interface_name, $match), 422, 'Interface PON YOTC tidak valid.');
        abort_unless(is_array($olt->traffic_pon_ports) && in_array($match[1], $olt->traffic_pon_ports, true), 422, 'PON ini belum dipilih untuk monitoring traffic YOTC.');
        abort_unless($entity->olt_id === $olt->id && $olt->ports()->current()->whereKey($entity->id)->where('interface_name', $entity->interface_name)->exists(), 422, 'PON YOTC sudah tidak aktif pada inventaris saat ini.');
        return $match[1];
    }

    private function signature(Olt $olt): string
    {
        $fields = ['id','tenant_id','chassis_type','connection_mode','ip_address','traffic_telnet_port',
            'traffic_telnet_user','traffic_telnet_pass_enc','traffic_enable_pass_enc','traffic_monitoring_enabled','traffic_pon_ports'];
        return hash('sha256', json_encode(array_intersect_key($olt->getAttributes(), array_flip($fields)), JSON_THROW_ON_ERROR));
    }

    private function assertSignature(Olt $olt, string $signature): void
    {
        $fresh = Olt::find($olt->id);
        if (! $fresh || $this->signature($fresh) !== $signature) {
            throw new OltConnectionException('Konfigurasi traffic OLT berubah selama pembacaan. Ulangi pembacaan.', 'configuration_changed');
        }
    }

    private function recentKey(OltPort $port): string
    {
        return 'nms:yotc-traffic:'.$this->signature($port->olt).':'.$port->id.':'.$port->interface_name;
    }

    private function recent(OltPort $port): ?array
    {
        $recent = Cache::get($this->recentKey($port));
        $interval = max(15, min(60, (int) config('olt.yotc_traffic_live_interval', 15)));
        return is_array($recent) && microtime(true)-$recent['at'] < $interval ? $recent['data'] : null;
    }

    private function persist(OltPort $port, array $data, string $signature): array
    {
        $olt = $port->olt; $stamp = now();
        $row = $data + ['tenant_id'=>$olt->tenant_id,'olt_id'=>$olt->id,'olt_port_id'=>$port->id,'source'=>'cli','recorded_at'=>$stamp];
        DB::transaction(function () use ($olt, $port, $signature, $data, $row, $stamp) {
            $fresh = Olt::whereKey($olt->id)->lockForUpdate()->first();
            if (! $fresh || $this->signature($fresh) !== $signature) {
                throw new OltConnectionException('Konfigurasi traffic OLT berubah selama pembacaan. Data tidak disimpan.', 'configuration_changed');
            }
            $current = $fresh->ports()->current()->whereKey($port->id)->where('interface_name', $port->interface_name)->lockForUpdate()->first();
            if (! $current) throw new OltConnectionException('Posisi PON berubah selama pembacaan. Data tidak disimpan.', 'configuration_changed');
            DB::table('port_traffic_logs')->insert($row);
            $current->update(['download_bps'=>$data['rx_bps'],'upload_bps'=>$data['tx_bps'],'traffic_observed_at'=>$stamp]);
            $fresh->update(['traffic_observed_at'=>$stamp]);
        });
        $port->setAttribute('download_bps', $data['rx_bps']); $port->setAttribute('upload_bps', $data['tx_bps']);
        $port->setAttribute('traffic_observed_at', $stamp); $olt->setAttribute('traffic_observed_at', $stamp);
        $row['recorded_at'] = $stamp->toIso8601String();
        Cache::put($this->recentKey($port), ['at'=>microtime(true),'data'=>$row], max(15, min(60, (int) config('olt.yotc_traffic_live_interval', 15))));
        return $row;
    }

    /** A maximum of four explicitly selected current PONs share one bounded Telnet session. */
    public function poll(Olt $olt): array
    {
        abort_unless($olt->chassis_type === 'C1600' && $olt->connection_mode === 'real' && $olt->traffic_monitoring_enabled, 422, 'Monitoring traffic PON YOTC belum diaktifkan.');
        $configured = $olt->traffic_pon_ports;
        $mikrotik = app(MikrotikPppoeTrafficService::class);
        $hasMikrotik = $mikrotik->isConfiguredFor($olt);
        abort_unless((is_array($configured) && count($configured) > 0 && count($configured) <= 4) || $hasMikrotik, 422, 'Pilih satu sampai empat PON untuk monitoring traffic YOTC.');
        $warnings = [];
        if (is_array($configured) && count($configured) > 0 && count($configured) <= 4 && (string) $olt->getRawOriginal('traffic_telnet_pass_enc') !== '') {
            $ports = $olt->ports()->current()->whereIn('interface_name', array_map(fn ($p) => 'epon-olt_'.$p, $configured))->get()->keyBy('interface_name');
            $client = null; $start = microtime(true); $reads = 0;
            try {
                foreach ($configured as $pon) {
                    $port = $ports->get('epon-olt_'.$pon);
                    if (! $port) { $warnings[] = 'PON '.$pon.' tidak tersedia pada inventaris YOTC saat ini.'; continue; }
                    $port->setRelation('olt', $olt);
                    if (microtime(true)-$start >= 19) { $warnings[] = 'Batas waktu siklus traffic YOTC tercapai; PON tersisa menunggu siklus berikutnya.'; break; }
                    try {
                        $this->assertEntity($port); $this->assertSignature($olt, $this->signature($olt));
                        if ($this->recent($port)) continue;
                        if ($client === null) { $client = app(YotcTelnetClient::class); $client->connect($olt); }
                        if ($reads > 0) usleep(max(0, min(1000, (int) config('olt.yotc_traffic_request_delay_ms', 250))) * 1000);
                        $reads++;
                        $this->collectUnlocked($port, $client);
                    } catch (OltConnectionException $error) {
                        if ($error->reason === 'read_busy') throw $error;
                        $warnings[] = $error->getMessage();
                        // A transport/command failure closes the session. Parser rejection alone leaves it usable.
                        if ($error->reason !== 'traffic_unavailable') break;
                    }
                }
            } finally { $client?->close(); }
        }
        if ($hasMikrotik) {
            foreach ($mikrotik->poll($olt) as $w) {
                $warnings[] = $w;
            }
        }
        return array_values(array_unique($warnings));
    }

    public function coverage(Olt $olt): array
    {
        $target = max(60, (int) config('olt.yotc_traffic_interval', 60));
        $configured = $olt->traffic_monitoring_enabled && is_array($olt->traffic_pon_ports) ? $olt->traffic_pon_ports : [];
        $total = count($configured); $fresh = 0; $oldest = null;
        if ($total > 0) {
            $ports = $olt->ports()->current()->whereIn('interface_name', array_map(fn ($p) => 'epon-olt_'.$p, $configured));
            $fresh = (clone $ports)->where('traffic_observed_at', '>=', now()->subSeconds($target))->whereNotNull('download_bps')->whereNotNull('upload_bps')->count();
            $oldest = (clone $ports)->min('traffic_observed_at');
        }
        return ['target_seconds'=>$target,'interfaces'=>$total,'fresh'=>$fresh,'overdue'=>max(0,$total-$fresh),'oldest_sample_at'=>$oldest,'complete'=>$total > 0 && $fresh === $total];
    }
}
