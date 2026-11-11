<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->string('plan_type')->default('Trial');
            $t->unsignedInteger('olt_quota')->default(1); $t->decimal('balance', 14, 2)->default(0);
            $t->timestamp('expired_at')->nullable(); $t->timestamps();
        });
        Schema::table('users', function (Blueprint $t) {
            $t->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $t->string('role')->default('cs');
        });
        Schema::create('api_tokens', function (Blueprint $t) {
            $t->id(); $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('name'); $t->string('token_hash', 64)->unique(); $t->json('abilities');
            $t->timestamp('expires_at')->nullable(); $t->timestamp('last_used_at')->nullable(); $t->timestamps();
        });
        Schema::create('olts', function (Blueprint $t) {
            $t->id(); $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('name'); $t->string('chassis_type'); $t->ipAddress('ip_address');
            $t->unsignedSmallInteger('telnet_port')->default(23); $t->string('telnet_user')->nullable();
            $t->text('telnet_pass_enc')->nullable(); $t->unsignedSmallInteger('snmp_port')->default(161);
            $t->text('snmp_community_enc')->nullable(); $t->string('status')->default('Offline');
            $t->timestamp('last_sync_at')->nullable(); $t->string('location')->nullable();
            $t->unsignedSmallInteger('cpu')->default(0); $t->decimal('temperature', 5, 1)->default(0);
            $t->string('uptime')->nullable(); $t->timestamps();
            $t->unique(['tenant_id', 'name']); $t->unique(['tenant_id', 'ip_address', 'telnet_port']);
        });
        Schema::create('olt_ports', function (Blueprint $t) {
            $t->id(); $t->foreignId('olt_id')->constrained()->cascadeOnDelete();
            $t->unsignedSmallInteger('slot_no'); $t->unsignedSmallInteger('port_no');
            $t->string('interface_name'); $t->string('description')->nullable();
            $t->unsignedInteger('total_onu')->default(0); $t->unsignedInteger('online_onu')->default(0); $t->timestamps();
            $t->unique(['olt_id', 'interface_name']);
        });
        Schema::create('onus', function (Blueprint $t) {
            $t->id(); $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('olt_id')->constrained()->cascadeOnDelete();
            $t->foreignId('olt_port_id')->constrained()->cascadeOnDelete(); $t->unsignedSmallInteger('onu_index');
            $t->string('name'); $t->string('sn'); $t->text('description')->nullable(); $t->string('phase_state');
            $t->decimal('rx_power', 6, 2)->nullable(); $t->decimal('olt_rx_power', 6, 2)->nullable();
            $t->string('pppoe_user')->nullable(); $t->unsignedSmallInteger('vlan_id')->nullable();
            $t->decimal('latitude', 10, 7)->nullable(); $t->decimal('longitude', 10, 7)->nullable(); $t->timestamps();
            $t->unique(['tenant_id', 'sn']); $t->index(['tenant_id', 'name']);
        });
        Schema::create('onu_optical_logs', function (Blueprint $t) {
            $t->id(); $t->foreignId('onu_id')->constrained()->cascadeOnDelete();
            $t->decimal('rx_power', 6, 2); $t->decimal('olt_rx_power', 6, 2);
            $t->timestamp('calculated_at'); $t->index(['onu_id', 'calculated_at']);
        });
        Schema::create('tags', function (Blueprint $t) {
            $t->id(); $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('tag_name'); $t->string('color_hex', 7); $t->timestamps();
            $t->unique(['tenant_id', 'tag_name']);
        });
        Schema::create('onu_tag', function (Blueprint $t) {
            $t->foreignId('onu_id')->constrained()->cascadeOnDelete(); $t->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $t->primary(['onu_id', 'tag_id']);
        });
        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id(); $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('actor_type'); $t->unsignedBigInteger('actor_id');
            $t->foreignId('olt_id')->nullable()->constrained()->nullOnDelete();
            $t->string('action'); $t->string('target'); $t->ipAddress('ip_address')->nullable(); $t->timestamp('created_at');
        });
    }

    public function down(): void
    {
        foreach (['audit_logs', 'onu_tag', 'tags', 'onu_optical_logs', 'onus', 'olt_ports', 'olts', 'api_tokens'] as $table) Schema::dropIfExists($table);
        Schema::table('users', function (Blueprint $t) { $t->dropConstrainedForeignId('tenant_id'); $t->dropColumn('role'); });
        Schema::dropIfExists('tenants');
    }
};
