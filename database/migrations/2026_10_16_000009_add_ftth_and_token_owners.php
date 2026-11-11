<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('api_tokens',fn(Blueprint $t)=>$t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete());
        Schema::create('ftth_nodes',function(Blueprint $t) {
            $t->id(); $t->foreignId('tenant_id')->constrained()->cascadeOnDelete(); $t->foreignId('olt_id')->constrained()->cascadeOnDelete();
            $t->foreignId('parent_id')->nullable()->constrained('ftth_nodes')->nullOnDelete();
            $t->string('type',10); $t->string('name',120); $t->text('description')->nullable();
            $t->decimal('latitude',10,7); $t->decimal('longitude',10,7); $t->unsignedSmallInteger('capacity')->default(8); $t->timestamps();
            $t->unique(['tenant_id','olt_id','name']);
        });
        Schema::create('ftth_assignments',function(Blueprint $t) {
            $t->id(); $t->foreignId('node_id')->constrained('ftth_nodes')->cascadeOnDelete(); $t->foreignId('onu_id')->unique()->constrained()->cascadeOnDelete();
            $t->unsignedSmallInteger('port_number'); $t->timestamps(); $t->unique(['node_id','port_number']);
        });
    }
    public function down(): void {
        Schema::dropIfExists('ftth_assignments'); Schema::dropIfExists('ftth_nodes');
        Schema::table('api_tokens',fn(Blueprint $t)=>$t->dropConstrainedForeignId('created_by'));
    }
};
