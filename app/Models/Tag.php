<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Tag extends Model
{
    protected $guarded = [];
    public function onus(){return $this->belongsToMany(Onu::class,'onu_tag');}
}
