<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('olts', function (Blueprint $table) {
            $table->boolean('traffic_monitoring_enabled')->default(false);
            $table->json('traffic_pon_ports')->nullable();
            $table->unsignedSmallInteger('traffic_telnet_port')->default(23);
            $table->string('traffic_telnet_user', 80)->nullable();
            $table->text('traffic_telnet_pass_enc')->nullable();
            $table->text('traffic_enable_pass_enc')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('olts', fn (Blueprint $table) => $table->dropColumn([
            'traffic_monitoring_enabled','traffic_pon_ports','traffic_telnet_port','traffic_telnet_user',
            'traffic_telnet_pass_enc','traffic_enable_pass_enc',
        ]));
    }
};
