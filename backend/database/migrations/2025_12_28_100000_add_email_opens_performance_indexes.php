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
        Schema::table('email_opens', function (Blueprint $table) {
            // 复合索引：campaign_id + email
            // 用于 CampaignAnalyticsController::getEmailOpens
            // 查询模式: WHERE campaign_id = ? AND email = ?
            if (!$this->indexExists('email_opens', 'idx_opens_campaign_email')) {
                $table->index(['campaign_id', 'email'], 'idx_opens_campaign_email');
            }
            
            // 复合索引：campaign_id + opened_at
            // 用于统计和时间排序查询
            // 查询模式: WHERE campaign_id = ? ORDER BY opened_at
            if (!$this->indexExists('email_opens', 'idx_opens_campaign_time')) {
                $table->index(['campaign_id', 'opened_at'], 'idx_opens_campaign_time');
            }
            
            // 复合索引：campaign_id + subscriber_id
            // 用于快速查找特定订阅者的打开记录
            // 查询模式: WHERE campaign_id = ? AND subscriber_id = ?
            if (!$this->indexExists('email_opens', 'idx_opens_campaign_subscriber')) {
                $table->index(['campaign_id', 'subscriber_id'], 'idx_opens_campaign_subscriber');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('email_opens', function (Blueprint $table) {
            $table->dropIndex('idx_opens_campaign_email');
            $table->dropIndex('idx_opens_campaign_time');
            $table->dropIndex('idx_opens_campaign_subscriber');
        });
    }
    
    /**
     * 检查索引是否存在
     */
    private function indexExists(string $table, string $indexName): bool
    {
        $result = DB::select("
            SELECT COUNT(*) as count 
            FROM information_schema.statistics 
            WHERE table_schema = DATABASE() 
            AND table_name = ? 
            AND index_name = ?
        ", [$table, $indexName]);
        
        return $result[0]->count > 0;
    }
};

