<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('customer_groups') && ! Schema::hasColumn('customer_groups', 'name_keywords')) {
            Schema::table('customer_groups', function (Blueprint $table) {
                $table->json('name_keywords')->nullable()->after('vlan_ids');
            });
        }

        if (! Schema::hasTable('power_monitor_groups')) {
            Schema::create('power_monitor_groups', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->string('name', 100);
                $table->string('color_hex', 7)->default('#f59e0b');
                $table->string('description', 255)->nullable();
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->timestamps();

                $table->unique(['tenant_id', 'name']);
            });
        }

        if (Schema::hasTable('power_monitors') && ! Schema::hasColumn('power_monitors', 'power_monitor_group_id')) {
            Schema::table('power_monitors', function (Blueprint $table) {
                $table->foreignId('power_monitor_group_id')
                    ->nullable()
                    ->after('tenant_id')
                    ->constrained('power_monitor_groups')
                    ->nullOnDelete();
            });
        }

        // Backfill default "Listrik" category for every existing tenant & link existing power monitors
        if (Schema::hasTable('tenants') && Schema::hasTable('power_monitor_groups')) {
            $now = now();
            $tenantIds = DB::table('tenants')->pluck('id');
            foreach ($tenantIds as $tenantId) {
                $groupId = DB::table('power_monitor_groups')
                    ->where('tenant_id', $tenantId)
                    ->where('name', 'Listrik')
                    ->value('id');

                if (! $groupId) {
                    $groupId = DB::table('power_monitor_groups')->insertGetId([
                        'tenant_id' => $tenantId,
                        'name' => 'Listrik',
                        'color_hex' => '#f59e0b',
                        'description' => 'Pemantauan listrik PLN via Ping IP & live status ONU',
                        'sort_order' => 1,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }

                if (Schema::hasTable('power_monitors') && Schema::hasColumn('power_monitors', 'power_monitor_group_id')) {
                    DB::table('power_monitors')
                        ->where('tenant_id', $tenantId)
                        ->whereNull('power_monitor_group_id')
                        ->update(['power_monitor_group_id' => $groupId]);
                }
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('power_monitors') && Schema::hasColumn('power_monitors', 'power_monitor_group_id')) {
            Schema::table('power_monitors', function (Blueprint $table) {
                $table->dropConstrainedForeignId('power_monitor_group_id');
            });
        }

        Schema::dropIfExists('power_monitor_groups');

        if (Schema::hasTable('customer_groups') && Schema::hasColumn('customer_groups', 'name_keywords')) {
            Schema::table('customer_groups', function (Blueprint $table) {
                $table->dropColumn('name_keywords');
            });
        }
    }
};
