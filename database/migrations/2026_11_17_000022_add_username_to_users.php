<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 64)->nullable()->after('name');
            $table->string('email')->nullable()->change();
        });

        $used = [];
        foreach (DB::table('users')->orderBy('id')->get(['id', 'username', 'email', 'name']) as $row) {
            $raw = $row->username ?: (strstr((string) $row->email, '@', true) ?: (string) $row->email ?: (string) $row->name);
            $base = strtolower(preg_replace('/[^A-Za-z0-9._-]+/', '', $raw) ?: 'user'.$row->id);
            if (strlen($base) < 2) {
                $base = 'user'.$row->id;
            }
            $base = substr($base, 0, 50);
            $candidate = $base;
            $suffix = 2;
            while (isset($used[$candidate])) {
                $candidate = $base.$suffix++;
            }
            $used[$candidate] = true;
            DB::table('users')->where('id', $row->id)->update(['username' => $candidate]);
        }

        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 64)->nullable(false)->unique()->change();
        });
    }

    public function down(): void
    {
        foreach (DB::table('users')->whereNull('email')->get(['id', 'username']) as $row) {
            DB::table('users')->where('id', $row->id)->update(['email' => ($row->username ?: 'user'.$row->id).'@local.invalid']);
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['username']);
            $table->dropColumn('username');
            $table->string('email')->nullable(false)->change();
        });
    }
};
