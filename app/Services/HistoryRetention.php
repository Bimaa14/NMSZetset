<?php
namespace App\Services;
use Illuminate\Support\Facades\DB;
class HistoryRetention {
    public function run(bool $apply=false):array {
        $counts=[];$columns=['onu_traffic_logs'=>'recorded_at','port_traffic_logs'=>'recorded_at','onu_optical_logs'=>'calculated_at','onu_status_logs'=>'observed_at','olt_operations'=>'completed_at','snmp_trap_events'=>'received_at','olt_backups'=>'created_at'];
        foreach($columns as$table=>$column){$minimum=in_array($table,['onu_traffic_logs','port_traffic_logs'],true)?31:1;$days=max($minimum,(int)config('operations.retention_days.'.$table,90));$q=DB::table($table)->where($column,'<',now()->subDays($days));
            if($table==='olt_operations')$q->whereIn('status',['completed','failed','partial','cancelled'])->whereNull('active_olt_id');
            // Keep the newest backup for every OLT even when the device has been idle.
            if($table==='olt_backups')$q->whereNotIn('id',fn($s)=>$s->selectRaw('MAX(id)')->from('olt_backups')->groupBy('olt_id'));
            $counts[$table]=(clone$q)->count();if($apply){do{$ids=(clone$q)->limit(1000)->pluck('id');if($ids->isNotEmpty())DB::table($table)->whereIn('id',$ids)->delete();}while($ids->count()===1000);}
        }return $counts;
    }
}
