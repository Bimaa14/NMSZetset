<?php

namespace App\Http\Controllers;

use App\Models\{AuditLog, Olt};
use App\Services\{AuditRecorder, DemoOltOperations};
use App\Services\Olt\{OltCapabilities, OltConnectionException, RealOltOperations, YotcHttpDriver, ZteTelnetDriver, ZteSnmpDriver};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Gate};
use Illuminate\Validation\Rule;

class OltController extends Controller
{
    private function scoped(Request $request, int $id): Olt
    {
        return Olt::where('tenant_id', $request->user()->tenant_id)->findOrFail($id);
    }
    private function input(Request $request, ?Olt $olt = null): array
    {
        if (!$request->has('telnet_user') && $request->has('telnet_username')) $request->merge(['telnet_user' => $request->input('telnet_username')]);
        $yotc = $request->input('chassis_type', $olt?->chassis_type) === 'C1600';
        $transportRequired = $yotc || ($olt && $request->input('chassis_type') !== $olt->chassis_type && !in_array($olt->cli_transport, ['telnet','ssh'], true));
        if ($yotc) $request->merge(['connection_mode'=>'real', 'snmp_enabled'=>false]);
        $input = $request->validate([
            'name' => ['required', 'string', 'max:80', Rule::unique('olts')->where('tenant_id', $request->user()->tenant_id)->ignore($olt?->id)],
            'chassis_type' => 'required|in:C300,C320,C1600',
            'ip_address' => ['required', 'ip', Rule::unique('olts')->where('tenant_id', $request->user()->tenant_id)->where('telnet_port', $request->input('telnet_port'))->ignore($olt?->id)],
            'telnet_port' => 'required|integer|min:1|max:65535', 'snmp_port' => 'required|integer|min:1|max:65535', 'location' => 'nullable|string|max:120',
            'telnet_user' => 'required|string|max:80',
            'telnet_password' => [$olt ? 'nullable' : 'required', 'string', 'max:255'],
            'snmp_community' => [$olt || $yotc ? 'nullable' : 'required', 'string', 'max:255'],
            'latitude'=>'nullable|numeric|between:-90,90','longitude'=>'nullable|numeric|between:-180,180',
            'connection_mode' => 'sometimes|required|in:real,simulator',
            'cli_transport' => $yotc ? 'required|in:http,https' : ($transportRequired ? 'required|in:telnet,ssh' : 'sometimes|required|in:telnet,ssh'),
            'connection_timeout' => 'sometimes|required|integer|min:1|max:30',
            'ssh_host_key_sha256' => ['nullable','regex:/^SHA256:[A-Za-z0-9+\/]{43}$/'],
            'snmp_enabled' => 'sometimes|boolean',
            'traffic_monitoring_enabled' => 'sometimes|boolean',
            'traffic_pon_ports' => 'sometimes|array|max:4',
            'traffic_pon_ports.*' => ['string','distinct','regex:/^(?:[1-9]|1[0-6])\/(?:[1-9]|[12][0-9]|3[0-2])$/'],
            'traffic_telnet_port' => 'sometimes|required|integer|min:1|max:65535',
            'traffic_telnet_user' => ['nullable','string','max:80','not_regex:/[\x00-\x1f\x7f\xff]/'],
            'traffic_telnet_password' => ['nullable','string','max:255','not_regex:/[\x00-\x1f\x7f\xff]/'],
            'traffic_enable_password' => ['nullable','string','max:255','not_regex:/[\x00-\x1f\x7f\xff]/'],
        ]);
        if($yotc) {
            $enabled=(bool)($input['traffic_monitoring_enabled']??$olt?->traffic_monitoring_enabled??false);
            $ports=array_values($input['traffic_pon_ports']??$olt?->traffic_pon_ports??[]);
            if($enabled) {
                $errors=[];
                if(!$ports)$errors['traffic_pon_ports']='Pilih PON yang sampling traffic-nya sudah diaktifkan di OLT.';
                if(empty($input['traffic_telnet_password']) && !$olt?->getRawOriginal('traffic_telnet_pass_enc'))$errors['traffic_telnet_password']='Password Telnet traffic diperlukan.';
                if($errors)throw \Illuminate\Validation\ValidationException::withMessages($errors);
                $known=$olt?->ports()->current()->where('interface_name','like','epon-olt_%')->get()->map(fn($port)=>$port->slot_no.'/'.$port->port_no)->all()??[];
                if(array_diff($ports,$known))throw \Illuminate\Validation\ValidationException::withMessages(['traffic_pon_ports'=>'Sinkronkan inventory terlebih dahulu, lalu pilih PON C1600 yang tersedia.']);
            }
            $input['traffic_monitoring_enabled']=$enabled;
            $input['traffic_pon_ports']=$ports;
        } else {
            $input['traffic_monitoring_enabled']=false;
            $input['traffic_pon_ports']=[];
            unset($input['traffic_telnet_port'],$input['traffic_telnet_user'],$input['traffic_telnet_password'],$input['traffic_enable_password']);
        }
        foreach (['telnet_password' => 'telnet_pass_enc', 'snmp_community' => 'snmp_community_enc', 'traffic_telnet_password'=>'traffic_telnet_pass_enc','traffic_enable_password'=>'traffic_enable_pass_enc'] as $field => $column) {
            if (isset($input[$field]) && $input[$field] !== '') $input[$column] = $input[$field];
            unset($input[$field]);
        }
        return $input;
    }
    private function log(Request $request, Olt $olt, string $action): void
    {
        app(AuditRecorder::class)->record($request, $action, $olt->name, $olt->exists ? $olt->id : null);
    }
    public function settings(Request $request, int $id)
    {
        $olt = $this->scoped($request, $id); Gate::authorize('update', $olt);
        return response()->json($olt->only(['name', 'chassis_type', 'ip_address', 'telnet_port', 'telnet_user', 'snmp_port', 'location','latitude','longitude', 'connection_mode','cli_transport','connection_timeout','ssh_host_key_sha256','snmp_enabled','traffic_monitoring_enabled','traffic_pon_ports','traffic_telnet_port','traffic_telnet_user']) + [
            'has_telnet_password' => $olt->getRawOriginal('telnet_pass_enc') !== null,
            'has_snmp_community' => $olt->getRawOriginal('snmp_community_enc') !== null,
            'has_traffic_telnet_password' => $olt->getRawOriginal('traffic_telnet_pass_enc') !== null,
            'has_traffic_enable_password' => $olt->getRawOriginal('traffic_enable_pass_enc') !== null,
        ])->header('Cache-Control', 'no-store');
    }
    public function store(Request $request)
    {
        Gate::authorize('create', Olt::class); $input = $this->input($request);
        return DB::transaction(function () use ($request, $input) {
            $tenant = $request->user()->tenant()->lockForUpdate()->firstOrFail();
            abort_if(config('nms.subscription') && $tenant->olts()->count() >= $tenant->olt_quota, 422, 'Kuota OLT penuh. Hapus OLT atau tingkatkan paket terlebih dahulu.');
            $olt = $tenant->olts()->create($input + ['status' => 'Offline']); $this->log($request, $olt, 'olt.create');
            return response()->json(['message' => 'OLT ditambahkan. Menunggu koneksi poller.'], 201);
        });
    }
    public function update(Request $request, int $id)
    {
        $olt = $this->scoped($request, $id); Gate::authorize('update', $olt); $input = $this->input($request, $olt);
        abort_if(\App\Models\OltOperation::where('active_olt_id',$olt->id)->exists(),409,'Tunggu operasi background selesai sebelum mengubah atau menghapus OLT.');
        app(RealOltOperations::class)->locked($olt, fn () => DB::transaction(function () use ($olt, $input, $request) {
            $olt->fill($input);
            if($olt->isDirty(['chassis_type','connection_mode','ip_address','traffic_monitoring_enabled','traffic_pon_ports','traffic_telnet_port','traffic_telnet_user','traffic_telnet_pass_enc','traffic_enable_pass_enc'])) {
                $olt->traffic_observed_at=null;
                $olt->ports()->update(['download_bps'=>null,'upload_bps'=>null,'traffic_observed_at'=>null]);
            }
            if ($olt->isDirty(['chassis_type','connection_mode','ip_address','telnet_port','cli_transport'])) {
                $olt->fill(['status'=>'Offline','last_connection_error'=>null,'telemetry_source'=>null,'system_observed_at'=>null,'last_sync_at'=>null]);
                $olt->onus()->update(['is_present'=>false]); $olt->ports()->update(['is_present'=>false]);
            }
            $olt->save(); $this->log($request, $olt, 'olt.update');
        }));
        return response()->json(['message' => 'Konfigurasi OLT berhasil disimpan.']);
    }
    public function destroy(Request $request, int $id)
    {
        $olt = $this->scoped($request, $id); Gate::authorize('delete', $olt);
        abort_if(\App\Models\OltOperation::where('active_olt_id',$olt->id)->exists(),409,'Tunggu operasi background selesai sebelum mengubah atau menghapus OLT.');
        app(RealOltOperations::class)->locked($olt, fn () => DB::transaction(function () use ($request, $olt) { $this->log($request, $olt, 'olt.delete'); $olt->delete(); }));
        return response()->json(['message' => 'OLT dan data ONU terkait telah dihapus.']);
    }
    public function sync(Request $request, int $id, DemoOltOperations $operations, RealOltOperations $real)
    {
        $olt = $this->scoped($request, $id); Gate::authorize('sync', $olt);
        if ($olt->connection_mode === 'real') {
            try { $warnings = $real->sync($olt); $this->log($request, $olt, 'olt.sync.real'); }
            catch (OltConnectionException $e) { $this->log($request, $olt, 'olt.sync.failed'); throw $e; }
            return response()->json(['message'=>'Sinkronisasi OLT nyata berhasil. Port, ONU dan log optik diperbarui.','warnings'=>$warnings,'source'=>'real','last_sync_at'=>$olt->fresh()->last_sync_at]);
        }
        DB::transaction(function () use ($request, $olt, $operations) {
            $operations->sync($olt); $this->log($request, $olt, 'olt.sync.demo');
        });
        return response()->json(['message' => 'Sinkronisasi demo selesai. Rekap port/ONU dan timestamp diperbarui dari database lokal.', 'last_sync_at' => $olt->last_sync_at]);
    }
    public function cli(Request $request, int $id)
    {
        $olt = $this->scoped($request, $id); Gate::authorize('cli', $olt);
        app(OltCapabilities::class)->requireFeature($olt, 'cli');
        $command = $request->validate(['command' => ['required','string','max:512','not_regex:/[\x00-\x1f\x7f\xff]/']])['command'];
        if ($olt->connection_mode === 'real') {
            try {
                $output = app(RealOltOperations::class)->locked($olt, fn () => app(ZteTelnetDriver::class)->cli($olt, $command));
                $this->log($request, $olt, 'olt.cli.real');
                return response()->json(['output'=>$output,'source'=>'real']);
            } catch (OltConnectionException $e) { $this->log($request,$olt,'olt.cli.failed'); throw $e; }
        }
        abort_unless(config('nms.demo') && app()->environment('local'), 503, 'Simulator belum diaktifkan pada lingkungan ini.');
        $output = match (trim(strtolower($command))) {
            'help', '?' => "Perintah simulator:\nshow version\nshow card\nshow processor\nshow temperature\nshow gpon onu state\nshow gpon onu optical-power\nclear\nGunakan ↑/↓ untuk riwayat perintah. Ctrl+L untuk membersihkan terminal.",
            'show version' => "ZTE ZXAN {$olt->chassis_type}\n{$olt->name}\nFirmware: ".($olt->firmware_version ?? 'Belum tersedia')."\nUptime: {$olt->uptime}\nSIMULATOR — tidak terhubung ke OLT.",
            'show processor' => "CPU load: {$olt->cpu}% (snapshot demo)",
            'show temperature' => "Chassis temperature: {$olt->temperature} °C (snapshot demo)",
            'show gpon onu optical-power' => $olt->onus()->with('port')->get()->map(fn ($o) => "{$o->port->interface_name}:{$o->onu_index}  RX {$o->rx_power} dBm  OLT RX {$o->olt_rx_power} dBm")->join("\n"),
            'show card' => $olt->ports()->get()->map(fn ($p) => "Slot {$p->slot_no}  {$p->interface_name}  ONU {$p->total_onu}  INSERVICE")->join("\n"),
            'show gpon onu state' => $olt->onus()->with('port')->get()->map(fn ($o) => "{$o->port->interface_name}:{$o->onu_index}  {$o->phase_state}  {$o->sn}")->join("\n"),
            default => 'Perintah tidak tersedia. Ketik help untuk daftar perintah simulator.',
        };
        $this->log($request, $olt, 'olt.cli.demo'); return response()->json(['output' => $output]);
    }

