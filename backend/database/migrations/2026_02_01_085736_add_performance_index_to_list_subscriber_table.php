<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 添加复合索引：用于按 list_id 查询并按 subscriber_id 排序
        // 这个索引可以大大加速分页查询
        if (!$this->indexExists('list_subscriber', 'idx_list_subscriber_pagination')) {
            Schema::table('list_subscriber', function (Blueprint $table) {
                $table->index(['list_id', 'subscriber_id'], 'idx_list_subscriber_pagination');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('list_subscriber', function (Blueprint $table) {
            $table->dropIndex('idx_list_subscriber_pagination');
        });
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $indexes = DB::select("SHOW INDEX FROM {$table} WHERE Key_name = ?", [$indexName]);
        return count($indexes) > 0;
    }
};
