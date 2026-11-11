<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('olts', function (Blueprint $t) {
            $t->boolean('monitoring_enabled')->default(true);
            foreach (['state_observed_at','names_observed_at','optical_observed_at'] as $f) $t->timestamp($f)->nullable();
        });
        Schema::table('onus', function (Blueprint $t) {
            foreach (['state_observed_at','optical_observed_at','last_offline_at'] as $f) $t->timestamp($f)->nullable();
        });
        Schema::create('olt_operations', function (Blueprint $t) {
            $t->id(); $t->foreignId('tenant_id')->constrained()->cascadeOnDelete(); $t->foreignId('olt_id')->constrained()->cascadeOnDelete();
            $t->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('actor_type',16)->default('User'); $t->string('kind',20); $t->string('stage',20); $t->string('status',20)->default('queued');
            $t->unsignedBigInteger('active_olt_id')->nullable()->unique(); $t->unsignedTinyInteger('progress')->default(0);
            $t->string('current_step')->default('Menunggu worker'); $t->json('warnings')->nullable(); $t->text('error')->nullable(); $t->string('ip_address',45)->nullable();
            foreach (['started_at','heartbeat_at','completed_at'] as $f) $t->timestamp($f)->nullable();
            $t->timestamps(); $t->index(['tenant_id','olt_id','created_at']);
        });
        Schema::create('onu_status_logs', function (Blueprint $t) {
            $t->id(); $t->foreignId('onu_id')->constrained()->cascadeOnDelete(); $t->string('phase_state',20); $t->string('previous_state',20)->nullable(); $t->string('source',20); $t->timestamp('observed_at')->index();
        });
    }
    public function down(): void {
        Schema::dropIfExists('onu_status_logs'); Schema::dropIfExists('olt_operations');
        Schema::table('onus', fn(Blueprint $t)=>$t->dropColumn(['state_observed_at','optical_observed_at','last_offline_at']));
        Schema::table('olts', fn(Blueprint $t)=>$t->dropColumn(['monitoring_enabled','state_observed_at','names_observed_at','optical_observed_at']));
    }
};
