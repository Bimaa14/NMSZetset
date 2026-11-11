<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationSetting extends Model
{
    protected $guarded = [];
    protected $hidden = ['telegram_bot_token_enc', 'whatsapp_api_key_enc'];

    protected function casts(): array
    {
        return [
            'telegram_enabled' => 'boolean',
            'telegram_bot_token_enc' => 'encrypted',
            'whatsapp_enabled' => 'boolean',
            'whatsapp_api_key_enc' => 'encrypted',
            'notify_on_los' => 'boolean',
            'notify_on_dying_gasp' => 'boolean',
            'notify_on_recovery' => 'boolean',
            'notify_on_bad_rx' => 'boolean',
            'bad_rx_threshold' => 'float',
            'cooldown_minutes' => 'integer',
        ];
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public static function forTenant(int $tenantId): self
    {
        return static::firstOrCreate(
            ['tenant_id' => $tenantId],
            [
                'telegram_enabled' => false,
                'whatsapp_enabled' => false,
                'whatsapp_provider' => 'baileys',
                'whatsapp_endpoint_url' => 'http://127.0.0.1:3001/send-message',
                'whatsapp_session_id' => 'default',
                'notify_on_los' => true,
                'notify_on_dying_gasp' => true,
                'notify_on_recovery' => true,
                'notify_on_bad_rx' => true,
                'bad_rx_threshold' => (float) config('nms.bad_rx_threshold', -27.00),
                'cooldown_minutes' => 15,
            ]
        );
    }
}
