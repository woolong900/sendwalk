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
        // 1. 先检查并删除 reason 字段上的索引
        $indexes = DB::select("SHOW INDEX FROM blacklist WHERE Column_name = 'reason'");
        
        if (!empty($indexes)) {
            foreach ($indexes as $index) {
                $indexName = $index->Key_name;
                if ($indexName !== 'PRIMARY') {
                    try {
                        DB::statement("ALTER TABLE blacklist DROP INDEX `{$indexName}`");
                    } catch (\Exception $e) {
                        // 索引可能已经不存在，忽略错误
                    }
                }
            }
        }
        
        // 2. 将 reason 从 ENUM 改为 TEXT，允许用户自定义原因
        DB::statement("
            ALTER TABLE blacklist 
            MODIFY COLUMN reason TEXT NULL
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // 回滚时转换为 ENUM（但会丢失自定义值）
        // 先将不在列表中的值转为 'manual'
        DB::statement("
            UPDATE blacklist 
            SET reason = 'manual' 
            WHERE reason IS NULL 
            OR reason NOT IN ('manual', 'hard_bounce', 'soft_bounce', 'complaint', 'unsubscribe')
        ");
        
        DB::statement("
            ALTER TABLE blacklist 
            MODIFY COLUMN reason ENUM('manual', 'hard_bounce', 'soft_bounce', 'complaint', 'unsubscribe') 
            NOT NULL DEFAULT 'manual'
        ");
        
        // 重新创建索引
        try {
            DB::statement("ALTER TABLE blacklist ADD INDEX `blacklist_reason_index` (`reason`)");
        } catch (\Exception $e) {
            // 索引可能已存在，忽略
        }
    }
};

