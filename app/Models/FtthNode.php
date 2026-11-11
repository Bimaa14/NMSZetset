<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class FtthNode extends Model {
    protected $guarded=[];
    protected function casts(): array { return ['latitude'=>'float','longitude'=>'float']; }
    public function assignments() { return $this->hasMany(FtthAssignment::class,'node_id'); }
}
