<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Onu extends Model
{
    protected $guarded = [];
    protected $hidden = ['reboot_restore_state'];
    public function scopeCurrent($query) { return $query->where('onus.is_present', true)->whereHas('olt', fn ($olt) => $olt->whereColumn('olts.connection_mode', 'onus.observation_source')); }
    protected function casts(): array
    {
        return ['download_bps'=>'float','upload_bps'=>'float','traffic_observed_at'=>'datetime','state_observed_at'=>'datetime', 'optical_observed_at'=>'datetime', 'last_offline_at'=>'datetime', 'rx_power' => 'float', 'olt_rx_power' => 'float', 'latitude' => 'float', 'longitude' => 'float', 'reboot_requested_at' => 'datetime', 'reboot_complete_at' => 'datetime', 'last_online_at' => 'datetime'];
    }
    public function olt() { return $this->belongsTo(Olt::class); }
    public function port() { return $this->belongsTo(OltPort::class, 'olt_port_id'); }
    public function tags() { return $this->belongsToMany(Tag::class, 'onu_tag'); }
    public function opticalLogs() { return $this->hasMany(OnuOpticalLog::class); }
}
