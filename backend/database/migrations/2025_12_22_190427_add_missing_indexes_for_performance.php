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
        // 1. campaigns 表添加索引
        Schema::table('campaigns', function (Blueprint $table) {
            // 单列索引
            $table->index('status', 'idx_campaigns_status');
            $table->index('scheduled_at', 'idx_campaigns_scheduled_at');
            $table->index('sent_at', 'idx_campaigns_sent_at');
            
            // 复合索引（最常用的查询模式：按用户查询，按状态筛选，按时间排序）
            $table->index(['user_id', 'status', 'created_at'], 'idx_campaigns_user_status_time');
        });

        // 2. campaign_sends 表添加索引
        Schema::table('campaign_sends', function (Blueprint $table) {
            $table->index('status', 'idx_campaign_sends_status');
            $table->index('sent_at', 'idx_campaign_sends_sent_at');
            $table->index(['subscriber_id', 'status'], 'idx_campaign_sends_sub_status');
        });

        // 3. list_subscriber 表添加索引
        Schema::table('list_subscriber', function (Blueprint $table) {
            $table->index('status', 'idx_list_subscriber_status');
            $table->index(['list_id', 'status'], 'idx_list_subscriber_list_status');
            $table->index(['subscriber_id', 'status'], 'idx_list_subscriber_sub_status');
        });

        // 4. subscribers 表添加索引
        Schema::table('subscribers', function (Blueprint $table) {
            $table->index('status', 'idx_subscribers_status');
            $table->index('created_at', 'idx_subscribers_created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropIndex('idx_campaigns_status');
            $table->dropIndex('idx_campaigns_scheduled_at');
            $table->dropIndex('idx_campaigns_sent_at');
            $table->dropIndex('idx_campaigns_user_status_time');
        });

        Schema::table('campaign_sends', function (Blueprint $table) {
            $table->dropIndex('idx_campaign_sends_status');
            $table->dropIndex('idx_campaign_sends_sent_at');
            $table->dropIndex('idx_campaign_sends_sub_status');
        });

        Schema::table('list_subscriber', function (Blueprint $table) {
            $table->dropIndex('idx_list_subscriber_status');
            $table->dropIndex('idx_list_subscriber_list_status');
            $table->dropIndex('idx_list_subscriber_sub_status');
        });

        Schema::table('subscribers', function (Blueprint $table) {
            $table->dropIndex('idx_subscribers_status');
            $table->dropIndex('idx_subscribers_created_at');
        });
    }
};
