<?php

namespace App\Services\Olt;

use App\Models\Olt;

class ZteSnmpDriver
{
    public function system(Olt $olt): array
    {
        $values = $this->get($olt, ['.1.3.6.1.2.1.1.1.0', '.1.3.6.1.2.1.1.3.0', '.1.3.6.1.2.1.1.5.0']);
        $ticks = (string) $values[1];
        if (preg_match('/\((\d+)\)/', $ticks, $m)) $ticks = $m[1];
        return ['description' => $values[0], 'uptime_seconds' => ctype_digit($ticks) ? (int) floor((int) $ticks / 100) : null, 'hostname' => $values[2]];
    }

    public function get(Olt $olt, array $oids): array
    {
        $session = $this->session($olt);
        try {
            foreach ($oids as $oid) $this->validOid($oid);
            $result = @$session->get($oids);
            if ($result === false || count((array) $result) !== count($oids)) throw new OltConnectionException('SNMP tidak merespons semua OID. Periksa community, port UDP, ACL, dan dukungan MIB.', 'snmp_failed');
            return array_values((array) $result);
        } catch (OltConnectionException $e) { throw $e; }
        catch (\Throwable) { throw new OltConnectionException('Permintaan SNMP gagal atau timeout.', 'snmp_failed'); }
        finally { $session->close(); }
    }

    public function walk(Olt $olt, string $oid): array
    {
        $this->validOid($oid); $session = $this->session($olt);
        try {
            $result = @$session->walk($oid);
            if ($result === false) throw new OltConnectionException('SNMP walk gagal. Periksa MIB, community dan ACL.', 'snmp_failed');
            return $result;
        } catch (OltConnectionException $e) { throw $e; }
        catch (\Throwable) { throw new OltConnectionException('SNMP walk gagal atau timeout.', 'snmp_failed'); }
        finally { $session->close(); }
    }

    public function interfaces(Olt $olt): array
    {
        return ['names' => $this->walk($olt, '.1.3.6.1.2.1.31.1.1.1.1'), 'states' => $this->walk($olt, '.1.3.6.1.2.1.2.2.1.8')];
    }

    public function metrics(Olt $olt): array
    {
        $profile = array_intersect_key(config('olt.snmp_metrics', []), array_flip(['cpu','temperature','memory_usage']));
        if (!$profile) return [];
        $raw = $this->get($olt, array_map(fn ($metric) => $metric['oid'], array_values($profile)));
        $metrics = [];
        foreach (array_keys($profile) as $i => $name) {
            if (!is_numeric($raw[$i])) throw new OltConnectionException('Nilai OID metrik SNMP bukan angka. Verifikasi MIB dan skala firmware.', 'snmp_failed');
            $value = (float) $raw[$i] * (float) ($profile[$name]['scale'] ?? 1);
            if (!is_finite($value) || ($name !== 'temperature' && ($value < 0 || $value > 100)) || ($name === 'temperature' && ($value < -100 || $value > 150))) throw new OltConnectionException('Skala OID metrik SNMP tidak valid.', 'snmp_failed');
            $metrics[$name] = $value;
        }
        return $metrics;
    }

    protected function session(Olt $olt): \SNMP
    {
        if (!class_exists(\SNMP::class)) throw new OltConnectionException('Ekstensi PHP SNMP belum aktif pada server NMS. Aktifkan ext-snmp untuk memakai SNMP.', 'snmp_unavailable');
        if (!$olt->snmp_community_enc) throw new OltConnectionException('SNMP community belum diatur.', 'configuration');
        $host = str_contains($olt->ip_address, ':') ? '['.$olt->ip_address.']' : $olt->ip_address;
        try {
            $session = new \SNMP(\SNMP::VERSION_2C, $host.':'.$olt->snmp_port, $olt->snmp_community_enc, ($olt->connection_timeout ?: 5) * 1000000, config('olt.snmp_retries', 1));
            $session->valueretrieval = SNMP_VALUE_PLAIN;
            $session->oid_output_format = SNMP_OID_OUTPUT_NUMERIC;
            $session->quick_print = true;
            $session->max_oids = 20;
            $session->exceptions_enabled = \SNMP::ERRNO_ANY;
            return $session;
        } catch (\Throwable) { throw new OltConnectionException('Tidak dapat membuka sesi SNMP.', 'snmp_failed'); }
    }

    private function validOid(string $oid): void
    {
        if (!preg_match('/^\.?\d+(?:\.\d+)+$/', $oid)) throw new OltConnectionException('Gunakan OID numerik yang telah diverifikasi dari MIB perangkat.', 'configuration');
    }
}