    public function refresh(Request $request, int $id, RealOltOperations $real)
    {
        $olt=$this->scoped($request,$id); Gate::authorize('view',$olt);
        $operation=$request->validate(['operation'=>'required|in:view,ports'])['operation'];
        if($olt->connection_mode!=='real') return response()->json(['source'=>'simulator','warnings'=>[]]);
        try { $warnings=$operation==='view'?$real->view($olt):$real->sync($olt); $this->log($request,$olt,'olt.read.'.$operation); }
        catch(OltConnectionException $e) { $this->log($request,$olt,'olt.read.failed'); throw $e; }
        return response()->json(['source'=>'real','warnings'=>$warnings]);
    }

    public function testConnection(Request $request)
    {
        $request->validate(['olt_id'=>'nullable|integer','protocol'=>'required|in:cli,snmp,both,web']);
        $saved=$request->filled('olt_id')?$this->scoped($request,(int)$request->input('olt_id')):null;
        $saved ? Gate::authorize('update',$saved) : Gate::authorize('create',Olt::class);
        if(!$request->has('telnet_user') && $request->has('telnet_username'))$request->merge(['telnet_user'=>$request->input('telnet_username')]);
        $model=$request->input('chassis_type',$saved?->chassis_type??'C320');
        $yotc=$model==='C1600';
        abort_if($yotc && $request->input('protocol')!=='web',422,'Gunakan test koneksi web untuk YOTC C1600.');
        abort_if(!$yotc && $request->input('protocol')==='web',422,'Koneksi web ini hanya didukung untuk YOTC C1600.');
        $input=$request->validate([
            'ip_address'=>'required|ip','telnet_port'=>'required|integer|min:1|max:65535','snmp_port'=>'required|integer|min:1|max:65535',
            'telnet_user'=>'nullable|string|max:80','telnet_password'=>'nullable|string|max:255','snmp_community'=>'nullable|string|max:255',
            'chassis_type'=>'sometimes|in:C300,C320,C1600',
            'cli_transport'=>$yotc?'required|in:http,https':'required|in:telnet,ssh','connection_timeout'=>'required|integer|min:1|max:30','ssh_host_key_sha256'=>['nullable','regex:/^SHA256:[A-Za-z0-9+\/]{43}$/'],
        ]);
        unset($input['telnet_password'],$input['snmp_community']);
        $probe=new Olt($input+['chassis_type'=>$model]); $probe->telnet_pass_enc=$request->filled('telnet_password')?$request->input('telnet_password'):$saved?->telnet_pass_enc;
        $probe->snmp_community_enc=$request->filled('snmp_community')?$request->input('snmp_community'):$saved?->snmp_community_enc;
        try {
            $results=[];
            if($yotc) {
                $test=fn()=>app(YotcHttpDriver::class)->test($probe);
                $results['web']=$saved?app(RealOltOperations::class)->monitoring($saved,$test):$test();
            }
            if(in_array($request->input('protocol'),['cli','both'],true)) $results['cli']=app(ZteTelnetDriver::class)->test($probe);
            if(in_array($request->input('protocol'),['snmp','both'],true)) $results['snmp']=app(ZteSnmpDriver::class)->system($probe);
            app(AuditRecorder::class)->record($request,'olt.connection.test','Test koneksi '.$probe->ip_address,$saved?->id);
            return response()->json(['message'=>'Koneksi nyata berhasil.','results'=>$results,'source'=>'real']);
        } catch(OltConnectionException $e) { app(AuditRecorder::class)->record($request,'olt.connection.failed','Test koneksi '.$probe->ip_address,$saved?->id); throw $e; }
    }
}
