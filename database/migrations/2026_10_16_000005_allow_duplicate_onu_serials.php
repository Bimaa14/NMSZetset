<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('onus', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'sn']);
            $table->index(['tenant_id', 'sn']);
            $table->unique(['olt_id', 'olt_port_id', 'onu_index', 'observation_source', 'sn'], 'onus_observation_identity_unique');
        });
    }

    public function down(): void
    {
        // Never delete real observations to restore the former serial-only identity.
        if (DB::table('onus')->select('tenant_id', 'sn')->groupBy('tenant_id', 'sn')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Rollback membutuhkan rekonsiliasi SN ganda; tidak ada inventaris yang dihapus.');
        }
        Schema::table('onus', function (Blueprint $table) {
            $table->unique(['tenant_id', 'sn']);
            $table->dropUnique('onus_observation_identity_unique');
            $table->dropIndex(['tenant_id', 'sn']);
        });
    }
};
