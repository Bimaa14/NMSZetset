<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class ApiToken extends Model {
    protected $guarded=[]; protected $hidden=['token_hash'];
    protected function casts(): array { return ['abilities'=>'array','expires_at'=>'datetime','last_used_at'=>'datetime']; }
}
