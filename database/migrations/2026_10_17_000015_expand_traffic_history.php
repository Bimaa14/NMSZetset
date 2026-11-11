<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{Schema,DB};
return new class extends Migration {
    public function up():void{
        Schema::table('onu_traffic_logs',function(Blueprint $t){
            $t->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();$t->foreignId('olt_id')->nullable()->constrained()->cascadeOnDelete();
            $t->double('rx_bps')->nullable();$t->double('tx_bps')->nullable();$t->string('rx_bytes',20)->nullable();$t->string('tx_bytes',20)->nullable();
            $t->string('source',12)->default('snmp');$t->timestamp('recorded_at')->nullable();$t->index(['onu_id','recorded_at']);
            $t->double('download_bps')->nullable()->change();$t->double('upload_bps')->nullable()->change();
        });
        DB::table('onu_traffic_logs')->update(['rx_bps'=>DB::raw('download_bps'),'tx_bps'=>DB::raw('upload_bps'),'recorded_at'=>DB::raw('observed_at')]);
        DB::table('onu_traffic_logs')->select('onu_id')->distinct()->orderBy('onu_id')->get()->each(function($row){$onu=DB::table('onus')->find($row->onu_id);if($onu)DB::table('onu_traffic_logs')->where('onu_id',$row->onu_id)->update(['tenant_id'=>$onu->tenant_id,'olt_id'=>$onu->olt_id]);});
        Schema::create('port_traffic_logs',function(Blueprint $t){
            $t->id();$t->foreignId('tenant_id')->constrained()->cascadeOnDelete();$t->foreignId('olt_id')->constrained()->cascadeOnDelete();$t->foreignId('olt_port_id')->constrained()->cascadeOnDelete();
            $t->double('rx_bps')->nullable();$t->double('tx_bps')->nullable();$t->string('rx_bytes',20)->nullable();$t->string('tx_bytes',20)->nullable();$t->string('source',12);$t->timestamp('recorded_at');$t->index(['olt_port_id','recorded_at']);
        });
    }
    public function down():void{Schema::dropIfExists('port_traffic_logs');Schema::table('onu_traffic_logs',function(Blueprint $t){$t->dropIndex(['onu_id','recorded_at']);$t->dropConstrainedForeignId('tenant_id');$t->dropConstrainedForeignId('olt_id');$t->dropColumn(['rx_bps','tx_bps','rx_bytes','tx_bytes','source','recorded_at']);});}
};
