<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class OltBackup extends Model {public $timestamps=false;protected $guarded=[];protected $hidden=['configuration'];protected function casts():array{return ['configuration'=>'encrypted','created_at'=>'datetime'];}}
