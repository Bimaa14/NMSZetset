<?php

namespace App\Http\Controllers;

use App\Models\Onu;
use App\Services\{AuditRecorder, DemoOltOperations};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Gate};
use Illuminate\Validation\Rule;

class OnuController extends Controller
{
    public function reboot(Request $request, int $id, DemoOltOperations $operations, AuditRecorder $audit)
    {
        $onu = Onu::where('tenant_id', $request->user()->tenant_id)->findOrFail($id);
        Gate::authorize('reboot', $onu);
        app(\App\Services\Olt\OltCapabilities::class)->requireFeature($onu->olt,'reboot');
        if ($onu->olt->connection_mode === 'real') {
            abort_unless($onu->is_present && $onu->observation_source === 'real',422,'ONU belum terverifikasi dari perangkat nyata. Jalankan Sync terlebih dahulu.');
            return app(\App\Services\Olt\RealOltOperations::class)->control($onu,function()use($onu,$request,$audit){
                try { app(\App\Services\Olt\ZteTelnetDriver::class)->reboot($onu); }
                catch(\App\Services\Olt\OltConnectionException $e) { $audit->record($request,'onu.reboot.'.$e->reason,$onu->sn,$onu->olt_id); throw $e; }
                // Acknowledgement is not proof of the ONU's new physical state. Sync confirms it.
                $onu->update(['reboot_requested_at'=>now(),'reboot_complete_at'=>null,'reboot_restore_state'=>null]);
                $log=$audit->record($request,'onu.reboot.real',$onu->name.' · '.$onu->sn,$onu->olt_id);
                return response()->json(['message'=>'OLT menerima perintah reboot. Jalankan Sync untuk memastikan status ONU terkini.','audit_log_id'=>$log->id,'source'=>'real']);
            });
        }
        $operations->requireAvailable();
        $operations->settleReboots($onu->tenant_id);
        return DB::transaction(function () use ($request, $id, $operations, $audit) {
            $onu = Onu::where('tenant_id', $request->user()->tenant_id)->lockForUpdate()->findOrFail($id);
            abort_if($onu->phase_state === 'rebooting', 409, 'ONU ini masih dalam proses reboot.');
            abort_unless($onu->olt->status === 'Online', 422, 'OLT offline. Reboot tidak dapat dijalankan.');
            $onu->update(['reboot_restore_state' => $onu->phase_state, 'phase_state' => 'rebooting', 'reboot_requested_at' => now(), 'reboot_complete_at' => now()->addSeconds(8)]);
            $operations->refreshPortCounts($onu->olt);
            $log = $audit->record($request, 'onu.reboot.demo', $onu->name.' · '.$onu->sn, $onu->olt_id);
            return response()->json(['message' => 'Reboot ONU demo dimulai. Status diperbarui otomatis dalam sekitar 8 detik.', 'audit_log_id' => $log->id], 202);
        });
    }

    public function tags(Request $request, int $id, AuditRecorder $audit)
    {
        $onu = Onu::where('tenant_id', $request->user()->tenant_id)->findOrFail($id);
        Gate::authorize('tag', $onu);
        $input = $request->validate([
            'tag_ids' => 'present|array|max:30',
            'tag_ids.*' => ['integer', 'distinct', Rule::exists('tags', 'id')->where('tenant_id', $onu->tenant_id)],
        ]);
        DB::transaction(function () use ($request, $onu, $input, $audit) {
            $onu->tags()->sync($input['tag_ids']);
            $audit->record($request, 'onu.tags.update', $onu->name.' · '.$onu->sn, $onu->olt_id);
        });
        return response()->json(['message' => 'Tag ONU berhasil disimpan.']);
    }
}
