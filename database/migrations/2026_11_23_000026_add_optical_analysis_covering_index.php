<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('onu_optical_logs') && ! $this->hasIndex('onu_optical_logs', 'onu_optical_logs_calc_cover_idx')) {
            Schema::table('onu_optical_logs', function (Blueprint $table) {
                // Covering index for OpticalAnalysis 24h baseline aggregation & HistoryRetention pruning
                $table->index(['calculated_at', 'onu_id', 'observation_source', 'rx_power'], 'onu_optical_logs_calc_cover_idx');
            });
        }

        if (Schema::hasTable('onu_status_logs') && ! $this->hasIndex('onu_status_logs', 'onu_status_logs_observed_idx')) {
            Schema::table('onu_status_logs', function (Blueprint $table) {
                $table->index('observed_at', 'onu_status_logs_observed_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('onu_optical_logs') && $this->hasIndex('onu_optical_logs', 'onu_optical_logs_calc_cover_idx')) {
            Schema::table('onu_optical_logs', function (Blueprint $table) {
                $table->dropIndex('onu_optical_logs_calc_cover_idx');
            });
        }

        if (Schema::hasTable('onu_status_logs') && $this->hasIndex('onu_status_logs', 'onu_status_logs_observed_idx')) {
            Schema::table('onu_status_logs', function (Blueprint $table) {
                $table->dropIndex('onu_status_logs_observed_idx');
            });
        }
    }

    private function hasIndex(string $table, string $indexName): bool
    {
        $driver = DB::getDriverName();
        if ($driver === 'sqlite') {
            $rows = DB::select("PRAGMA index_list('{$table}')");
            foreach ($rows as $row) {
                if (($row->name ?? null) === $indexName) {
                    return true;
                }
            }

            return false;
        }

        $rows = DB::select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$indexName]);

        return count($rows) > 0;
    }
};
