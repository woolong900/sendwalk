<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('domain_warmups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('smtp_server_id')->constrained('smtp_servers')->onDelete('cascade');
            $table->string('domain');
            $table->boolean('enabled')->default(false);
            // 预热开始时间，用于计算"今天是预热的第几天"
            $table->timestamp('started_at')->nullable();
            // 自定义阶梯（null 时用系统默认）：[{day:1,limit:50},...]
            $table->json('schedule')->nullable();
            $table->timestamps();

            // 同一台服务器下，同一域名只能有一条配置
            $table->unique(['smtp_server_id', 'domain']);
            $table->index(['user_id', 'enabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domain_warmups');
    }
};
