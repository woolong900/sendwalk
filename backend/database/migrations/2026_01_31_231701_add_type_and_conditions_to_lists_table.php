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
        Schema::table('lists', function (Blueprint $table) {
            // 列表类型：manual（手动）或 auto（自动）
            $table->enum('type', ['manual', 'auto'])->default('manual')->after('description');
            
            // 自动列表的条件配置（JSON格式）
            // 格式：{"logic": "and|or", "rules": [{"type": "in_list|not_in_list|has_opened|has_delivered", ...}]}
            $table->json('conditions')->nullable()->after('type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('lists', function (Blueprint $table) {
            $table->dropColumn(['type', 'conditions']);
        });
    }
};
