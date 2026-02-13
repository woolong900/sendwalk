<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 优化数据分析查询性能
     */
    public function up(): void
    {
        // 为 orders 表添加索引
        Schema::table('orders', function (Blueprint $table) {
            // paid_at 索引 - 优化时间范围查询
            if (!$this->indexExists('orders', 'idx_orders_paid_at')) {
                $table->index('paid_at', 'idx_orders_paid_at');
            }
            
            // utm_medium 索引 - 优化按发件域名分组查询
            if (!$this->indexExists('orders', 'idx_orders_utm_medium')) {
                $table->index('utm_medium', 'idx_orders_utm_medium');
            }
            
            // domain 索引 - 优化按落地页域名分组查询
            if (!$this->indexExists('orders', 'idx_orders_domain')) {
                $table->index('domain', 'idx_orders_domain');
            }
            
            // 复合索引：paid_at + utm_medium - 优化按时间范围和发件域名查询
            if (!$this->indexExists('orders', 'idx_orders_paid_utm')) {
                $table->index(['paid_at', 'utm_medium'], 'idx_orders_paid_utm');
            }
            
            // 复合索引：paid_at + domain - 优化按时间范围和落地页域名查询
            if (!$this->indexExists('orders', 'idx_orders_paid_domain')) {
                $table->index(['paid_at', 'domain'], 'idx_orders_paid_domain');
            }
        });
        
        // 为 send_logs 表添加 from_email 索引
        Schema::table('send_logs', function (Blueprint $table) {
            // from_email 索引 - 优化按发件人分组查询
            if (!$this->indexExists('send_logs', 'idx_send_logs_from_email')) {
                $table->index('from_email', 'idx_send_logs_from_email');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('idx_orders_paid_at');
            $table->dropIndex('idx_orders_utm_medium');
            $table->dropIndex('idx_orders_domain');
            $table->dropIndex('idx_orders_paid_utm');
            $table->dropIndex('idx_orders_paid_domain');
        });
        
        Schema::table('send_logs', function (Blueprint $table) {
            $table->dropIndex('idx_send_logs_from_email');
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
