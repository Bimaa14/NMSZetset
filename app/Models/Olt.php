<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Olt extends Model
{
    protected $guarded = [];
    protected $hidden = ['telnet_pass_enc', 'snmp_community_enc', 'telnet_user', 'traffic_telnet_user', 'traffic_telnet_pass_enc', 'traffic_enable_pass_enc'];

    protected function casts(): array
    {
        return ['traffic_monitoring_enabled'=>'boolean','traffic_pon_ports'=>'array','traffic_telnet_pass_enc'=>'encrypted','traffic_enable_pass_enc'=>'encrypted', 'latitude'=>'float','longitude'=>'float','last_backup_at'=>'datetime','traffic_observed_at'=>'datetime', 'monitoring_enabled'=>'boolean', 'state_observed_at'=>'datetime', 'names_observed_at'=>'datetime', 'optical_observed_at'=>'datetime', 'last_sync_at' => 'datetime', 'telnet_pass_enc' => 'encrypted', 'snmp_community_enc' => 'encrypted', 'snmp_enabled' => 'boolean', 'boards' => 'array', 'system_observed_at' => 'datetime', 'last_connection_check_at' => 'datetime'];
    }

    public function tenant() { return $this->belongsTo(Tenant::class); }
    public function onus() { return $this->hasMany(Onu::class); }
    public function ports() { return $this->hasMany(OltPort::class); }
}
