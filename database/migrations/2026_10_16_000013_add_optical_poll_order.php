<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up():void{Schema::table('olt_ports',fn(Blueprint $t)=>$t->timestamp('optical_polled_at')->nullable());}
    public function down():void{Schema::table('olt_ports',fn(Blueprint $t)=>$t->dropColumn('optical_polled_at'));}
};
