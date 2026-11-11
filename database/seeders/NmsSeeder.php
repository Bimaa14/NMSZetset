<?php

namespace Database\Seeders;

use App\Models\{AuditLog, Olt, OltPort, Onu, OnuOpticalLog, Tag, Tenant, User};
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class NmsSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $tenant = Tenant::firstOrCreate(['name' => 'Network'], ['plan_type' => 'Premium', 'olt_quota' => 22, 'balance' => 750000, 'expired_at' => '2026-10-28 23:59:59']);
            $password = Hash::make('NmsDemo2026!');
            foreach (['isp_owner' => 'Bima Pratama', 'noc' => 'Aditya · NOC', 'cs' => 'Nadia · Customer Support', 'teknisi' => 'Rizky · Teknisi'] as $role => $name) {
                User::firstOrCreate(['email' => $role.'@bima.test'], ['tenant_id' => $tenant->id, 'name' => $name, 'role' => $role, 'password' => $password]);
            }
            $tags = collect(['Prioritas' => '#a78bfa', 'Residensial' => '#60a5fa', 'Bisnis' => '#34d399'])->map(fn ($color, $name) => Tag::firstOrCreate(['tenant_id' => $tenant->id, 'tag_name' => $name], ['color_hex' => $color]))->values();
            if (!app()->environment(['local', 'testing']) && !config('nms.demo')) return;
            $other = Tenant::firstOrCreate(['name' => 'ISP Nusantara'], ['plan_type' => 'Trial', 'olt_quota' => 2]);
            User::firstOrCreate(['email' => 'owner@nusantara.test'], ['tenant_id' => $other->id, 'name' => 'Owner Nusantara', 'role' => 'isp_owner', 'password' => $password]);
            Olt::firstOrCreate(['tenant_id' => $other->id, 'name' => 'OLT-TENANT-LAIN'], ['chassis_type' => 'C320', 'ip_address' => '192.168.2.10', 'status' => 'Online']);
            $names = ['OLT01-MONEX', 'OLT02-PALEDANG', 'OLT03-UNPAS(CIPAKU)', 'OLT04-MASPUR', 'OLT05-KBB', 'OLT06-GUNA_CIPTA', 'OLT SOREANG', 'OLT CIWIDEY', 'OLT LOVINA', 'OLT10-CIMAHI', 'OLT11-LENGKONG', 'OLT12-BUAHBATU', 'OLT13-ANTAPANI', 'OLT14-DAGO', 'OLT15-CILEUNYI', 'OLT16-RANCAEKEK', 'OLT17-PADALARANG', 'OLT18-LEMBANG', 'OLT19-BANJARAN', 'OLT20-MARGAHAYU', 'OLT21-DAYEUHKOLOT', 'OLT22-KATAPANG'];
            $locations = ['Monex, Bandung', 'Paledang, Bandung', 'Cipaku, Bandung', 'Maspur, Bandung', 'Bandung Barat', 'Guna Cipta, Bandung', 'Soreang, Kab. Bandung', 'Ciwidey, Kab. Bandung', 'Lovina, Bandung'];
            $people = ['Budi_Santoso', 'Siti_Rahayu', 'Warung_Kopi', 'Ahmad_Fauzi', 'Dewi_Lestari', 'Andi_Pratama', 'Toko_Berkah', 'Rina_Wulandari', 'Agus_Setiawan', 'Kantor_Mandiri', 'Dedi_Kurniawan', 'Putri_Anjani', 'Rudi_Hartono', 'Nia_Permata', 'Hendra_Wijaya', 'Cafe_Senja'];
            foreach ($names as $i => $name) {
                $olt = Olt::firstOrCreate(['tenant_id' => $tenant->id, 'name' => $name], [
                    'chassis_type' => in_array($i, [3, 6, 7, 10, 15, 19]) ? 'C300' : 'C320', 'ip_address' => '10.10.1.'.($i + 11), 'telnet_port' => 23, 'snmp_port' => 161,
                    'telnet_user' => 'demo', 'telnet_pass_enc' => 'simulation-only', 'snmp_community_enc' => 'simulation-only', 'status' => 'Online', 'last_sync_at' => '2026-10-15 15:48:00',
                    'location' => $locations[$i] ?? 'Area Bandung Raya', 'cpu' => 12 + ($i * 7 % 38), 'temperature' => 36 + ($i % 9), 'uptime' => (24 + $i).' hari 08 jam', 'firmware_version' => 'V2.1.0 (simulasi)',
                ]);
                for ($p = 0; $p < 4; $p++) {
                    $port = OltPort::firstOrCreate(['olt_id' => $olt->id, 'interface_name' => 'gpon-olt_1/1/'.($p + 1)], ['slot_no' => 1, 'port_no' => $p + 1, 'description' => 'Distribusi area '.($p + 1), 'total_onu' => 4, 'online_onu' => $p === 3 && $i % 3 === 0 ? 3 : 4]);
                    for ($j = 0; $j < 4; $j++) {
                        $n = $p * 4 + $j; $bad = $n * 22 + $i < 64;
                        $rx = $bad ? -28.1 - ($i % 12) / 5 : -18.4 - ($n % 8) / 2;
                        $onu = Onu::firstOrCreate(['tenant_id' => $tenant->id, 'olt_id' => $olt->id, 'olt_port_id' => $port->id, 'onu_index' => $j + 1, 'observation_source' => 'simulator', 'sn' => sprintf('ZTEGC%07d', 1122334 + $i * 16 + $n)], [
                            'olt_id' => $olt->id, 'olt_port_id' => $port->id, 'onu_index' => $j + 1, 'name' => $people[$n].($i ? '_'.($i + 1) : ''),
                            'description' => 'Jl. '.explode(',', $olt->location)[0].' No. '.($n + 10), 'phase_state' => $n === 15 && $i % 3 === 0 ? ($i % 2 === 0 ? 'los' : 'dying-gasp') : 'working',
                            'rx_power' => $rx, 'olt_rx_power' => $rx - .65, 'pppoe_user' => strtolower($people[$n]).($i + 1).'@bima.net', 'vlan_id' => 120 + $i,
                            'latitude' => -6.87 - ($i % 8) * .015 - $n * .001, 'longitude' => 107.53 + ($i % 7) * .02 + $n * .001, 'updated_at' => '2026-10-15 15:48:00',
                        ]);
                        $onu->tags()->syncWithoutDetaching([$tags[$n % 3]->id]);
                        OnuOpticalLog::firstOrCreate(['onu_id' => $onu->id, 'calculated_at' => '2026-10-15 14:00:00'], ['rx_power' => $bad ? -23.5 : $rx, 'olt_rx_power' => -24.1]);
                        OnuOpticalLog::firstOrCreate(['onu_id' => $onu->id, 'calculated_at' => '2026-10-15 15:00:00'], ['rx_power' => $rx, 'olt_rx_power' => $rx - .65]);
                    }
                }
            }
            AuditLog::firstOrCreate(['tenant_id' => $tenant->id, 'action' => 'seed', 'target' => 'Data demo awal'], ['actor_type' => 'User', 'actor_id' => $tenant->users()->first()->id, 'olt_id' => null, 'ip_address' => '127.0.0.1', 'created_at' => '2026-10-15 15:48:00']);
        });
    }
}
