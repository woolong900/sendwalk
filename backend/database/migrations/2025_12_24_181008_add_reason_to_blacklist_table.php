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
        Schema::table('blacklist', function (Blueprint $table) {
            // 检查 reason 字段是否已存在
            $hasReasonColumn = Schema::hasColumn('blacklist', 'reason');
            
            if (!$hasReasonColumn) {
                // 如果 reason 字段不存在，创建它
                $table->enum('reason', [
                    'manual',        // 手动添加
                    'hard_bounce',   // 硬退信
                    'soft_bounce',   // 软退信（多次失败）
                    'complaint',     // 投诉
                    'unsubscribe',   // 取消订阅
                ])->default('manual')->after('email');
            }
            
            // 添加 notes 字段（如果不存在）
            if (!Schema::hasColumn('blacklist', 'notes')) {
                $table->text('notes')->nullable()->after('reason');
            }
        });
        
        // 如果 reason 已存在但是 varchar 类型，需要修改为 enum
        // 注意：MySQL 不支持直接修改列类型从 varchar 到 enum
        // 我们需要通过原始 SQL 来完成
        $columnType = DB::select("
            SELECT DATA_TYPE 
            FROM INFORMATION_SCHEMA.COLUMNS 
            WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'blacklist' 
            AND COLUMN_NAME = 'reason'
        ");
        
        if (!empty($columnType) && $columnType[0]->DATA_TYPE === 'varchar') {
            // 先将所有空值或不在枚举中的值设为 'manual'
            DB::statement("
                UPDATE blacklist 
                SET reason = 'manual' 
                WHERE reason IS NULL 
                OR reason NOT IN ('manual', 'hard_bounce', 'soft_bounce', 'complaint', 'unsubscribe')
            ");
            
            // 修改列类型为 enum
            DB::statement("
                ALTER TABLE blacklist 
                MODIFY COLUMN reason ENUM('manual', 'hard_bounce', 'soft_bounce', 'complaint', 'unsubscribe') 
                NOT NULL DEFAULT 'manual'
            ");
        }
        
        // 添加 reason 索引（如果不存在）
        if (!Schema::hasColumn('blacklist', 'reason')) {
            Schema::table('blacklist', function (Blueprint $table) {
                $table->index('reason');
            });
        } else {
            // 检查索引是否存在
            $indexes = DB::select("SHOW INDEX FROM blacklist WHERE Key_name = 'blacklist_reason_index'");
            if (empty($indexes)) {
                Schema::table('blacklist', function (Blueprint $table) {
                    $table->index('reason');
                });
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('blacklist', function (Blueprint $table) {
            // 删除索引
            $indexes = DB::select("SHOW INDEX FROM blacklist WHERE Key_name = 'blacklist_reason_index'");
            if (!empty($indexes)) {
                $table->dropIndex(['reason']);
            }
            
            // 删除 notes 字段
            if (Schema::hasColumn('blacklist', 'notes')) {
                $table->dropColumn('notes');
            }
            
            // 注意：不删除 reason 字段，因为它可能在迁移之前就存在了
        });
    }
};
