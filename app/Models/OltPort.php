<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OltPort extends Model
{
    protected $guarded = [];
    protected function casts():array{return ['optical_polled_at'=>'datetime','traffic_observed_at'=>'datetime','download_bps'=>'float','upload_bps'=>'float'];}
    public function scopeCurrent($query) { return $query->where('olt_ports.is_present', true)->whereHas('olt', fn ($olt) => $olt->whereColumn('olts.connection_mode', 'olt_ports.observation_source')); }
    public function olt() { return $this->belongsTo(Olt::class); }
    public function onus() { return $this->hasMany(Onu::class); }
}
