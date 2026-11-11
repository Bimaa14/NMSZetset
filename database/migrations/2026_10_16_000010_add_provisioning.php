<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('service_profiles',function(Blueprint $t){
            $t->id();$t->boolean('is_active')->default(true);$t->foreignId('tenant_id')->constrained()->cascadeOnDelete();$t->string('name',100);$t->string('onu_type',40);$t->string('mode',20)->default('registration');
            $t->string('upstream_profile',80)->nullable();$t->string('downstream_profile',80)->nullable();$t->unsignedSmallInteger('vlan_id')->nullable();$t->string('wan_profile',80)->nullable();$t->json('commands')->nullable();$t->timestamps();$t->unique(['tenant_id','name']);
        });
        Schema::create('provision_plans',function(Blueprint $t){
            $t->id();$t->foreignId('tenant_id')->constrained()->cascadeOnDelete();$t->foreignId('olt_id')->constrained()->cascadeOnDelete();$t->foreignId('created_by')->constrained('users')->cascadeOnDelete();$t->foreignId('service_profile_id')->constrained()->restrictOnDelete();
            $t->string('confirmation_hash',64);$t->string('profile_signature',64);$t->json('parameters');$t->string('status',20)->default('planned');$t->text('result')->nullable();$t->timestamp('expires_at');$t->timestamps();
        });
    }
    public function down():void{Schema::dropIfExists('provision_plans');Schema::dropIfExists('service_profiles');}
};
