<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up():void {
        Schema::table('service_profiles',fn(Blueprint $t)=>$t->json('verification')->nullable());
        Schema::create('snmp_trap_events',function(Blueprint $t){$t->id();$t->foreignId('tenant_id')->constrained()->cascadeOnDelete();$t->foreignId('olt_id')->constrained()->cascadeOnDelete();$t->string('source_ip',45);$t->string('trap_oid',255);$t->text('payload');$t->timestamp('received_at')->index();});
    }
    public function down():void {Schema::dropIfExists('snmp_trap_events');Schema::table('service_profiles',fn(Blueprint $t)=>$t->dropColumn('verification'));}
};
