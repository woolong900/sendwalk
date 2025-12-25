<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 删除未使用的 segments 表
        Schema::dropIfExists('segments');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // 如果需要回滚，重新创建空表
        Schema::create('segments', function ($table) {
            $table->id();
            $table->timestamps();
        });
    }
};

