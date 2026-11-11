<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up():void{Schema::create('onu_configuration_changes',function(Blueprint $t){
        $t->id();$t->foreignId('tenant_id')->constrained()->cascadeOnDelete();$t->foreignId('olt_id')->constrained()->cascadeOnDelete();$t->foreignId('onu_id')->constrained()->cascadeOnDelete();$t->foreignId('created_by')->constrained('users')->cascadeOnDelete();
        $t->string('kind',20);$t->string('action',12);$t->string('status',16)->default('planned');$t->longText('payload');$t->string('confirmation_hash',64);$t->timestamp('expires_at');$t->text('result')->nullable();$t->timestamps();$t->index(['tenant_id','onu_id','created_at']);
    });}
    public function down():void{Schema::dropIfExists('onu_configuration_changes');}
};
