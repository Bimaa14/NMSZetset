<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationLog extends Model
{
    public $timestamps = false;
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
        ];
    }

    public function olt()
    {
        return $this->belongsTo(Olt::class);
    }

    public function onu()
    {
        return $this->belongsTo(Onu::class);
    }
}
