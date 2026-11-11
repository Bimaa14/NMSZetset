<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class ProvisionPlan extends Model { protected $guarded=[];protected $hidden=['confirmation_hash','profile_signature'];protected function casts():array{return ['parameters'=>'array','expires_at'=>'datetime'];} }
