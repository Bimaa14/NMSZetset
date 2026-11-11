<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('onus', function (Blueprint $table) {
            $table->string('device_name')->nullable();
            $table->text('device_description')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('onus', fn (Blueprint $table) => $table->dropColumn(['device_name', 'device_description']));
    }
};
