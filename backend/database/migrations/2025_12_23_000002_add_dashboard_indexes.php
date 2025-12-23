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
        Schema::table('send_logs', function (Blueprint $table) {
            // 复合索引：campaign_id + created_at + status
            // 用于: WHERE campaign_id IN (...) AND created_at >= ? AND status = ?
            $table->index(['campaign_id', 'created_at', 'status'], 'idx_sendlogs_campaign_time_status');
        });

        // 2. campaigns 表添加 user_id 索引（如果还没有）
        // 检查索引是否存在
        $campaignIndexes = Schema::getConnection()
            ->getDoctrineSchemaManager()
            ->listTableIndexes('campaigns');
        
        if (!isset($campaignIndexes['campaigns_user_id_index']) && 
            !isset($campaignIndexes['idx_campaigns_user_id'])) {
            Schema::table('campaigns', function (Blueprint $table) {
                $table->index('user_id', 'idx_campaigns_user_id');
            });
        }

        // 3. list_subscriber 表优化（用于订阅者统计）
        Schema::table('list_subscriber', function (Blueprint $table) {
            // 复合索引：list_id + subscriber_id
            // 这个可能已经存在（unique约束），但我们确保有索引
            // 由于有 unique(['list_id', 'subscriber_id'])，这个索引应该已存在
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('send_logs', function (Blueprint $table) {
            $table->dropIndex('idx_sendlogs_campaign_time_status');
        });

        // 只删除我们创建的索引
        $campaignIndexes = Schema::getConnection()
            ->getDoctrineSchemaManager()
            ->listTableIndexes('campaigns');
        
        if (isset($campaignIndexes['idx_campaigns_user_id'])) {
            Schema::table('campaigns', function (Blueprint $table) {
                $table->dropIndex('idx_campaigns_user_id');
            });
        }
    }
};

