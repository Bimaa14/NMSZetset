<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('power_monitor_groups')) {
            Schema::table('power_monitor_groups', function (Blueprint $table) {
                if (! Schema::hasColumn('power_monitor_groups', 'whatsapp_recipients')) {
                    $table->string('whatsapp_recipients', 500)->nullable()->after('description');
                }
                if (! Schema::hasColumn('power_monitor_groups', 'telegram_chat_id')) {
                    $table->string('telegram_chat_id', 255)->nullable()->after('whatsapp_recipients');
                }
                if (! Schema::hasColumn('power_monitor_groups', 'telegram_thread_id')) {
                    $table->string('telegram_thread_id', 64)->nullable()->after('telegram_chat_id');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('power_monitor_groups')) {
            Schema::table('power_monitor_groups', function (Blueprint $table) {
                $cols = array_filter(
                    ['whatsapp_recipients', 'telegram_chat_id', 'telegram_thread_id'],
                    fn ($col) => Schema::hasColumn('power_monitor_groups', $col)
                );
                if ($cols) {
                    $table->dropColumn($cols);
                }
            });
        }
    }
};
