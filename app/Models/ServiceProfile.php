<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class ServiceProfile extends Model { protected $guarded=[];protected function casts():array{return ['commands'=>'array','verification'=>'array'];} }
