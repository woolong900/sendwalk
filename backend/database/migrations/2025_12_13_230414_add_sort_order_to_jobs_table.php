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
        Schema::table('jobs', function (Blueprint $table) {
            // 添加排序字段
            $table->bigInteger('sort_order')->default(0)->after('available_at');
            
            // 添加索引：queue + reserved_at + sort_order
            $table->index(['queue', 'reserved_at', 'sort_order'], 'jobs_queue_reserved_sort_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            // 删除索引
            $table->dropIndex('jobs_queue_reserved_sort_index');
            
            // 删除字段
            $table->dropColumn('sort_order');
        });
    }
};
