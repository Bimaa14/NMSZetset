<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up():void{
        Schema::table('users',fn(Blueprint $t)=>$t->json('preferences')->nullable());
        Schema::table('olts',function(Blueprint $t){$t->decimal('latitude',10,7)->nullable();$t->decimal('longitude',10,7)->nullable();$t->timestamp('last_backup_at')->nullable();});
        Schema::create('olt_backups',function(Blueprint $t){$t->id();$t->foreignId('tenant_id')->constrained()->cascadeOnDelete();$t->foreignId('olt_id')->constrained()->cascadeOnDelete();$t->longText('configuration');$t->string('checksum',64);$t->unsignedInteger('bytes');$t->timestamp('created_at');});
    }
    public function down():void{Schema::dropIfExists('olt_backups');Schema::table('users',fn(Blueprint $t)=>$t->dropColumn('preferences'));Schema::table('olts',fn(Blueprint $t)=>$t->dropColumn(['latitude','longitude','last_backup_at']));}
};
