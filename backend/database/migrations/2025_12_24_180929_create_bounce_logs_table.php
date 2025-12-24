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
        Schema::create('bounce_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscriber_id')->nullable()->constrained()->onDelete('set null');
            $table->foreignId('campaign_id')->nullable()->constrained()->onDelete('set null');
            $table->string('email')->index(); // 保存邮箱以便订阅者被删除后仍可查询
            $table->enum('bounce_type', ['hard', 'soft'])->index(); // 硬退信或软退信
            $table->string('error_code')->nullable(); // SMTP 错误码，如 550, 554
            $table->text('error_message')->nullable(); // 完整的错误消息
            $table->text('smtp_response')->nullable(); // 完整的 SMTP 响应
            $table->timestamps();
            
            // 索引优化
            $table->index(['subscriber_id', 'bounce_type', 'created_at']);
            $table->index(['email', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bounce_logs');
    }
};
