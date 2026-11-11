<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class OnuDetailSnapshot extends Model {
    protected $guarded=[];
    protected $hidden=['payload'];
    protected function casts():array{return ['payload'=>'encrypted:array','observed_at'=>'datetime'];}
}
