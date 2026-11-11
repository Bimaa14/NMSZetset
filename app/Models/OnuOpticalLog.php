<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OnuOpticalLog extends Model
{
    public $timestamps = false;
    protected $guarded = [];
    public function onu() { return $this->belongsTo(Onu::class); }
}
