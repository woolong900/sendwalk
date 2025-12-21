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
        // 1. 先删除重复的记录（保留最早的那条）
        DB::statement("
            DELETE t1 FROM campaign_sends t1
            INNER JOIN campaign_sends t2 
            WHERE t1.id > t2.id 
            AND t1.campaign_id = t2.campaign_id 
            AND t1.subscriber_id = t2.subscriber_id
        ");

        // 2. 添加唯一索引（MySQL 会自动处理与现有索引的关系）
        Schema::table('campaign_sends', function (Blueprint $table) {
            $table->unique(['campaign_id', 'subscriber_id'], 'campaign_sends_campaign_subscriber_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // 删除唯一索引
        Schema::table('campaign_sends', function (Blueprint $table) {
            $table->dropUnique('campaign_sends_campaign_subscriber_unique');
        });
    }
};
