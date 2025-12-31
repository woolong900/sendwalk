<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * 为 jobs 表添加 reserved_at 索引，优化仪表盘队列长度查询性能
     * 原查询：SELECT COUNT(*) FROM jobs WHERE reserved_at IS NULL
     */
    public function up(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            $table->index('reserved_at', 'jobs_reserved_at_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            $table->dropIndex('jobs_reserved_at_index');
        });
    }
};

