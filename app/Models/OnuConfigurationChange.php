<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class OnuConfigurationChange extends Model {
    protected $guarded=[];
    protected $hidden=['payload','confirmation_hash'];
    protected function casts():array{return ['payload'=>'encrypted:array','expires_at'=>'datetime'];}
}
