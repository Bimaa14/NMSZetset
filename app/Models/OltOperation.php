<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class OltOperation extends Model {
    protected $guarded=[];
    protected $hidden=['ip_address','active_olt_id'];
    protected function casts(): array { return ['is_initial_sync'=>'boolean','warnings'=>'array','started_at'=>'datetime','heartbeat_at'=>'datetime','completed_at'=>'datetime']; }
    public function olt() { return $this->belongsTo(Olt::class); }
}
