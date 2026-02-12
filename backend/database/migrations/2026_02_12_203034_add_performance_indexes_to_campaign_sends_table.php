<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * 为自动列表的 has_opened/has_delivered 条件优化查询性能
     */
    public function up(): void
    {
        Schema::table('campaign_sends', function (Blueprint $table) {
            // 优化 has_opened 条件查询：WHERE subscriber_id = ? AND opened_at IS NOT NULL
            $table->index(['subscriber_id', 'opened_at'], 'idx_campaign_sends_subscriber_opened');
            
            // 优化 has_delivered 条件查询：WHERE subscriber_id = ? AND sent_at IS NOT NULL
            $table->index(['subscriber_id', 'sent_at'], 'idx_campaign_sends_subscriber_sent');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('campaign_sends', function (Blueprint $table) {
            $table->dropIndex('idx_campaign_sends_subscriber_opened');
            $table->dropIndex('idx_campaign_sends_subscriber_sent');
        });
    }
};
