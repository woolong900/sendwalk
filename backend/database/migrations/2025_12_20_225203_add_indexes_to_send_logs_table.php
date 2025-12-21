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
        Schema::table('send_logs', function (Blueprint $table) {
            // 复合索引用于速率限制查询（最频繁的查询）
            // 查询模式: WHERE smtp_server_id = ? AND created_at >= ? AND status IN ('sent', 'failed')
            $table->index(['smtp_server_id', 'created_at', 'status'], 'idx_server_time_status');
            
            // 复合索引用于监控页面的筛选查询
            // 查询模式: WHERE smtp_server_id = ? AND status = ? AND created_at >= ?
            // 注意：上面的索引也可以覆盖这个查询模式
            
            // 复合索引用于按订阅者查询发送历史
            // 查询模式: WHERE subscriber_id = ? ORDER BY created_at DESC
            $table->index(['subscriber_id', 'created_at'], 'idx_subscriber_time');
            
            // 改进 email 查询性能（用于按邮箱查询发送历史）
            $table->index('email');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('send_logs', function (Blueprint $table) {
            $table->dropIndex('idx_server_time_status');
            $table->dropIndex('idx_subscriber_time');
            $table->dropIndex(['email']);
        });
    }
};
