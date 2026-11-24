<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('power_monitor_groups')) {
            Schema::table('power_monitor_groups', function (Blueprint $table) {
                if (! Schema::hasColumn('power_monitor_groups', 'down_message_template')) {
                    $table->text('down_message_template')->nullable()->after('telegram_thread_id');
                }
                if (! Schema::hasColumn('power_monitor_groups', 'up_message_template')) {
                    $table->text('up_message_template')->nullable()->after('down_message_template');
                }
            });
        }

        if (Schema::hasTable('notification_settings')) {
            Schema::table('notification_settings', function (Blueprint $table) {
                if (! Schema::hasColumn('notification_settings', 'power_down_template')) {
                    $table->text('power_down_template')->nullable()->after('power_cooldown_minutes');
                }
                if (! Schema::hasColumn('notification_settings', 'power_up_template')) {
                    $table->text('power_up_template')->nullable()->after('power_down_template');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('power_monitor_groups')) {
            Schema::table('power_monitor_groups', function (Blueprint $table) {
                $cols = array_filter(
                    ['down_message_template', 'up_message_template'],
                    fn ($col) => Schema::hasColumn('power_monitor_groups', $col)
                );
                if ($cols) {
                    $table->dropColumn($cols);
                }
            });
        }

        if (Schema::hasTable('notification_settings')) {
            Schema::table('notification_settings', function (Blueprint $table) {
                $cols = array_filter(
                    ['power_down_template', 'power_up_template'],
                    fn ($col) => Schema::hasColumn('notification_settings', $col)
                );
                if ($cols) {
                    $table->dropColumn($cols);
                }
            });
        }
    }
};
