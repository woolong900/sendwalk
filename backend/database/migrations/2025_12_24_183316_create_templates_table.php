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
        Schema::create('templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('name'); // 模板名称
            $table->string('category')->default('general'); // 模板分类：general, marketing, transactional, newsletter
            $table->text('description')->nullable(); // 模板描述
            $table->string('thumbnail')->nullable(); // 缩略图URL（可选）
            $table->text('html_content'); // HTML 内容
            $table->text('plain_content')->nullable(); // 纯文本内容（可选）
            $table->boolean('is_default')->default(false); // 是否为系统默认模板
            $table->boolean('is_active')->default(true); // 是否启用
            $table->integer('usage_count')->default(0); // 使用次数
            $table->timestamp('last_used_at')->nullable(); // 最后使用时间
            $table->timestamps();
            
            // 索引
            $table->index(['user_id', 'category']);
            $table->index(['user_id', 'is_active']);
            $table->index('category');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('templates');
    }
};
