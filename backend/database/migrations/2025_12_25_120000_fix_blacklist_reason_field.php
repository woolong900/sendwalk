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
        // 将 reason 从 ENUM 改回 TEXT，允许用户自定义原因
        DB::statement("
            ALTER TABLE blacklist 
            MODIFY COLUMN reason TEXT NULL
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // 回滚时转换为 ENUM（但会丢失自定义值）
        // 先将不在列表中的值转为 'manual'
        DB::statement("
            UPDATE blacklist 
            SET reason = 'manual' 
            WHERE reason NOT IN ('manual', 'hard_bounce', 'soft_bounce', 'complaint', 'unsubscribe')
        ");
        
        DB::statement("
            ALTER TABLE blacklist 
            MODIFY COLUMN reason ENUM('manual', 'hard_bounce', 'soft_bounce', 'complaint', 'unsubscribe') 
            DEFAULT 'manual'
        ");
    }
};

