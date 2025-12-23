<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. send_logs 表添加复合索引（用于仪表盘发送统计）
        // 使用 try-catch 避免重复创建索引的错误
        try {
            Schema::table('send_logs', function (Blueprint $table) {
                // 复合索引：campaign_id + created_at + status
                // 用于: WHERE campaign_id IN (...) AND created_at >= ? AND status = ?
                $table->index(['campaign_id', 'created_at', 'status'], 'idx_sendlogs_campaign_time_status');
            });
        } catch (\Exception $e) {
            // 索引可能已存在，跳过
            if (!str_contains($e->getMessage(), 'Duplicate key name')) {
                throw $e;
            }
        }

        // 2. campaigns 表添加 user_id 索引（如果还没有）
        // 使用原始 SQL 检查索引是否存在
        $indexExists = \DB::select("
            SELECT COUNT(*) as count 
            FROM information_schema.statistics 
            WHERE table_schema = DATABASE() 
            AND table_name = 'campaigns' 
            AND index_name = 'idx_campaigns_user_id'
        ");
        
        if ($indexExists[0]->count == 0) {
            // 检查是否有外键自动创建的索引
            $fkIndexExists = \DB::select("
                SELECT COUNT(*) as count 
                FROM information_schema.statistics 
                WHERE table_schema = DATABASE() 
                AND table_name = 'campaigns' 
                AND column_name = 'user_id'
            ");
            
            if ($fkIndexExists[0]->count == 0) {
                Schema::table('campaigns', function (Blueprint $table) {
                    $table->index('user_id', 'idx_campaigns_user_id');
                });
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // 删除 send_logs 索引
        try {
            Schema::table('send_logs', function (Blueprint $table) {
                $table->dropIndex('idx_sendlogs_campaign_time_status');
            });
        } catch (\Exception $e) {
            // 索引可能不存在，跳过
        }

        // 删除 campaigns 索引（如果存在）
        $indexExists = \DB::select("
            SELECT COUNT(*) as count 
            FROM information_schema.statistics 
            WHERE table_schema = DATABASE() 
            AND table_name = 'campaigns' 
            AND index_name = 'idx_campaigns_user_id'
        ");
        
        if ($indexExists[0]->count > 0) {
            Schema::table('campaigns', function (Blueprint $table) {
                $table->dropIndex('idx_campaigns_user_id');
            });
        }
    }
};

