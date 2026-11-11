<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up():void{
        Schema::table('onu_traffic_logs',fn(Blueprint $t)=>$t->index(['olt_id','recorded_at']));
        Schema::table('onus',fn(Blueprint $t)=>$t->index(['tenant_id','olt_id','phase_state']));
        // Existing indexes already cover onu_id/recorded_at and tenant_id/sn.
        // Optical history uses calculated_at, indexed with onu_id since its creation.
    }
    public function down():void{
        Schema::table('onu_traffic_logs',fn(Blueprint $t)=>$t->dropIndex(['olt_id','recorded_at']));
        Schema::table('onus',fn(Blueprint $t)=>$t->dropIndex(['tenant_id','olt_id','phase_state']));
    }
};
