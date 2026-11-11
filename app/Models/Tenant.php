<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Tenant extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['expired_at' => 'datetime', 'balance' => 'decimal:2'];
    }

    public function olts() { return $this->hasMany(Olt::class); }
    public function users() { return $this->hasMany(User::class); }
    public function onus() { return $this->hasMany(Onu::class); }
    public function notificationSetting() { return $this->hasOne(NotificationSetting::class); }
    public function notificationLogs() { return $this->hasMany(NotificationLog::class); }
}
