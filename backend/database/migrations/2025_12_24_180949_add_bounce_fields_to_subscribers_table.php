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
        Schema::table('subscribers', function (Blueprint $table) {
            // 软退信计数
            $table->integer('bounce_count')->default(0)->after('status');
            
            // 最后一次退信时间
            $table->timestamp('last_bounce_at')->nullable()->after('bounce_count');
            
            // 索引优化：快速查询需要处理的退信订阅者
            $table->index(['bounce_count', 'last_bounce_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subscribers', function (Blueprint $table) {
            $table->dropIndex(['bounce_count', 'last_bounce_at']);
            $table->dropColumn(['bounce_count', 'last_bounce_at']);
        });
    }
};
