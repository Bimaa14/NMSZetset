<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('olts', function (Blueprint $table) {
            $table->string('firmware_version')->nullable();
        });
        Schema::table('onus', function (Blueprint $table) {
            $table->timestamp('reboot_requested_at')->nullable();
            $table->timestamp('reboot_complete_at')->nullable()->index();
            $table->string('reboot_restore_state')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('onus', function (Blueprint $table) {
            $table->dropIndex(['reboot_complete_at']);
            $table->dropColumn(['reboot_requested_at', 'reboot_complete_at', 'reboot_restore_state']);
        });
        Schema::table('olts', fn (Blueprint $table) => $table->dropColumn('firmware_version'));
    }
};
