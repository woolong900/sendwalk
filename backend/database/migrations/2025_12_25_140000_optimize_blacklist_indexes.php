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
        // 1. 添加复合索引优化分页查询
        // user_id + id 组合索引，支持快速定位和分页
        $this->addIndexIfNotExists('blacklist', 'idx_blacklist_user_id_id', ['user_id', 'id']);
        
        // 2. 添加复合索引优化搜索
        // user_id + email 组合索引（已有 unique 索引，无需重复）
        
        // 3. 添加 created_at 索引优化排序（如果需要按时间排序）
        $this->addIndexIfNotExists('blacklist', 'idx_blacklist_created_at', ['created_at']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('blacklist', function (Blueprint $table) {
            $table->dropIndex('idx_blacklist_user_id_id');
            $table->dropIndex('idx_blacklist_created_at');
        });
    }
    
    /**
     * 检查索引是否存在，不存在则添加
     */
    private function addIndexIfNotExists(string $table, string $indexName, array $columns): void
    {
        try {
            // 检查索引是否存在
            $indexes = DB::select("
                SELECT COUNT(*) as count 
                FROM information_schema.statistics 
                WHERE table_schema = DATABASE() 
                AND table_name = ? 
                AND index_name = ?
            ", [$table, $indexName]);
            
            if ($indexes[0]->count == 0) {
                Schema::table($table, function (Blueprint $table) use ($indexName, $columns) {
                    $table->index($columns, $indexName);
                });
                echo "✓ 索引 {$indexName} 创建成功\n";
            } else {
                echo "✓ 索引 {$indexName} 已存在\n";
            }
        } catch (\Exception $e) {
            // 如果出错（如索引已存在），记录但不中断
            echo "⚠ 索引 {$indexName}: " . $e->getMessage() . "\n";
        }
    }
};

