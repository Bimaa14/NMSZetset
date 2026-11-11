<?php
namespace App\Http\Controllers;

use App\Models\{Onu,OltPort};
use App\Services\Olt\OltTrafficService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TrafficController extends Controller
{
    private function entity(Request $r,string $kind,int $id):Onu|OltPort{
        abort_unless($r->user()->allows('read'),403);
        return ($kind==='onus'?Onu::current()->with(['olt','port']):OltPort::current()->with('olt'))->whereHas('olt',fn($q)=>$q->where('tenant_id',$r->user()->tenant_id))->findOrFail($id);
    }
    public function live(Request $r,string $kind,int $id,OltTrafficService $collector){
        $entity=$this->entity($r,$kind,$id);
        try{$data=['sample'=>$collector->collect($entity)];}
        catch(\App\Services\Olt\OltConnectionException $e){if($e->reason!=='read_busy')throw $e;$data=['skipped'=>true,'reason'=>'read_busy'];}
        $data['poll_after_seconds']=$entity->olt->chassis_type==='C1600'?max(15,(int)config('olt.yotc_traffic_live_interval',15)):2;
        return response()->json($data,isset($data['skipped'])?202:200)->header('Cache-Control','no-store');
    }
    public function history(Request $r,string $kind,int $id){
        $entity=$this->entity($r,$kind,$id);
        $d=$r->validate(['from'=>'nullable|date_format:Y-m-d\TH:i:sP','to'=>'nullable|date_format:Y-m-d\TH:i:sP','minutes'=>'nullable|integer|min:1|max:527040']);
        $to=(isset($d['to'])?CarbonImmutable::parse($d['to']):CarbonImmutable::now())->setTimezone(config('app.timezone'));$from=isset($d['from'])?CarbonImmutable::parse($d['from'])->setTimezone(config('app.timezone')):$to->subMinutes($d['minutes']??30);
        abort_if($from>=$to||$from->diffInDays($to)>366,422,'Rentang harus berurutan dan maksimal 366 hari.');
        $table=$kind==='onus'?'onu_traffic_logs':'port_traffic_logs';$column=$kind==='onus'?'onu_id':'olt_port_id';
        $base=DB::table($table)->where($column,$id)->where('tenant_id',$entity->olt->tenant_id)->where('olt_id',$entity->olt_id)->whereIn('source',$entity->olt->connection_mode==='real'?['snmp','cli']:['simulator']);
        $data=app(\App\Services\TrafficHistory::class)->read($base,$from,$to);
        $data['volume_supported']=$entity->olt->chassis_type!=='C1600'||$kind==='onus';
        if(!$data['volume_supported']) {
            $data['chart_baseline']=null;
            $data['volume_note']='C1600 menyediakan laju traffic langsung. Arti counter octet belum terverifikasi, sehingga volume tidak dihitung.';
            foreach(['rx','tx'] as $direction) { $data['statistics'][$direction]['volume_bytes']=null; $data['statistics'][$direction]['covered_seconds']=0; }
        }
        return response()->json($data)->header('Cache-Control','no-store');
    }
}
