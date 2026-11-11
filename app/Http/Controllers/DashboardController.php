<?php

namespace App\Http\Controllers;

use App\Models\{AuditLog, Tag};
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request) { return response()->view('dashboard', ['initial' => $this->payload($request,true)])->header('Cache-Control','no-store, private, max-age=0'); }
    public function data(Request $request) { return response()->json($this->payload($request)); }

    public function activity(Request $request) {
        $tenant=$request->user()->tenant_id;
        abort_unless($request->user()->allows('read'),403);
        return response()->json([
            'active_operations'=>\App\Models\OltOperation::where('tenant_id',$tenant)->whereIn('status',['queued','running'])->latest('id')->get(),
            'recent_operations'=>\App\Models\OltOperation::where('tenant_id',$tenant)->whereNotNull('completed_at')->latest('completed_at')->latest('id')->limit(100)->get(),
            'monitor'=>['scheduler'=>\Illuminate\Support\Facades\Cache::get('nms:monitor:heartbeat'),'worker'=>\Illuminate\Support\Facades\Cache::get('nms:worker:heartbeat')],
        ])->header('Cache-Control','no-store');
    }

    private function payload(Request $request,bool $compact=false): array
    {
        $user = $request->user(); $tenant = $user->tenant;
        abort_unless($tenant && $user->allows('read'), 403); if ($tenant->name === 'Bima Network') $tenant->update(['name' => 'Network']);
        app(\App\Services\DemoOltOperations::class)->settleReboots($tenant->id);
        $olts = $tenant->olts()->with(['ports' => fn ($q) => $q->current()])->withCount(['onus' => fn ($q) => $q->current(), 'onus as online_onu' => fn ($q) => $q->current()->where('phase_state', 'working'), 'ports' => fn ($q) => $q->current()])->orderBy('id')->get();
        $lastSync = $olts->max('last_sync_at');
        $opticalTime = $olts->max('optical_observed_at');
        $compact=$compact||$request->boolean('compact');
        $onus = $compact ? collect() : $tenant->onus()->current()->with(['tags:id,tag_name,color_hex', 'port:id,interface_name', 'opticalLogs' => fn ($q) => $q->whereHas('onu',fn($parent)=>$parent->whereColumn('onus.observation_source','onu_optical_logs.observation_source'))->orderByDesc('calculated_at')->orderByDesc('id')->limit(2)])->orderBy('id')->get()->map(function ($onu) use (&$opticalTime) {
            $row = $onu->toArray();
            $previous = $onu->opticalLogs->get(1)?->rx_power;
            $row['previous_rx'] = $previous !== null ? (float) $previous : null;
            $latest = $onu->opticalLogs->first()?->calculated_at;
            if ($latest && (!$opticalTime || $latest > $opticalTime)) $opticalTime = $latest;
            $row['interface'] = str_replace(['gpon-olt_', 'epon-olt_'], ['gpon-onu_', 'epon-onu_'], $onu->port->interface_name).':'.$onu->onu_index;
            unset($row['optical_logs']); return $row;
        });
        foreach ($olts as $olt) {
            $olt->setAttribute('capabilities',app(\App\Services\Olt\OltCapabilities::class)->describe($olt));
            $olt->setAttribute('traffic_coverage',$olt->capabilities['features']['traffic']?app(\App\Services\Olt\OltTrafficService::class)->coverage($olt):null);
            if ($olt->connection_mode !== 'real') continue;
            $metrics = $olt->telemetry_source === 'real' ? ($olt->boards['metrics'] ?? []) : [];
            foreach (['cpu','temperature','uptime','firmware_version','memory_usage'] as $key) $olt->setAttribute($key, $metrics[$key] ?? null);
        }
        $ops = \App\Models\OltOperation::where('tenant_id',$tenant->id)->whereIn('id',fn($q)=>$q->selectRaw('MAX(id)')->from('olt_operations')->where('tenant_id',$tenant->id)->groupBy('olt_id'))->get()->keyBy('olt_id');
        return [
            'active_operations'=>\App\Models\OltOperation::where('tenant_id',$tenant->id)->whereIn('status',['queued','running'])->latest('id')->get(),'recent_operations'=>\App\Models\OltOperation::where('tenant_id',$tenant->id)->whereNotNull('completed_at')->latest('completed_at')->latest('id')->limit(100)->get(), 'operations'=>$ops, 'monitor'=>['scheduler'=>\Illuminate\Support\Facades\Cache::get('nms:monitor:heartbeat'),'worker'=>\Illuminate\Support\Facades\Cache::get('nms:worker:heartbeat')],
            'user' => $user->only('id', 'name', 'email', 'role','preferences'), 'tenant' => $tenant,
            'permissions' => collect(['read', 'operate', 'manage', 'cli', 'maps', 'tags', 'staff', 'audit', 'api', 'subscription'])->mapWithKeys(fn ($p) => [$p => $user->allows($p)]),
            'olts' => $olts, 'onus' => $onus, 'compact'=>$compact,
            'summary'=>['total_onus'=>$olts->sum('onus_count'),'online_onus'=>$olts->sum('online_onu'),'los_onus'=>$tenant->onus()->current()->where('phase_state','los')->count(),'weak_onus'=>$tenant->onus()->current()->where('rx_power','<=',config('nms.bad_rx_threshold'))->count(),'degraded_onus'=>count(app(\App\Services\OpticalAnalysis::class)->forTenant($tenant->id)['degraded_ids'])],
            'staff' => $user->allows('staff') ? $tenant->users()->get(['id', 'name', 'email', 'role']) : [],
            'tags' => Tag::where('tenant_id', $tenant->id)->withCount(['onus'=>fn($q)=>$q->current()])->get(),
            'audit' => $user->allows('audit') ? AuditLog::where('tenant_id', $tenant->id)->latest('id')->limit(100)->get() : [],
            'subscription' => (bool) config('nms.subscription'), 'demo' => app()->environment('local') && config('nms.demo'),
            'equipment_updated_at' => $lastSync ? $lastSync->timezone('Asia/Jakarta')->format('H.i').' WIB' : 'belum tersedia', 'optical_calculated_at' => $opticalTime ? \Illuminate\Support\Carbon::parse($opticalTime)->timezone('Asia/Jakarta')->format('H.i').' WIB' : 'belum tersedia', 'threshold' => config('nms.bad_rx_threshold'),
        ];
    }
}
