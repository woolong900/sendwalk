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
        // 为 send_logs 表添加复合索引以优化仪表盘查询
        // 这些索引针对 DashboardController 中的查询进行优化
        
        Schema::table('send_logs', function (Blueprint $table) {
            // 复合索引：campaign_id + status + created_at
            // 优化：WHERE campaign_id IN (...) AND status = 'sent' AND created_at >= ...
            if (!$this->indexExists('send_logs', 'idx_campaign_status_created')) {
                $table->index(['campaign_id', 'status', 'created_at'], 'idx_campaign_status_created');
            }
            
            // 复合索引：status + created_at
            // 优化：WHERE status = 'sent' AND created_at >= ...
            if (!$this->indexExists('send_logs', 'idx_status_created')) {
                $table->index(['status', 'created_at'], 'idx_status_created');
            }
        });
        
        // 为 campaigns 表添加复合索引
        Schema::table('campaigns', function (Blueprint $table) {
            // 复合索引：user_id + status
            // 优化：WHERE user_id = ? AND status = 'sending'
            if (!$this->indexExists('campaigns', 'idx_user_status')) {
                $table->index(['user_id', 'status'], 'idx_user_status');
            }
        });
        
        // 为 list_subscriber 表添加索引（如果还没有）
        Schema::table('list_subscriber', function (Blueprint $table) {
            // 复合索引：list_id + subscriber_id
            // 优化 JOIN 查询
            if (!$this->indexExists('list_subscriber', 'idx_list_subscriber')) {
                $table->index(['list_id', 'subscriber_id'], 'idx_list_subscriber');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('send_logs', function (Blueprint $table) {
            $table->dropIndex('idx_campaign_status_created');
            $table->dropIndex('idx_status_created');
        });
        
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropIndex('idx_user_status');
        });
        
        Schema::table('list_subscriber', function (Blueprint $table) {
            $table->dropIndex('idx_list_subscriber');
        });
    }
    
    /**
     * 检查索引是否存在
     */
    private function indexExists($table, $indexName): bool
    {
        $indexes = DB::select("SHOW INDEX FROM {$table} WHERE Key_name = ?", [$indexName]);
        return count($indexes) > 0;
    }
};
