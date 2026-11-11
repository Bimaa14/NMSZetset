<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void { Schema::table('onu_optical_logs', fn (Blueprint $table) => $table->string('observation_source')->default('simulator')); }
    public function down(): void { Schema::table('onu_optical_logs', fn (Blueprint $table) => $table->dropColumn('observation_source')); }
};
