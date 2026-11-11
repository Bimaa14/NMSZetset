<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('onus', fn (Blueprint $table) => $table->timestamp('last_online_at')->nullable());
    }

    public function down(): void
    {
        Schema::table('onus', fn (Blueprint $table) => $table->dropColumn('last_online_at'));
    }
};
