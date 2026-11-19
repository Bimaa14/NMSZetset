<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('customer_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('color_hex', 7)->default('#38bdf8');
            $table->json('vlan_ids');
            $table->string('description', 255)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
        });

        if (! Schema::hasColumn('onus', 'vlan_ids')) {
            Schema::table('onus', function (Blueprint $table) {
                $table->json('vlan_ids')->nullable()->after('vlan_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('onus', 'vlan_ids')) {
            Schema::table('onus', function (Blueprint $table) {
                $table->dropColumn('vlan_ids');
            });
        }
        Schema::dropIfExists('customer_groups');
    }
};
