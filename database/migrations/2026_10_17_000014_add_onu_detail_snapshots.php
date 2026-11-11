<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up():void { Schema::create('onu_detail_snapshots',function(Blueprint $t){
        $t->id();$t->foreignId('onu_id')->constrained()->cascadeOnDelete();$t->string('section',20);$t->string('source',12);$t->text('payload');$t->timestamp('observed_at');$t->timestamps();$t->unique(['onu_id','section','source']);
    }); }
    public function down():void { Schema::dropIfExists('onu_detail_snapshots'); }
};
