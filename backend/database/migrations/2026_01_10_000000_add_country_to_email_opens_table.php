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
        Schema::table('email_opens', function (Blueprint $table) {
            // 添加国家代码字段（如 CN, US, JP 等）
            $table->string('country_code', 2)->nullable()->after('ip_address');
            // 添加国家名称字段
            $table->string('country_name', 100)->nullable()->after('country_code');
            
            // 添加索引用于按国家统计
            $table->index('country_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('email_opens', function (Blueprint $table) {
            $table->dropIndex(['country_code']);
            $table->dropColumn(['country_code', 'country_name']);
        });
    }
};

