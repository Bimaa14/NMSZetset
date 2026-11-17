<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('power_monitors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('group_name', 120)->default('MONITORING-LISTRIK');
            $table->string('location', 160)->nullable();
            $table->string('monitor_type', 20)->default('ping'); // ping, onu, both
            $table->string('ip_address', 45)->nullable();
            $table->foreignId('onu_id')->nullable()->constrained('onus')->nullOnDelete();
            $table->string('status', 20)->default('unknown'); // up, down, unknown
            $table->decimal('last_latency_ms', 8, 2)->nullable();
            $table->unsignedTinyInteger('packet_loss')->nullable();
            $table->unsignedSmallInteger('consecutive_failures')->default(0);
            $table->boolean('is_enabled')->default(true);
            $table->string('whatsapp_recipients_override', 500)->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('last_up_at')->nullable();
            $table->timestamp('last_down_at')->nullable();
            $table->timestamp('last_state_changed_at')->nullable();
            $table->string('last_error', 255)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'is_enabled', 'last_checked_at'], 'power_monitors_poll_idx');
            $table->index(['tenant_id', 'onu_id'], 'power_monitors_onu_idx');
        });

        Schema::table('notification_settings', function (Blueprint $table) {
            $table->boolean('power_notify_enabled')->default(true)->after('cooldown_minutes');
            $table->text('power_whatsapp_recipients')->nullable()->after('power_notify_enabled');
            $table->string('power_telegram_chat_id', 255)->nullable()->after('power_whatsapp_recipients');
            $table->string('power_telegram_thread_id', 64)->nullable()->after('power_telegram_chat_id');
            $table->unsignedSmallInteger('power_cooldown_minutes')->default(5)->after('power_telegram_thread_id');
        });

        Schema::table('notification_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('power_monitor_id')->nullable()->after('onu_id');
            $table->index(['tenant_id', 'power_monitor_id', 'event_type', 'sent_at'], 'notif_logs_power_idx');
        });
    }

    public function down(): void
    {
        Schema::table('notification_logs', function (Blueprint $table) {
            $table->dropIndex('notif_logs_power_idx');
            $table->dropColumn('power_monitor_id');
        });

        Schema::table('notification_settings', function (Blueprint $table) {
            $table->dropColumn([
                'power_notify_enabled',
                'power_whatsapp_recipients',
                'power_telegram_chat_id',
                'power_telegram_thread_id',
                'power_cooldown_minutes',
            ]);
        });

        Schema::dropIfExists('power_monitors');
    }
};
