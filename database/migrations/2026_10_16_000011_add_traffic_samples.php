<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up():void{
        Schema::table('olts',fn(Blueprint $t)=>$t->timestamp('traffic_observed_at')->nullable());
        foreach(['onus','olt_ports'] as $name)Schema::table($name,function(Blueprint $t){$t->double('download_bps')->nullable();$t->double('upload_bps')->nullable();$t->timestamp('traffic_observed_at')->nullable();});
        Schema::create('onu_traffic_logs',function(Blueprint $t){$t->id();$t->foreignId('onu_id')->constrained()->cascadeOnDelete();$t->double('download_bps');$t->double('upload_bps');$t->timestamp('observed_at')->index();});
    }
    public function down():void{Schema::dropIfExists('onu_traffic_logs');Schema::table('olts',fn(Blueprint $t)=>$t->dropColumn('traffic_observed_at'));foreach(['onus','olt_ports'] as $name)Schema::table($name,fn(Blueprint $t)=>$t->dropColumn(['download_bps','upload_bps','traffic_observed_at']));}
};
