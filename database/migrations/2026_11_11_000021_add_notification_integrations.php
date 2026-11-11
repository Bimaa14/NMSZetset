<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('notification_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->unique()->constrained()->cascadeOnDelete();

            // Telegram Bot Integration
            $table->boolean('telegram_enabled')->default(false);
            $table->text('telegram_bot_token_enc')->nullable();
            $table->string('telegram_chat_id', 255)->nullable();
            $table->string('telegram_thread_id', 64)->nullable();

            // WhatsApp (Node.js Baileys / Gateway) Integration
            $table->boolean('whatsapp_enabled')->default(false);
            $table->string('whatsapp_provider', 32)->default('baileys');
            $table->string('whatsapp_endpoint_url', 500)->nullable();
            $table->text('whatsapp_api_key_enc')->nullable();
            $table->string('whatsapp_session_id', 100)->default('default');
            $table->text('whatsapp_recipients')->nullable();

            // Event Trigger Rules
            $table->boolean('notify_on_los')->default(true);
            $table->boolean('notify_on_dying_gasp')->default(true);
            $table->boolean('notify_on_recovery')->default(true);
            $table->boolean('notify_on_bad_rx')->default(true);
            $table->decimal('bad_rx_threshold', 6, 2)->nullable();
            $table->unsignedSmallInteger('cooldown_minutes')->default(15);

            $table->timestamps();
        });

        Schema::create('notification_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('olt_id')->nullable();
            $table->unsignedBigInteger('onu_id')->nullable();
            $table->string('channel', 20);
            $table->string('event_type', 40);
            $table->string('recipient', 191);
            $table->string('status', 20);
            $table->text('message');
            $table->text('error')->nullable();
            $table->timestamp('sent_at');

            $table->index(['tenant_id', 'sent_at']);
            $table->index(['tenant_id', 'onu_id', 'event_type', 'sent_at'], 'notif_logs_cooldown_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_logs');
        Schema::dropIfExists('notification_settings');
    }
};
