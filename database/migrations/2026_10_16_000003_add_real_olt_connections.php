<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('olts', function (Blueprint $t) {
            $t->string('connection_mode')->default('simulator');
            $t->string('cli_transport')->default('telnet');
            $t->unsignedSmallInteger('connection_timeout')->default(5);
            $t->string('ssh_host_key_sha256')->nullable();
            $t->boolean('snmp_enabled')->default(false);
            $t->string('telemetry_source')->nullable();
            $t->timestamp('system_observed_at')->nullable();
            $t->timestamp('last_connection_check_at')->nullable();
            $t->text('last_connection_error')->nullable();
            $t->json('boards')->nullable();
            $t->decimal('memory_usage', 5, 2)->nullable();
        });
        Schema::table('olt_ports', function (Blueprint $t) {
            $t->string('observation_source')->default('simulator');
            $t->boolean('is_present')->default(true);
            $t->timestamp('last_seen_at')->nullable();
        });
        Schema::table('onus', function (Blueprint $t) {
            $t->string('observation_source')->default('simulator');
            $t->boolean('is_present')->default(true);
            $t->timestamp('last_seen_at')->nullable();
            $t->string('admin_state')->nullable();
            $t->string('omcc_state')->nullable();
            $t->string('onu_type')->nullable();
        });
        Schema::table('onu_optical_logs', function (Blueprint $t) {
            $t->decimal('rx_power', 6, 2)->nullable()->change();
            $t->decimal('olt_rx_power', 6, 2)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('onus', fn (Blueprint $t) => $t->dropColumn(['observation_source','is_present','last_seen_at','admin_state','omcc_state','onu_type']));
        Schema::table('olt_ports', fn (Blueprint $t) => $t->dropColumn(['observation_source','is_present','last_seen_at']));
        Schema::table('olts', fn (Blueprint $t) => $t->dropColumn(['connection_mode','cli_transport','connection_timeout','ssh_host_key_sha256','snmp_enabled','telemetry_source','system_observed_at','last_connection_check_at','last_connection_error','boards','memory_usage']));
        // Nullable optical samples remain valid: restoring NOT NULL would destroy genuine no-signal history.
    }
};
